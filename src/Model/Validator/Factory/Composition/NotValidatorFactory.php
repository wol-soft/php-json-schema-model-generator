<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Composition;

use PHPModelGenerator\Exception\ComposedValue\NotException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

class NotValidatorFactory extends AbstractCompositionValidatorFactory
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

        // Inherit the parent type into the not branch before wrapping in array.
        // inheritPropertyType for 'not' treats $json['not'] as a single schema object,
        // so it must run before we wrap it in an array for iteration.
        $propertySchema = $this->inheritPropertyType($propertySchema);
        $json = $propertySchema->getJson();

        // Wrap the single 'not' schema in an array so getCompositionProperties can iterate it.
        $json[$this->key] = [$json[$this->key]];
        $wrappedOuter = $propertySchema->withJson($json);

        // Force required=true so strict null checks apply inside the not branch.
        $property->setRequired(true);

        $wrappedSchema = $wrappedOuter->withJson([
            'type' => $this->key,
            'propertySchema' => $wrappedOuter,
            'onlyForDefinedValues' => false,
        ]);

        $compositionProperties = $this->getCompositionProperties(
            $schemaProcessor,
            $schema,
            $property,
            $wrappedSchema,
            false,
        );

        $availableAmount = count($compositionProperties);

        $property->addValidator(
            new ComposedPropertyValidator(
                $schemaProcessor->getGeneratorConfiguration(),
                $property,
                $compositionProperties,
                static::class,
                NotException::class,
                [
                    'compositionProperties' => $compositionProperties,
                    'schema' => $schema,
                    'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                    'viewHelper' => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
                    'availableAmount' => $availableAmount,
                    'composedValueValidation' => '$succeededCompositionElements === 0',
                    'postPropose' => false,
                    'mergedProperty' => null,
                    'onlyForDefinedValues' => false,
                ],
            ),
            100,
        );
    }
}
