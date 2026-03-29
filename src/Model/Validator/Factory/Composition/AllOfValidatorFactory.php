<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Composition;

use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\BaseProperty;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

class AllOfValidatorFactory
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

        $this->warnIfEmpty($schemaProcessor, $property, $propertySchema);
        $propertySchema = $this->inheritPropertyType($propertySchema);

        $wrappedSchema = $propertySchema->withJson([
            'type' => $this->key,
            'propertySchema' => $propertySchema,
            'onlyForDefinedValues' => false,
        ]);

        $compositionProperties = $this->getCompositionProperties(
            $schemaProcessor,
            $schema,
            $property,
            $wrappedSchema,
            true,
        );

        $resolvedCompositions = 0;
        $mergedProperty = null;
        foreach ($compositionProperties as $compositionProperty) {
            $compositionProperty->onResolve(
                function () use (
                    &$resolvedCompositions,
                    &$mergedProperty,
                    $property,
                    $compositionProperties,
                    $wrappedSchema,
                    $schemaProcessor,
                    $schema,
                ): void {
                    if (++$resolvedCompositions === count($compositionProperties)) {
                        $this->transferPropertyType($property, $compositionProperties, true);

                        $mergedProperty = !($property instanceof BaseProperty)
                            ? $schemaProcessor->createMergedProperty(
                                $schema,
                                $property,
                                $compositionProperties,
                                $wrappedSchema,
                            )
                            : null;
                    }
                },
            );
        }

        $availableAmount = count($compositionProperties);

        $property->addValidator(
            new ComposedPropertyValidator(
                $schemaProcessor->getGeneratorConfiguration(),
                $property,
                $compositionProperties,
                static::class,
                AllOfException::class,
                [
                    'compositionProperties' => $compositionProperties,
                    'schema' => $schema,
                    'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                    'viewHelper' => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
                    'availableAmount' => $availableAmount,
                    'composedValueValidation' => "\$succeededCompositionElements === $availableAmount",
                    'postPropose' => true,
                    'mergedProperty' => &$mergedProperty,
                    'onlyForDefinedValues' => false,
                ],
            ),
            100,
        );
    }
}
