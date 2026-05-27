<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Composition;

use PHPModelGenerator\Exception\ComposedValue\NotException;
use PHPModelGenerator\Exception\Generic\DeniedPropertyException;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

class NotValidatorFactory extends AbstractCompositionValidatorFactory
{
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        if (!isset($propertySchema->getJson()[$this->key]) || $this->shouldSkip($property, $propertySchema)) {
            return;
        }

        $notSchema = $propertySchema->getJson()[$this->key];
        if (is_bool($notSchema)) {
            if ($notSchema === true) {
                $this->warnIfAlwaysFalse(
                    $schemaProcessor,
                    $property,
                    'not: true negates the always-valid schema; no value is accepted',
                );
                $this->buildNotTrueComposition($schemaProcessor, $schema, $property, $propertySchema);
            }

            // not: false is the negation of always-invalid, so every value is accepted — no validator needed.
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

    /**
     * Build the composition that implements `not: true` semantics via NotException.
     *
     * The composition contains one branch with a `!array_key_exists` validator. That validator
     * fires (throws) when the property is ABSENT from $modelData, causing the branch to fail and
     * the not constraint to be satisfied (no exception). When the property IS present, the
     * validator does not fire, the branch succeeds, and the not constraint is violated — throwing
     * NotException.
     */
    private function buildNotTrueComposition(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $propertyFactory = new PropertyFactory();
        $branchSchema = $propertySchema->withJson([]);

        $branchProperty = new CompositionPropertyDecorator(
            $property->getName(),
            $branchSchema,
            $propertyFactory->create(
                $schemaProcessor,
                $schema,
                $property->getName(),
                $branchSchema,
                $property->isRequired(),
            ),
        );

        $absenceCheck = "!array_key_exists('" . addslashes($property->getName()) . "', \$modelData)";

        $branchProperty->onResolve(
            function () use ($branchProperty, $absenceCheck): void {
                $branchProperty->filterValidators(
                    static fn(Validator $validator): bool =>
                        !is_a($validator->getValidator(), RequiredPropertyValidator::class) &&
                        !is_a($validator->getValidator(), ComposedPropertyValidator::class),
                );
                $branchProperty->addValidator(
                    new PropertyValidator(
                        $branchProperty,
                        $absenceCheck,
                        DeniedPropertyException::class,
                    ),
                );
            },
        );

        $compositionProperties = [$branchProperty];

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
                    'availableAmount' => 1,
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
