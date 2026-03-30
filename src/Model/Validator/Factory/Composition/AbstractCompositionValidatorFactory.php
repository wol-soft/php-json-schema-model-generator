<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Composition;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\BaseProperty;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\ClearTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\CompositionTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

abstract class AbstractCompositionValidatorFactory extends AbstractValidatorFactory
{
    /**
     * Emit a warning when the composition array for the current keyword is empty.
     */
    protected function warnIfEmpty(
        SchemaProcessor $schemaProcessor,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        if (
            empty($propertySchema->getJson()[$this->key]) &&
            $schemaProcessor->getGeneratorConfiguration()->isOutputEnabled()
        ) {
            // @codeCoverageIgnoreStart
            echo "Warning: empty composition for {$property->getName()} may lead to unexpected results\n";
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * Returns true when composition processing should be skipped for this property.
     *
     * For non-root object-typed properties, composition keywords are processed inside
     * the nested schema by processSchema (with the type=base path). Adding a composition
     * validator at the parent level would duplicate validation and inject a _Merged_ type
     * hint that overrides the correct nested-class type.
     */
    protected function shouldSkip(PropertyInterface $property, JsonSchema $propertySchema): bool
    {
        return !($property instanceof BaseProperty)
            && ($propertySchema->getJson()['type'] ?? '') === 'object';
    }

    /**
     * Build composition sub-properties for the current keyword's branches.
     *
     * @param bool $merged Whether to suppress CompositionTypeHintDecorators for object branches.
     *
     * @return CompositionPropertyDecorator[]
     *
     * @throws SchemaException
     */
    protected function getCompositionProperties(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
        bool $merged,
    ): array {
        $propertyFactory = new PropertyFactory();
        $compositionProperties = [];
        $json = $propertySchema->getJson()['propertySchema']->getJson();

        $property->addTypeHintDecorator(new ClearTypeHintDecorator());

        foreach ($json[$this->key] as $compositionElement) {
            $compositionSchema = $propertySchema->getJson()['propertySchema']->withJson($compositionElement);

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

            $compositionProperty->onResolve(function () use ($compositionProperty, $property, $merged): void {
                $compositionProperty->filterValidators(
                    static fn(Validator $validator): bool =>
                        !is_a($validator->getValidator(), RequiredPropertyValidator::class) &&
                        !is_a($validator->getValidator(), ComposedPropertyValidator::class),
                );

                if (!($merged && $compositionProperty->getNestedSchema())) {
                    $property->addTypeHintDecorator(new CompositionTypeHintDecorator($compositionProperty));
                }
            });

            $compositionProperties[] = $compositionProperty;
        }

        return $compositionProperties;
    }

    /**
     * Inherit a parent-level type into composition branches that declare no type.
     */
    protected function inheritPropertyType(JsonSchema $propertySchema): JsonSchema
    {
        $json = $propertySchema->getJson();

        if (!isset($json['type'])) {
            return $propertySchema;
        }

        if ($json['type'] === 'base') {
            $json['type'] = 'object';
        }

        switch ($this->key) {
            case 'not':
                if (!isset($json[$this->key]['type'])) {
                    $json[$this->key]['type'] = $json['type'];
                }
                break;
            case 'if':
                return $this->inheritIfPropertyType($propertySchema->withJson($json));
            default:
                foreach ($json[$this->key] as &$composedElement) {
                    if (!isset($composedElement['type'])) {
                        $composedElement['type'] = $json['type'];
                    }
                }
        }

        return $propertySchema->withJson($json);
    }

    /**
     * Inherit the parent type into all branches of an if/then/else composition.
     */
    protected function inheritIfPropertyType(JsonSchema $propertySchema): JsonSchema
    {
        $json = $propertySchema->getJson();

        foreach (['if', 'then', 'else'] as $keyword) {
            if (!isset($json[$keyword])) {
                continue;
            }

            if (!isset($json[$keyword]['type'])) {
                $json[$keyword]['type'] = $json['type'];
            }
        }

        return $propertySchema->withJson($json);
    }

    /**
     * After all composition branches resolve, attempt to widen the parent property's type
     * to cover all branch types. Skips for branches with nested schemas.
     *
     * @param bool $isAllOf Whether allOf semantics apply (affects nullable detection).
     * @param CompositionPropertyDecorator[] $compositionProperties
     */
    protected function transferPropertyType(
        PropertyInterface $property,
        array $compositionProperties,
        bool $isAllOf,
    ): void {
        foreach ($compositionProperties as $compositionProperty) {
            if ($compositionProperty->getNestedSchema() !== null) {
                return;
            }
        }

        $allNames = array_merge(...array_map(
            static fn(CompositionPropertyDecorator $p): array =>
                $p->getType() ? $p->getType()->getNames() : [],
            $compositionProperties,
        ));

        $hasBranchWithNoType = array_filter(
            $compositionProperties,
            static fn(CompositionPropertyDecorator $p): bool => $p->getType() === null,
        ) !== [];

        $hasBranchWithRequiredProperty = array_filter(
            $compositionProperties,
            static fn(CompositionPropertyDecorator $p): bool => $p->isRequired(),
        ) !== [];

        $hasBranchWithOptionalProperty = $isAllOf
            ? !$hasBranchWithRequiredProperty
            : array_filter(
                $compositionProperties,
                static fn(CompositionPropertyDecorator $p): bool => !$p->isRequired(),
            ) !== [];

        $hasNull = in_array('null', $allNames, true);
        $nonNullNames = array_values(array_filter(
            array_unique($allNames),
            fn(string $t): bool => $t !== 'null',
        ));

        if (!$nonNullNames) {
            return;
        }

        $nullable = ($hasNull || $hasBranchWithNoType || $hasBranchWithOptionalProperty) ? true : null;

        $property->setType(new PropertyType($nonNullNames, $nullable));
    }
}
