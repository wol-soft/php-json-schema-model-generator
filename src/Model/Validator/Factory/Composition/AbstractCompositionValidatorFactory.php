<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Composition;

use PHPModelGenerator\Exception\Generic\DeniedPropertyException;
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
use PHPModelGenerator\Model\Validator\InstanceOfValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\ClearTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\CompositionTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\Filter\CompositionCompatibilityChecker;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\TypeIntersection;

abstract class AbstractCompositionValidatorFactory extends AbstractValidatorFactory
{
    /**
     * Composition keywords whose branches are alternatives rather than joint constraints
     * (unlike allOf) - the only keywords for which excluding a vacuous branch changes the
     * composition's meaning rather than merely removing a no-op.
     */
    private const array EXCLUDABLE_COMPOSITION_KEYS = ['anyOf', 'oneOf', 'if'];

    /**
     * JSON Schema keywords that are purely descriptive/documentary and impose no validation
     * constraint of their own. Used only to decide whether to emit the vacuous-branch warning -
     * never to decide exclusion, which is scoped narrowly to isExampleOnlyBranch() instead.
     */
    private const array ANNOTATION_ONLY_KEYWORDS = [
        '$comment',
        'title',
        'description',
        'default',
        'example',
        'examples',
        'deprecated',
        'readOnly',
        'writeOnly',
    ];

    /**
     * Emit a generation-time warning for always-unsatisfiable composition schemas.
     */
    protected function warnIfAlwaysFalse(
        SchemaProcessor $schemaProcessor,
        PropertyInterface $property,
        string $reason,
    ): void {
        $schemaProcessor->getGeneratorConfiguration()->getLogger()->warning(
            "Always-unsatisfiable schema for property '{property}': {reason}",
            ['property' => $property->getName(), 'reason' => $reason],
        );
    }

    /**
     * Emit a warning when the composition array for the current keyword is empty.
     */
    protected function warnIfEmpty(
        SchemaProcessor $schemaProcessor,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        if (empty($propertySchema->getJson()[$this->key])) {
            $schemaProcessor->getGeneratorConfiguration()->getLogger()->warning(
                "Empty composition for '{property}' may lead to unexpected results",
                ['property' => $property->getName()],
            );
        }
    }

    /**
     * Emit a generation-time warning when a ($ref-resolved) composition branch carries no
     * validation/assertion keyword at all and therefore matches any value. This is purely
     * informational: unlike the narrow, whitelisted exclusion in isExampleOnlyBranch(), it is not
     * limited to the "example" shape and does not change generated behavior - a vacuous branch
     * keeps its full (odd but spec-correct) effect on the composition regardless of this warning.
     *
     * @param array<string, mixed> $resolvedBranchJson
     */
    private function warnIfVacuousBranch(
        SchemaProcessor $schemaProcessor,
        PropertyInterface $property,
        int $branchIndex,
        array $resolvedBranchJson,
    ): void {
        if (array_diff(array_keys($resolvedBranchJson), self::ANNOTATION_ONLY_KEYWORDS) !== []) {
            return;
        }

        $schemaProcessor->getGeneratorConfiguration()->getLogger()->warning(
            "Composition branch #{index} for '{property}' carries no validation keyword and"
                . ' matches any value',
            ['index' => $branchIndex, 'property' => $property->getName()],
        );
    }

