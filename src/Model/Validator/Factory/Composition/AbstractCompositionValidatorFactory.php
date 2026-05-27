<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Composition;

use PHPModelGenerator\Exception\Generic\DeniedPropertyException;
use PHPModelGenerator\Model\Property\BaseProperty;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\ClearTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\CompositionTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

abstract class AbstractCompositionValidatorFactory extends AbstractValidatorFactory
{
    /**
     * Emit a generation-time warning for always-unsatisfiable composition schemas.
     */
    protected function warnIfAlwaysFalse(
        SchemaProcessor $schemaProcessor,
        PropertyInterface $property,
        string $reason,
    ): void {
        if ($schemaProcessor->getGeneratorConfiguration()->isOutputEnabled()) {
            // @codeCoverageIgnoreStart
            echo "Warning: always-unsatisfiable schema for property '{$property->getName()}': $reason\n";
            // @codeCoverageIgnoreEnd
        }
    }

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

        foreach ($json[$this->key] as $index => $compositionElement) {
            if ($compositionElement === false) {
                $compositionProperties[] = $this->createAlwaysFalseBranchProperty(
                    $schemaProcessor,
                    $schema,
                    $property,
                    $propertySchema->getJson()['propertySchema'],
                );
                continue;
            }

            if ($compositionElement === true) {
                $compositionProperties[] = $this->createAlwaysTrueBranchProperty(
                    $schemaProcessor,
                    $schema,
                    $property,
                    $propertySchema->getJson()['propertySchema'],
                );
                continue;
            }

            $compositionSchema = $propertySchema->getJson()['propertySchema']->navigate("$this->key/$index");

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
     * Create a composition branch for a boolean `false` schema element.
     *
     * The branch always fails when the property key is present in $modelData, so absent optional
     * properties are not denied. Used for false branches in allOf/anyOf/oneOf compositions.
     */
    protected function createAlwaysFalseBranchProperty(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $parentSchema,
    ): CompositionPropertyDecorator {
        $propertyFactory = new PropertyFactory();
        $branchSchema = $parentSchema->withJson([]);

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

        $presenceCheck = "array_key_exists('" . addslashes($property->getName()) . "', \$modelData)";

        $branchProperty->onResolve(
            function () use ($branchProperty, $presenceCheck): void {
                $branchProperty->filterValidators(
                    static fn(Validator $validator): bool =>
                        !is_a($validator->getValidator(), RequiredPropertyValidator::class) &&
                        !is_a($validator->getValidator(), ComposedPropertyValidator::class),
                );
                $branchProperty->addValidator(
                    new PropertyValidator(
                        $branchProperty,
                        $presenceCheck,
                        DeniedPropertyException::class,
                    ),
                );
            },
        );

        return $branchProperty;
    }

    /**
     * Create a composition branch for a boolean `true` schema element.
     *
     * The branch always succeeds (no validators) and is marked as an always-true branch so that
     * type inference excludes it from type narrowing. Used for true branches in allOf/anyOf/oneOf.
     */
    protected function createAlwaysTrueBranchProperty(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $parentSchema,
    ): CompositionPropertyDecorator {
        $propertyFactory = new PropertyFactory();
        $branchSchema = $parentSchema->withJson([]);

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

        $branchProperty->markAsAlwaysTrueBranch();

        $branchProperty->onResolve(function () use ($branchProperty): void {
            $branchProperty->filterValidators(
                static fn(Validator $validator): bool =>
                    !is_a($validator->getValidator(), RequiredPropertyValidator::class) &&
                    !is_a($validator->getValidator(), ComposedPropertyValidator::class),
            );
            // No validator added — true schema always succeeds.
            // No type hint decorator — true schema contributes no type constraint.
        });

        return $branchProperty;
    }

    /**
     * Inherit a parent-level type into composition branches that declare no type.
     *
     * When the parent has no explicit type, branches that use object-like keywords
     * (properties, required, additionalProperties) are inferred as type: object.
     * This ensures each branch generates its own nested class/validation scope,
     * matching the pre-refactoring AnyProcessor behavior.
     */
    protected function inheritPropertyType(JsonSchema $propertySchema): JsonSchema
    {
        $json = $propertySchema->getJson();

        $parentHasType = isset($json['type']);

        $hasObjectKeywords = function (array $element): bool {
            if (isset($element['type'])) {
                return false;
            }

            return isset($element['properties'])
                || isset($element['required'])
                || isset($element['additionalProperties']);
        };

        switch ($this->key) {
            case 'not':
                if (isset($json[$this->key]) && !isset($json[$this->key]['type'])) {
                    if ($parentHasType) {
                        $json[$this->key]['type'] = $json['type'];
                    } elseif ($hasObjectKeywords($json[$this->key])) {
                        $json[$this->key]['type'] = 'object';
                    }
                }
                break;
            case 'if':
                return $this->inheritIfPropertyType($propertySchema->withJson($json));
            default:
                foreach ($json[$this->key] as &$composedElement) {
                    if (isset($composedElement['type'])) {
                        continue;
                    }

                    if(is_bool($composedElement)){
                        continue;
                    }

                    if ($parentHasType) {
                        $composedElement['type'] = $json['type'];
                    } elseif ($hasObjectKeywords($composedElement)) {
                        $composedElement['type'] = 'object';
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
            if (!isset($json[$keyword]) || is_bool($json[$keyword])) {
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

        // For anyOf/oneOf: a true branch always satisfies the composition for any value,
        // so the property type cannot be narrowed — leave it untyped.
        // For allOf: exclude true branches from type computation; they contribute no constraint.
        $activeBranches = array_values(array_filter(
            $compositionProperties,
            static fn(CompositionPropertyDecorator $compositionProperty): bool =>
                !$compositionProperty->isAlwaysTrueBranch(),
        ));

        if (!$isAllOf && count($activeBranches) < count($compositionProperties)) {
            return;
        }

        $allNames = array_merge(...array_map(
            static fn(CompositionPropertyDecorator $p): array =>
                $p->getType() ? $p->getType()->getNames() : [],
            $activeBranches,
        ));

        $hasBranchWithNoType = array_filter(
            $activeBranches,
            static fn(CompositionPropertyDecorator $p): bool => $p->getType() === null,
        ) !== [];

        $hasBranchWithRequiredProperty = array_filter(
            $activeBranches,
            static fn(CompositionPropertyDecorator $p): bool => $p->isRequired(),
        ) !== [];

        $hasBranchWithOptionalProperty = $isAllOf
            ? !$hasBranchWithRequiredProperty
            : array_filter(
                $activeBranches,
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
