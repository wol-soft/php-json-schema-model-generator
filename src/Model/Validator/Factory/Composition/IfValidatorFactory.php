<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Composition;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\BaseProperty;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\ConditionalPropertyValidator;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

class IfValidatorFactory
    extends AbstractCompositionValidatorFactory
    implements ComposedPropertiesValidatorFactoryInterface
{
    /**
     * @throws SchemaException
     */
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        if (!isset($propertySchema->getJson()[$this->key]) || $this->shouldSkip($property, $propertySchema)) {
            return;
        }

        $json = $propertySchema->getJson();

        if (!isset($json['then']) && !isset($json['else'])) {
            throw new SchemaException(
                sprintf(
                    'Incomplete conditional composition for property %s in file %s',
                    $property->getName(),
                    $property->getJsonSchema()->getFile(),
                ),
            );
        }

        $propertySchema = $this->inheritPropertyType($propertySchema);
        $json = $propertySchema->getJson();

        $propertyFactory = new PropertyFactory();

        $onlyForDefinedValues = !($property instanceof BaseProperty)
            && (!$property->isRequired()
                && $schemaProcessor->getGeneratorConfiguration()->isImplicitNullAllowed());

        /** @var array<string, CompositionPropertyDecorator|null> $properties */
        $properties = [];

        foreach (['if', 'then', 'else'] as $keyword) {
            if (!isset($json[$keyword])) {
                $properties[$keyword] = null;
                continue;
            }

            $compositionSchema = $propertySchema->withJson($json[$keyword]);

            $compositionProperty = new CompositionPropertyDecorator(
                $property->getName(),
                $compositionSchema,
                $propertyFactory->create(
                    $schemaProcessor,
                    $schema,
                    $property->getName(),
                    $compositionSchema,
                    $property->isRequired(),
                ),
            );

            $compositionProperty->onResolve(static function () use ($compositionProperty): void {
                $compositionProperty->filterValidators(
                    static fn(Validator $validator): bool =>
                        !is_a($validator->getValidator(), RequiredPropertyValidator::class) &&
                        !is_a($validator->getValidator(), ComposedPropertyValidator::class),
                );
            });

            $properties[$keyword] = $compositionProperty;
        }

        $property->addValidator(
            new ConditionalPropertyValidator(
                $schemaProcessor->getGeneratorConfiguration(),
                $property,
                array_values(array_filter($properties)),
                array_values(array_filter([$properties['then'], $properties['else']])),
                [
                    'ifProperty' => $properties['if'],
                    'thenProperty' => $properties['then'],
                    'elseProperty' => $properties['else'],
                    'schema' => $schema,
                    'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                    'viewHelper' => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
                    'onlyForDefinedValues' => $onlyForDefinedValues,
                ],
            ),
            100,
        );
    }
}
