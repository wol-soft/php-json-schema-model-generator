<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Arrays\InvalidUnevaluatedItemsException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Validator emitted for schemas declaring `unevaluatedItems: <schema>`.
 *
 * Mirrors UnevaluatedPropertiesValidator on the array side: each index of the array not
 * claimed by a sibling positive applicator must validate against the unevaluatedItems
 * subschema.
 */
class UnevaluatedItemsValidator extends AbstractUnevaluatedItemsValidator
{
    private const NESTED_PROPERTY_NAME = 'unevaluated item';

    private readonly PropertyInterface $validationProperty;

    /**
     * @throws SchemaException
     */
    public function __construct(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertiesStructure,
    ) {
        $this->validationProperty = (new PropertyFactory())->create(
            $schemaProcessor,
            $schema,
            self::NESTED_PROPERTY_NAME,
            $propertiesStructure->navigate('unevaluatedItems'),
            true,
        );

        $this->validationProperty->onResolve(function (): void {
            $this->resolve();
        });

        parent::__construct(
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'UnevaluatedItems.phptpl',
            InvalidUnevaluatedItemsException::class,
            ['&$invalidItems'],
            [
                'schema' => $schema,
                'validationProperty' => $this->validationProperty,
                'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                'viewHelper' => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
            ],
        );
    }

    /**
     * @inheritDoc
     */
    public function getCheck(): string
    {
        $this->removeRequiredPropertyValidator($this->validationProperty);

        return parent::getCheck();
    }

    public function getValidationProperty(): PropertyInterface
    {
        return $this->validationProperty;
    }

    /**
     * Initialize the per-call error map captured by reference into the IIFE so the
     * generated exception receives the populated list.
     */
    public function getValidatorSetUp(): string
    {
        return '$invalidItems = [];';
    }
}
