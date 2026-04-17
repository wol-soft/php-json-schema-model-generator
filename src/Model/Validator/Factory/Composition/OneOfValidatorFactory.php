<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Composition;

use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\BaseProperty;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

class OneOfValidatorFactory
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

        $onlyForDefinedValues = !($property instanceof BaseProperty)
            && (!$property->isRequired()
                && $schemaProcessor->getGeneratorConfiguration()->isImplicitNullAllowed());

        $wrappedSchema = $propertySchema->withJson([
            'type' => $this->key,
            'propertySchema' => $propertySchema,
            'onlyForDefinedValues' => $onlyForDefinedValues,
        ]);

        $compositionProperties = $this->getCompositionProperties(
            $schemaProcessor,
            $schema,
            $property,
            $wrappedSchema,
            false,
        );

        $resolvedCompositions = 0;
        foreach ($compositionProperties as $compositionProperty) {
            $compositionProperty->onResolve(
                function () use (&$resolvedCompositions, $property, $compositionProperties): void {
                    if (++$resolvedCompositions === count($compositionProperties)) {
                        $this->transferPropertyType($property, $compositionProperties, false);
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
                OneOfException::class,
                [
                    'compositionProperties' => $compositionProperties,
                    'schema' => $schema,
                    'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                    'viewHelper' => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
                    'availableAmount' => $availableAmount,
                    'composedValueValidation' => '$succeededCompositionElements === 1',
                    'postPropose' => true,
                    'mergedProperty' => null,
                    'onlyForDefinedValues' => $onlyForDefinedValues,
                ],
            ),
            100,
        );
    }
}
