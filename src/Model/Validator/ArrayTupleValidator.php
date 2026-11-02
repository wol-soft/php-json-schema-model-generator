<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Arrays\InvalidTupleException;
use PHPModelGenerator\Exception\Generic\DeniedPropertyException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Validates the positions of a tuple-form `items` list, each by the property of the same index.
 *
 * Extracted into a method of its own, like ArrayItemValidator: a tuple property may reference the array which owns the
 * tuple (`items: [{"type": "string"}, {"$ref": "#/definitions/node"}]` inside the definition `node`). Rendered inline,
 * the validators of such a tuple property would contain the very validator being rendered and the rendering would never
 * terminate. As an extracted method the self reference is a method call.
 *
 * Extracting only the tuples which are actually recursive was rejected: the cycle may run over any number of
 * definitions and compositions and is only known once every reference is resolved, whereas the validator has to be
 * either an extracted one or an inline one when it is created.
 */
class ArrayTupleValidator extends ExtractedMethodValidator
{
    /** @var PropertyInterface[] */
    private array $tupleProperties;

    /**
     * @throws SchemaException
     */
    public function __construct(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        JsonSchema $propertiesStructure,
        string $propertyName,
    ) {
        $propertyFactory = new PropertyFactory();

        $this->tupleProperties = [];

        foreach ($propertiesStructure->getJson() as $tupleIndex => $tupleItem) {
            $tupleItemName = "tuple item #$tupleIndex of array $propertyName";

            // A boolean schema can't be navigated into: the position is validated by an empty schema (`true`
            // accepts every value) which is extended by a validator rejecting every value for `false`
            $tupleItemSchema = is_bool($tupleItem)
                ? $propertiesStructure->withPointer($propertiesStructure->getPointer() . "/$tupleIndex")->withJson([])
                : $propertiesStructure->navigate($tupleIndex);

            // an item of the array behaves like a nested property to add item-level validation
            $tupleProperty = $propertyFactory->create(
                $schemaProcessor,
                $schema,
                $tupleItemName,
                $tupleItemSchema,
                true,
            );

            if ($tupleItem === false) {
                $tupleProperty->addValidator(
                    (new PropertyValidator($tupleProperty, 'true', DeniedPropertyException::class))
                        ->withJsonPointer($tupleItemSchema->getPointer()),
                );
            }

            $this->tupleProperties[] = $tupleProperty;
        }

        $this->resolve();

        parent::__construct(
            $schemaProcessor->getGeneratorConfiguration(),
            new Property($propertyName, null, $propertiesStructure),
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ArrayTuple.phptpl',
            [
                'schema' => $schema,
                'tupleProperties' => &$this->tupleProperties,
                'viewHelper' => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
                'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
            ],
            InvalidTupleException::class,
            ['&$invalidTuples'],
            $schema,
        );
    }

    /**
     * @inheritDoc
     */
    public function getCheck(): string
    {
        foreach ($this->tupleProperties as $tupleProperty) {
            $this->removeRequiredPropertyValidator($tupleProperty);
        }

        return parent::getCheck();
    }

    /**
     * The properties used to validate each tuple index, in index order. Exposed so post
     * processors (e.g. TransformingFilterOutputTypePostProcessor) can recurse into them —
     * mirrors ArrayItemValidator::getNestedProperty() for the tuple-form equivalent.
     *
     * @return PropertyInterface[]
     */
    public function getTupleProperties(): array
    {
        return $this->tupleProperties;
    }

    /**
     * The list the template fills with the violations of each tuple index and the exception reports.
     */
    public function getValidatorSetUp(): string
    {
        return '$invalidTuples = [];';
    }
}