    /**
     * A branch consisting solely of the literal "example" keyword (single key, exactly
     * "example") - the one shape explicitly reported in issue #72/PR #74. Deliberately not
     * generalized to any other annotation keyword or combination, and not to a bare empty `{}`
     * branch: JSON Schema does not define "annotation-only branches match nothing", so excluding a
     * branch from a composition is an opinionated DX override of that spec meaning that must stay
     * opt-in per keyword rather than silently expanding to every schema shape that merely lacks
     * assertions.
     *
     * A companion `"type": "object"` key is also tolerated. This is not a broadening of the
     * whitelist by concept: inheritPropertyType() mutates the raw branch JSON (injecting the
     * parent's type into any branch that declares none of its own) before this method ever sees
     * it, for every branch regardless of $ref usage. A $ref-based branch loses that injected
     * sibling on resolution (Draft 7 ignores keywords sitting next to $ref), so it resolves to
     * exactly `{"example": ...}` - but an inline branch keeps it, resolving to
     * `{"example": ..., "type": "object"}` instead. Both are the exact same author-written
     * "example"-only shape; only our own internal bookkeeping differs, so both must be recognized
     * as the same whitelisted case rather than treating $ref vs inline as a real distinction.
     *
     * @param array<string, mixed> $resolvedBranchJson
     */
    private static function isExampleOnlyBranch(array $resolvedBranchJson): bool
    {
        $keys = array_keys($resolvedBranchJson);
        sort($keys);

        if ($keys === ['example']) {
            return true;
        }

        return $keys === ['example', 'type'] && $resolvedBranchJson['type'] === 'object';
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
     * Check the (post-type-inheritance) composition branches for filter keywords.
     *
     * Must be called AFTER inheritPropertyType() in each modify() method. A branch that
     * inherits "object" from the parent is genuinely object-typed: PropertyFactory routes
     * it through processSchema, producing a nested class whose properties are processed
     * independently and are not subject to ComposedItem $value reset. branchContainsFilter()
     * correctly skips the properties scan for such branches.
     *
     * For "not", the value is a single branch schema (not an array); all other keywords
     * use an array of branches.
     *
     * TODO: filters inside composition branches cannot be correctly applied
     * (ComposedItem.phptpl resets $value to $originalModelData after each branch).
     * Proper per-branch filter chaining is deferred to a follow-up topic.
     *
     * @throws SchemaException
     */
    protected function checkForFilterInBranches(
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $json = $propertySchema->getJson();

        if ($this->key === 'not') {
            $branch = $json['not'] ?? null;
            if (
                is_array($branch)
                && CompositionCompatibilityChecker::branchContainsFilter($branch)
            ) {
                throw new SchemaException(
                    sprintf(
                        'A filter keyword inside a not composition branch is not supported'
                            . ' for property %s in file %s.',
                        $property->getName(),
                        $property->getJsonSchema()->getFile(),
                    ),
                    $property->getJsonSchema(),
                );
            }
            return;
        }

        foreach ($json[$this->key] ?? [] as $index => $compositionElement) {
            if (
                is_array($compositionElement)
                && CompositionCompatibilityChecker::branchContainsFilter($compositionElement)
            ) {
                throw new SchemaException(
                    sprintf(
                        'A filter keyword inside a %s composition branch is not supported'
                            . ' for property %s in file %s (branch #%d).',
                        $this->key,
                        $property->getName(),
                        $property->getJsonSchema()->getFile(),
                        $index,
                    ),
                    $property->getJsonSchema(),
                );
            }
        }
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

            // Only branches that resolve synchronously (i.e. right here, not deferred to a later
            // onResolve callback) can be inspected safely: a branch still awaiting resolution is,
            // by construction, part of a recursive $ref chain, which requires structural content
            // (properties/items/...) to recurse through and can therefore never be the vacuous
            // shape this check looks for. Calling getJsonSchema() on a not-yet-resolved branch
            // would risk a fatal error (its wrapped property may still be an unresolved proxy).
            if ($compositionProperty->isResolved()) {
                $resolvedBranchJson = $compositionProperty->getJsonSchema()->getJson();

                $this->warnIfVacuousBranch($schemaProcessor, $property, $index, $resolvedBranchJson);

                if (
                    in_array($this->key, self::EXCLUDABLE_COMPOSITION_KEYS, true)
                    && self::isExampleOnlyBranch($resolvedBranchJson)
                ) {
                    // A branch consisting solely of the OpenAPI-style "example" keyword carries no
                    // constraint and is almost certainly leftover documentation data rather than an
                    // intended alternative - exclude it entirely rather than letting it match any
                    // value. See analysis.md §2d for why this must not reuse
                    // createAlwaysTrueBranchProperty()/markAsAlwaysTrueBranch() instead.
                    continue;
                }
            }

            $compositionProperty->onResolve(function () use ($compositionProperty, $property, $merged): void {
                $nestedSchema = $compositionProperty->getNestedSchema();

                $compositionProperty->filterValidators(
                    static function (Validator $validator) use ($nestedSchema): bool {
                        if (is_a($validator->getValidator(), RequiredPropertyValidator::class)) {
                            return false;
                        }
                        if (is_a($validator->getValidator(), ComposedPropertyValidator::class)) {
                            return false;
                        }
                        // An empty object schema ({type: object} with no declared properties)
                        // must accept any PHP object in composition context. The generated
                        // placeholder class carries no semantic constraints, so the strict
                        // instanceof check against it would incorrectly reject valid objects
                        // (e.g. a DateTime produced by a transforming filter) that are perfectly
                        // acceptable under the schema's actual semantics.
                        if (
                            is_a($validator->getValidator(), InstanceOfValidator::class)
                            && $nestedSchema !== null
                            && empty($nestedSchema->getProperties())
                        ) {
                            return false;
                        }
                        return true;
                    },
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
     */
    protected function inheritPropertyType(JsonSchema $propertySchema): JsonSchema
    {
        $json = $propertySchema->getJson();

        if (!isset($json['type'])) {
            return $propertySchema;
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
                    if (!is_bool($composedElement) && !isset($composedElement['type'])) {
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
     * After all composition branches resolve, derive the parent property's type from the
     * branch types and apply it. Skips when any branch has a nested schema (object merging
     * is handled elsewhere), except that allOf still checks such branches against any sibling
     * scalar-typed branch for an object-vs-scalar conflict — see assertNoObjectScalarTypeConflict().
     *
     * allOf: intersect all typed branch types — only values satisfying every branch simultaneously
     * are valid, so the PHP type is the intersection. Branches with no declared type impose no
     * constraint and are excluded from the intersection. An empty intersection (contradictory
     * branch types) throws SchemaException because no value can ever be valid.
     *
     * anyOf / oneOf: union of all typed branch types — at least one branch must pass, so the PHP
     * type is the union. An untyped branch accepts every value, making the composition satisfied
     * by any input; the property's type hint is removed (remains mixed) in that case. A branch
     * that resolves to an object (nested schema) alongside a scalar-typed sibling branch is not a
     * conflict here: unlike allOf, anyOf/oneOf allow a value to satisfy either shape, so no check
     * is needed.
     *
     * Also callable from outside the factory (e.g. EnumPostProcessor) after a post processor has
     * mutated branch types and needs the parent's native type recomputed from the updated branches.
     *
     * @param bool $isAllOf true for allOf, false for anyOf/oneOf.
     * @param CompositionPropertyDecorator[] $compositionProperties
     *
     * @throws SchemaException when allOf branches declare conflicting types, including an
     *                          object-shaped branch conflicting with a scalar-typed branch.
     */
    public static function transferPropertyType(
        PropertyInterface $property,
        array $compositionProperties,
        bool $isAllOf,
    ): void {
        foreach ($compositionProperties as $compositionProperty) {
            if ($compositionProperty->getNestedSchema() !== null) {
                if ($isAllOf) {
                    self::assertNoObjectScalarTypeConflict($property, $compositionProperties);
                }

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

        if ($isAllOf) {
            self::transferAllOfType($property, $compositionProperties, $hasBranchWithOptionalProperty);
            return;
        }

        self::transferAnyOfOneOfType($property, $compositionProperties, $hasBranchWithOptionalProperty);
    }

    /**
     * A branch resolved via a nested schema always requires the value to be an object. allOf
     * requires every branch to hold simultaneously for the same value, so any sibling branch with
     * an explicit scalar type (string, integer, number, boolean, array, or null) can never be
     * satisfied at the same time as an object-shaped branch — the schema is unsatisfiable.
     *
     * This case is invisible to transferAllOfType()'s type intersection, which only inspects
     * branches with a scalar getType() and returns early whenever any branch has a nested schema.
     * Without this check the conflict previously went undetected: at the schema root it instead
     * surfaced as a confusing generic "No nested schema for composed property" crash (the scalar
     * branch has no nested schema, which SchemaProcessor::transferComposedPropertiesToSchema()
     * requires unconditionally), and nested inside a property it produced no generation-time
     * diagnostic at all — only an allOf validator that rejects every possible input at runtime.
     *
     * @param CompositionPropertyDecorator[] $compositionProperties
     *
     * @throws SchemaException when a scalar-typed branch coexists with an object-shaped branch.
     */
    private static function assertNoObjectScalarTypeConflict(
        PropertyInterface $property,
        array $compositionProperties,
    ): void {
        $hasConflictingScalarBranch = array_filter(
            $compositionProperties,
            static fn(CompositionPropertyDecorator $compositionProperty): bool =>
                $compositionProperty->getNestedSchema() === null
                && $compositionProperty->getType() !== null
                && !$compositionProperty->isAlwaysTrueBranch(),
        ) !== [];

        if ($hasConflictingScalarBranch) {
            self::throwConflictingAllOfTypesException($property);
        }
    }

    /**
     * @throws SchemaException
     */
    private static function throwConflictingAllOfTypesException(PropertyInterface $property): void
    {
        throw new SchemaException(
            sprintf(
                "Property '%s' is defined with conflicting types in allOf composition branches"
                    . ' (file %s). allOf requires all constraints to hold simultaneously,'
                    . ' making this schema unsatisfiable.',
                $property->getName(),
                $property->getJsonSchema()->getFile(),
            ),
            $property->getJsonSchema(),
        );
    }

    /**
     * Derive and apply the parent property's type using allOf intersection semantics.
     *
     * Only typed branches (those that declare a type keyword) constrain the intersection.
     * Untyped branches impose no type restriction and are excluded. Null is valid only when
     * ALL typed branches allow it. An empty non-null intersection (contradictory types) throws
     * SchemaException — no value can satisfy all branch type constraints simultaneously.
     *
     * @param CompositionPropertyDecorator[] $compositionProperties
     *
     * @throws SchemaException
     */
    private static function transferAllOfType(
        PropertyInterface $property,
        array $compositionProperties,
        bool $hasBranchWithOptionalProperty,
    ): void {
        $constrainingBranches = array_values(array_filter(
            $compositionProperties,
            static fn(CompositionPropertyDecorator $p): bool => $p->getType() !== null,
        ));

        if (empty($constrainingBranches)) {
            // No typed branches — no type constraint to apply.
            return;
        }

        // Intersection of non-null type names across all typed branches.
        // TypeIntersection::compute handles int ⊂ float (integer is a subtype of number in JSON Schema).
        $nonNullSets = array_map(
            static fn(CompositionPropertyDecorator $p): array => array_values(array_filter(
                $p->getType()->getNames(),
                static fn(string $typeName): bool => $typeName !== 'null',
            )),
            $constrainingBranches,
        );
        $nonNullNames = array_shift($nonNullSets);
        foreach ($nonNullSets as $typeSet) {
            $nonNullNames = TypeIntersection::compute($nonNullNames, $typeSet);
        }

        // Null is valid in allOf only when ALL typed branches allow it.
        $allBranchesAllowNull = count(array_filter(
            $constrainingBranches,
            static fn(CompositionPropertyDecorator $p): bool =>
                in_array('null', $p->getType()->getNames(), true)
                || $p->getType()->isNullable() === true,
        )) === count($constrainingBranches);

        if (empty($nonNullNames) && !$allBranchesAllowNull) {
            self::throwConflictingAllOfTypesException($property);
        }

        if (empty($nonNullNames)) {
            // Only null survives the intersection; the null-processor path handles pure-null types.
            return;
        }

        $nullable = ($allBranchesAllowNull || $hasBranchWithOptionalProperty) ? true : null;
        $property->setType(new PropertyType($nonNullNames, $nullable));
    }

    /**
     * Derive and apply the parent property's type using anyOf/oneOf union semantics.
     *
     * Branches are partitioned into three categories:
     * - Typed (getType() !== null): contribute their names to the union.
     * - Explicit null-type ({type:null}): getType() is null but typeHint contains 'null';
     *   contributes nullable=true to the result.
     * - Truly untyped ({}): getType() is null and typeHint does not contain 'null'; the branch
     *   accepts every value, so the composition is always satisfiable and no type hint applies.
     *
     * A truly untyped branch causes early return without setting a type (property remains mixed),
     * matching the behaviour of PropertyMerger::mergeNullableBranch for object-level compositions.
     * An explicit null-type branch ({type:null}) is NOT treated as untyped — it adds nullable=true
     * to the typed union rather than removing the type hint.
     *
     * @param CompositionPropertyDecorator[] $compositionProperties
     */
    private static function transferAnyOfOneOfType(
        PropertyInterface $property,
        array $compositionProperties,
        bool $hasBranchWithOptionalProperty,
    ): void {
        $hasExplicitNullBranch = false;

        foreach ($compositionProperties as $compositionProperty) {
            if ($compositionProperty->getType() !== null) {
                continue;
            }

            if (str_contains($compositionProperty->getTypeHint(), 'null')) {
                // Explicit null-type branch ({type: null}): contributes nullable=true.
                $hasExplicitNullBranch = true;
            } else {
                // Truly untyped branch ({}): any value is valid, so the composition is
                // always satisfiable — no type hint is appropriate for this property.
                return;
            }
        }

        $typedBranches = array_values(array_filter(
            $compositionProperties,
            static fn(CompositionPropertyDecorator $p): bool => $p->getType() !== null,
        ));

        if (empty($typedBranches)) {
            // Only explicit null branches; no scalar type to build a union from.
            return;
        }

        $allNames = array_merge(...array_map(
            static fn(CompositionPropertyDecorator $p): array => $p->getType()->getNames(),
            $typedBranches,
        ));

        $hasNull = $hasExplicitNullBranch || in_array('null', $allNames, true);
        $nonNullNames = array_values(array_filter(
            array_unique($allNames),
            static fn(string $typeName): bool => $typeName !== 'null',
        ));

        if (!$nonNullNames) {
            return;
        }

        $nullable = ($hasNull || $hasBranchWithOptionalProperty) ? true : null;
        $property->setType(new PropertyType($nonNullNames, $nullable));
    }
}
