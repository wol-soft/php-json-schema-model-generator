<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor\Internal;

use PHPModelGenerator\Exception\UnsupportedSchemaFeatureException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\MethodInterface;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\ArrayContainsValidator;
use PHPModelGenerator\Model\Validator\Factory\Composition\NotValidatorFactory;
use PHPModelGenerator\Model\Validator\UnevaluatedPropertiesValidator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Traits\CompositionEvaluationTrait;

/**
 * Detects whether unevaluatedProperties or unevaluatedItems is reachable from a schema and,
 * when it is, activates evaluation tracking on that schema's composition validators.
 *
 * Activation emits the _compositionEvaluations cache field on the generated class and marks
 * each composition validator with per-branch slot writes. When neither keyword is reachable
 * anywhere in the schema graph, this post processor is a complete no-op.
 *
 * Cross-state revalidation under mutation (setter + populate) is *not* handled here. It lives
 * in Model.phptpl and Populate.phptpl as a direct call to _executePostCompositionValidators
 * against a candidate state — see the templates for details. The unevaluatedProperties
 * validator participates in that pass simply by being registered as a post-composition
 * validator on the schema; this post processor's only responsibility is the activation-side
 * work that makes the cache available to it.
 *
 * Known limitation: when a cross-state check fails in direct-exception mode, composition
 * validation that ran during the setter's per-property _validate* phase may have updated
 * _propertyValidationState / _compositionEvaluations with state that was never committed.
 * The cache may briefly hold "would-be" entries until the next mutation invalidates them.
 * This is a pre-existing concern with composition revalidation, not specific to the
 * unevaluatedProperties feature, and self-corrects the next time an affecting property
 * changes.
 */
class UnevaluatedPropertiesPostProcessor extends PostProcessor
{
    /**
     * Monotonically increasing counter scoped to a single ($schema, $property) activation
     * pass. Reset per property at the start of its walk so each property's compositions
     * receive `<propertyName>_0`, `<propertyName>_1`, ... slot keys. Tracked on the
     * instance so the recursive activation helpers do not need to thread a by-reference
     * parameter through every call.
     */
    private int $slotKeyCounter = 0;

    /**
     * Object ids of composition validators already activated in the current property
     * walk. Required (not defensive) — a self-referencing schema such as
     * `{type: array, allOf: [{$ref: "#"}], unevaluatedItems: false}` produces a composition
     * validator whose composed property's wrapped property carries the same composition
     * validator instance. Without this short-circuit, `activateArrayComposition()` would
     * recurse indefinitely.
     *
     * @var array<int, true>
     */
    private array $activatedCompositions = [];

    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $seen = [];

        if (!$this->needsActivation($schema, $seen)) {
            return;
        }

        $this->assertNoUnsupportedDownPropagation($schema);

        // A schema with a non-false `unevaluatedProperties` validator tracks each key the
        // validator successfully evaluates in `_evaluatedPropertyKeys`. That field is read
        // by `_getEvaluatedProperties()` on nested branch classes so an enclosing
        // `unevaluatedProperties` sees those keys as evaluated.
        $this->addEvaluatedPropertyKeysField($schema);

        // The nested branch schemas may already be queued — adding the method here, before any
        // render() runs, ensures every branch class carries _getEvaluatedProperties() regardless
        // of whether the outer or inner schema was processed first. RenderQueue::execute runs
        // process() over every job before render() begins.
        $this->addGetEvaluatedPropertiesToNestedBranchSchemas($schema);

        // The trait carries collectUnevaluatedKeys(), which every generated unevaluatedProperties
        // validator calls regardless of whether the schema also has composition validators. It
        // must therefore be attached on every activation-triggering schema, not only on ones that
        // declare allOf/anyOf/oneOf/if-then-else.
        $schema->addTrait(CompositionEvaluationTrait::class);

        $this->activateSchemaLevelTracking($schema);
        $this->activateArrayPropertyTracking($schema);
    }

    /**
     * Rejects a composition branch's `unevaluatedProperties`/`unevaluatedItems` when it cannot
     * possibly be evaluated correctly: the branch renders as its own, separately-constructed
     * class with no visibility into the enclosing schema's own declarations or a sibling
     * branch's claims (only the reverse direction - branch claims propagating up - is
     * implemented). Silently computing a wrong "unevaluated" set would either reject valid
     * input or accept invalid input, so this fails loudly at generation time instead - see
     * `.claude/topics/branch-unevaluated-down-propagation/` for the full design discussion,
     * including why a general fix was rejected as either unsound (anyOf/oneOf: two sibling
     * branches each gating on the other's claims can have no unique consistent answer at all,
     * not just an expensive one to compute) or out of proportion to a pattern of unknown
     * real-world frequency (allOf, where a fix is sound but non-trivial).
     *
     * `true` is exempt: it never rejects anything regardless of what the branch believes is
     * evaluated, so the gap has no observable effect on validation outcomes. `not` is exempt
     * because it already blocks annotations from crossing its boundary in both directions by
     * design (see `not.rst`) - nothing about its own outcome depends on outside annotations.
     */
    private function assertNoUnsupportedDownPropagation(Schema $schema): void
    {
        foreach ($schema->getBaseValidators() as $validator) {
            if ($validator instanceof AbstractComposedPropertyValidator) {
                $this->checkBranchesForUnsupportedDownPropagation(
                    $validator,
                    $schema->getJsonSchema()->getJson(),
                    $schema->getClassName(),
                );
            }
        }

        foreach ($schema->getProperties() as $schemaProperty) {
            foreach ($schemaProperty->getOrderedValidators() as $validator) {
                if ($validator instanceof AbstractComposedPropertyValidator) {
                    $this->checkBranchesForUnsupportedDownPropagation(
                        $validator,
                        $schemaProperty->getJsonSchema()->getJson(),
                        $schemaProperty->getName(),
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $enclosingJson The JSON of the schema/property the composition
     *                                             keyword itself sits on - not any one branch's.
     */
    private function checkBranchesForUnsupportedDownPropagation(
        AbstractComposedPropertyValidator $validator,
        array $enclosingJson,
        string $contextName,
    ): void {
        if (is_a($validator->getCompositionProcessor(), NotValidatorFactory::class, true)) {
            return;
        }

        $composedProperties = $validator->getComposedProperties();
        $branchJsonList = [];
        $seen = [];

        foreach ($composedProperties as $index => $composedProperty) {
            $branchJsonList[$index] = $this->collectBranchOwnJson($composedProperty, $seen);
        }

        $enclosingHasObjectClaims = $this->declaresObjectClaimingKeyword($enclosingJson);
        $enclosingHasArrayClaims = $this->declaresArrayClaimingKeyword($enclosingJson);

        foreach ($branchJsonList as $index => $branchJson) {
            $somethingElseHasObjectClaims = $enclosingHasObjectClaims;
            $somethingElseHasArrayClaims = $enclosingHasArrayClaims;

            foreach ($branchJsonList as $siblingIndex => $siblingJson) {
                if ($siblingIndex === $index) {
                    continue;
                }

                $somethingElseHasObjectClaims =
                    $somethingElseHasObjectClaims || $this->declaresObjectClaimingKeyword($siblingJson);
                $somethingElseHasArrayClaims =
                    $somethingElseHasArrayClaims || $this->declaresArrayClaimingKeyword($siblingJson);
            }

            $this->assertKeywordNotUnsupported(
                $branchJson,
                'unevaluatedProperties',
                $somethingElseHasObjectClaims,
                $composedProperties[$index]->getBranchSchema(),
                $contextName,
                $index,
            );
            $this->assertKeywordNotUnsupported(
                $branchJson,
                'unevaluatedItems',
                $somethingElseHasArrayClaims,
                $composedProperties[$index]->getBranchSchema(),
                $contextName,
                $index,
            );
        }
    }

    /**
     * A branch's own JSON, or - when it has no nested `Schema` of its own (true for every
     * array-typed branch, and for a self/mutually-referencing `$ref` branch) - the JSON of
     * whatever composition is nested directly inside it instead, recursing the same way
     * `propertyHasBranchUnevaluatedItems()` does. `$seen` (keyed on file+pointer, not object
     * identity - `getOrderedValidators()` returns fresh clones on every call) guards against the
     * same self-referencing-schema cycle item 15 fixed there.
     *
     * @param array<string, bool> $seen
     */
    private function collectBranchOwnJson(CompositionPropertyDecorator $composedProperty, array &$seen): array
    {
        $branchJson = $composedProperty->getBranchSchema()->getJson();

        if ($composedProperty->getNestedSchema() !== null) {
            return $branchJson;
        }

        $wrappedProperty = $composedProperty->getWrappedProperty();
        $propertyKey = $wrappedProperty->getJsonSchema()->getFile()
            . '#' . $wrappedProperty->getJsonSchema()->getPointer();

        if (array_key_exists($propertyKey, $seen)) {
            return $branchJson;
        }

        $seen[$propertyKey] = true;

        foreach ($wrappedProperty->getOrderedValidators() as $nestedValidator) {
            if (!$nestedValidator instanceof AbstractComposedPropertyValidator) {
                continue;
            }

            foreach ($nestedValidator->getComposedProperties() as $nestedComposedProperty) {
                $branchJson = array_merge($branchJson, $this->collectBranchOwnJson($nestedComposedProperty, $seen));
            }
        }

        return $branchJson;
    }

    private function declaresObjectClaimingKeyword(array $json): bool
    {
        return array_key_exists('properties', $json)
            || array_key_exists('patternProperties', $json)
            || array_key_exists('additionalProperties', $json);
    }

    private function declaresArrayClaimingKeyword(array $json): bool
    {
        return array_key_exists('items', $json)
            || array_key_exists('additionalItems', $json)
            || array_key_exists('contains', $json);
    }

    private function assertKeywordNotUnsupported(
        array $branchJson,
        string $keyword,
        bool $somethingElseClaims,
        JsonSchema $branchSchema,
        string $contextName,
        int $branchIndex,
    ): void {
        if (
            !array_key_exists($keyword, $branchJson)
            || $branchJson[$keyword] === true
            || !$somethingElseClaims
        ) {
            return;
        }

        throw new UnsupportedSchemaFeatureException(
            sprintf(
                "Branch #%d of the composition for '%s' declares '%s', which cannot yet see "
                    . 'property names or indices declared by the enclosing schema or a sibling '
                    . "branch - remove '%s' from the branch, or restructure the schema so it is "
                    . 'declared only at the level that needs it',
                $branchIndex + 1,
                $contextName,
                $keyword,
                $keyword,
            ),
            $branchSchema,
        );
    }

    /**
     * Enables evaluation tracking on every schema-level composition validator and declares
     * the `_compositionEvaluations` cache field if any composition was activated.
     *
     * Each activated branch writes its success bit and the property names it claimed into
     * `_compositionEvaluations[$validatorIndex][$componentIndex]`. The unevaluatedProperties
     * validator reads those slots via the trait's `collectUnevaluatedKeys`. Schemas without
     * any composition skip the field declaration — the trait's reads use `?? []`, so the
     * absence is safe.
     */
    private function activateSchemaLevelTracking(Schema $schema): void
    {
        $activated = false;
        foreach ($schema->getBaseValidators() as $baseValidator) {
            if ($baseValidator instanceof AbstractComposedPropertyValidator) {
                $baseValidator->enableEvaluationTracking();
                $activated = true;
            }
        }

        if (!$activated) {
            return;
        }

        $schema->addProperty(
            (new Property(
                'compositionEvaluations',
                new PropertyType('array'),
                new JsonSchema(__FILE__, []),
            ))
                ->setInternal(true)
                ->setDefaultValue([]),
        );
    }

    /**
     * Walks array properties carrying `unevaluatedItems`, activates evaluation tracking on
     * any composition validator attached to such a property, and declares the two transient
     * array-side fields when their respective write sites are reachable:
     *   - `_compositionAnnotated` — only when at least one property-level composition was
     *     activated. The composition template wholesale-overwrites the property's slot at
     *     the end of every chain run.
     *   - `_evaluatedItemIndices` — whenever at least one array property carries
     *     `unevaluatedItems`. The unevaluatedItems template writes a `[propertyName =>
     *     [index => true]]` entry after each successful per-index validation.
     */
    private function activateArrayPropertyTracking(Schema $schema): void
    {
        $evaluatedItemIndicesNeeded = false;
        $compositionActivated = false;

        foreach ($schema->getProperties() as $schemaProperty) {
            // The unevaluatedItems factory is registered on the `array` type only, so a
            // property whose type cannot hold an array never produces an unevaluatedItems
            // validator at runtime. Activating compositions in that case would write
            // `_compositionAnnotated` slots that nobody reads — wasted state.
            $typeNames = $schemaProperty->getType()?->getNames() ?? [];
            if ($typeNames !== [] && !in_array('array', $typeNames, true)) {
                continue;
            }

            $propertyJson = $schemaProperty->getJsonSchema()->getJson();
            $hasDirectUnevaluatedItems = array_key_exists('unevaluatedItems', $propertyJson);
            // A composition branch may carry unevaluatedItems even when the array property itself
            // does not (e.g. allOf: [{unevaluatedItems: {schema}}]). The branch's validator still
            // needs the _evaluatedItemIndices field declared on the class.
            $hasBranchUnevaluatedItems = $this->propertyHasBranchUnevaluatedItems($schemaProperty);

            if (!$hasDirectUnevaluatedItems && !$hasBranchUnevaluatedItems) {
                continue;
            }

            $evaluatedItemIndicesNeeded = true;

            // Composition activation and sibling crediting are only relevant when the property
            // itself carries an unevaluatedItems check that reads those annotations. A branch-only
            // unevaluatedItems validates within its own branch and needs no outer crediting.
            if (!$hasDirectUnevaluatedItems) {
                continue;
            }

            // A direct-sibling `contains` credits its matched indices to the property's evaluated
            // set so the sibling unevaluatedItems check sees them as evaluated.
            $this->enableDirectSiblingContainsTracking($schemaProperty);

            $this->slotKeyCounter = 0;
            $this->activatedCompositions = [];

            foreach ($schemaProperty->getOrderedValidators() as $validator) {
                if ($validator instanceof AbstractComposedPropertyValidator) {
                    $this->activateArrayComposition($validator, $schemaProperty);
                    $compositionActivated = true;
                }
            }
        }

        if ($compositionActivated) {
            // Transient bridge between property-level array compositions and a sibling
            // unevaluatedItems validator. Wholesale-overwritten per chain run; never registered
            // with the rollback registry, never snapshotted across setter calls.
            $schema->addProperty(
                (new Property(
                    'compositionAnnotated',
                    new PropertyType('array'),
                    new JsonSchema(__FILE__, []),
                ))
                    ->setInternal(true)
                    ->setDefaultValue([]),
            );
        }

        if ($evaluatedItemIndicesNeeded) {
            // Per-array-property index map of indices the property's UnevaluatedItems validator
            // successfully evaluated, shaped as [propertyName => [index => true]]. Inner and outer
            // UnevaluatedItems validators share the same instance field — there are no nested
            // array classes to cross. Transient: every chain run overwrites it; never registered
            // with the rollback registry, never snapshotted across setter calls.
            $schema->addProperty(
                (new Property(
                    'evaluatedItemIndices',
                    new PropertyType('array'),
                    new JsonSchema(__FILE__, []),
                ))
                    ->setInternal(true)
                    ->setDefaultValue([]),
            );
        }
    }

    /**
     * True when any composition validator directly on the property carries a branch declaring
     * `unevaluatedItems` — either directly in the branch's own JSON, or nested one or more
     * levels deeper inside a composition the branch itself carries. Such a branch's validator
     * writes and reads `_evaluatedItemIndices`, so the field must be declared even though the
     * array property itself has no unevaluatedItems.
     *
     * An array-typed branch never gets its own nested `Schema` (unlike an object-typed branch,
     * which always routes through `processSchema()`), so a further composition keyword nested
     * inside the branch's own JSON — e.g. the branch is itself `{oneOf: [{unevaluatedItems:
     * ...}]}` — has no `Schema` object for `getNestedSchema()` to recurse into. Recursing
     * through the branch's wrapped property's own validators instead mirrors how
     * `activateValidatorsInBranch()` already walks this exact structure for the activation step
     * itself.
     *
     * A self-referencing array composition (e.g. `{type: array, allOf: [{$ref: "#"}]}`) makes
     * the wrapped property this recurses into the same property again, indefinitely — required
     * cycle protection, not defensive, the same class of cycle `needsActivation()` guards
     * against for schemas. Keyed on file+pointer rather than object identity because
     * `getOrderedValidators()` returns fresh clones on every call (see
     * `activateValidatorsInBranch()`'s own comment on this), so the wrapped `PropertyInterface`
     * instance is not guaranteed stable across recursive calls even for the same schema location.
     */
    private function propertyHasBranchUnevaluatedItems(PropertyInterface $property, array &$seen = []): bool
    {
        $propertyKey = $property->getJsonSchema()->getFile() . '#' . $property->getJsonSchema()->getPointer();

        if (array_key_exists($propertyKey, $seen)) {
            return $seen[$propertyKey];
        }

        $seen[$propertyKey] = false;

        foreach ($property->getOrderedValidators() as $validator) {
            if (!$validator instanceof AbstractComposedPropertyValidator) {
                continue;
            }

            foreach ($validator->getComposedProperties() as $composedProperty) {
                if (array_key_exists('unevaluatedItems', $composedProperty->getBranchSchema()->getJson())) {
                    return $seen[$propertyKey] = true;
                }

                if (
                    $composedProperty->getNestedSchema() === null
                    && $this->propertyHasBranchUnevaluatedItems($composedProperty->getWrappedProperty(), $seen)
                ) {
                    return $seen[$propertyKey] = true;
                }
            }
        }

        return false;
    }

    /**
     * Enable direct-sibling index crediting on any `contains` validator sitting directly on the
     * property, so a sibling unevaluatedItems check sees the matched indices as evaluated.
     */
    private function enableDirectSiblingContainsTracking(PropertyInterface $property): void
    {
        foreach ($property->getOrderedValidators() as $validator) {
            if ($validator instanceof ArrayContainsValidator) {
                $validator->setTrackEvaluatedItems();
            }
        }
    }

    /**
     * Enable evaluation tracking on a property-level composition validator on an array
     * property and recurse into its branches. The slot-key counter on the post-processor
     * instance is shared across the recursion so an outer composition and a nested one
     * inside one of its branches receive monotonically increasing keys (e.g. `tags_0` for
     * the outer allOf, `tags_1` for an inner oneOf). Within each branch, any ArrayContains
     * validator gets its trackBranchMatches flag set so the contains template exports per-
     * index match results to the surrounding composition body.
     *
     * The instance-level `$activatedCompositions` set guards against `$ref`-induced cycles
     * in the composition graph; activating the same validator twice would double-emit slot
     * writes and confuse the rebuild.
     */
    private function activateArrayComposition(
        AbstractComposedPropertyValidator $compositionValidator,
        PropertyInterface $parentProperty,
    ): void {
        $compositionId = spl_object_id($compositionValidator);
        if (isset($this->activatedCompositions[$compositionId])) {
            return;
        }
        $this->activatedCompositions[$compositionId] = true;

        $compositionValidator->enableEvaluationTracking();
        $compositionValidator->setSlotKey($parentProperty->getName() . '_' . $this->slotKeyCounter++);

        foreach ($compositionValidator->getComposedProperties() as $composedProperty) {
            $this->activateValidatorsInBranch($composedProperty, $parentProperty);
        }
    }

    private function activateValidatorsInBranch(
        CompositionPropertyDecorator $composedProperty,
        PropertyInterface $parentProperty,
    ): void {
        // Iterate the wrapped property's validators directly. The decorator's
        // getOrderedValidators() returns fresh withProperty() clones every call, so a
        // mutation on those clones would be invisible at render time.
        foreach ($composedProperty->getWrappedProperty()->getOrderedValidators() as $validator) {
            if ($validator instanceof AbstractComposedPropertyValidator) {
                $this->activateArrayComposition($validator, $parentProperty);
                continue;
            }

            if ($validator instanceof ArrayContainsValidator) {
                $validator->setTrackBranchMatches(true);
            }
        }
    }

    /**
     * Adds the `_evaluatedPropertyKeys` collection field to a schema that carries the
     * schema-form `UnevaluatedPropertiesValidator`. Nested branch schemas reach this code
     * through their own `process()` call (RenderQueue calls each job's processors), so no
     * recursion across branches is required here.
     */
    private function addEvaluatedPropertyKeysField(Schema $schema): void
    {
        foreach ($schema->getPostCompositionValidators() as $postCompositionValidator) {
            if ($postCompositionValidator instanceof UnevaluatedPropertiesValidator) {
                $schema->addProperty(
                    (new Property(
                        'evaluatedPropertyKeys',
                        new PropertyType('array'),
                        new JsonSchema(__FILE__, []),
                    ))
                        ->setInternal(true)
                        ->setDefaultValue([]),
                );

                return;
            }
        }
    }

    /**
     * For each composition branch that produces a nested object class, adds an internal
     * `_getEvaluatedProperties()` method the enclosing schema's unevaluatedProperties
     * validator queries to learn which keys the nested class evaluated.
     *
     * The method name uses an underscore prefix so it cannot collide with a user-declared
     * schema property called `evaluatedProperties` (whose generated getter would be
     * `getEvaluatedProperties()` without the underscore) and so its internal-only role is
     * visible at the call site.
     */
    private function addGetEvaluatedPropertiesToNestedBranchSchemas(Schema $schema): void
    {
        foreach ($schema->getBaseValidators() as $baseValidator) {
            if (!$baseValidator instanceof AbstractComposedPropertyValidator) {
                continue;
            }

            foreach ($baseValidator->getComposedProperties() as $composedProperty) {
                $nestedSchema = $composedProperty->getNestedSchema();

                if ($nestedSchema === null || $nestedSchema->hasMethod('_getEvaluatedProperties')) {
                    continue;
                }

                $nestedSchema->addMethod(
                    '_getEvaluatedProperties',
                    $this->buildGetEvaluatedPropertiesMethod($nestedSchema),
                );
            }
        }
    }

    /**
     * Builds a MethodInterface that emits `_getEvaluatedProperties()` for the given nested
     * schema. The method returns the union of declared property names present in the instance's
     * raw model data, the keys matched by the schema's own `patternProperties`, and the keys
     * recorded in `_evaluatedPropertyKeys` (populated by the nested schema's own
     * unevaluatedProperties validator). Together these are the keys the branch evaluated, so an
     * enclosing unevaluatedProperties check must credit them.
     */
    private function buildGetEvaluatedPropertiesMethod(Schema $nestedSchema): MethodInterface
    {
        return new class ($nestedSchema) implements MethodInterface {
            public function __construct(private readonly Schema $nestedSchema)
            {
            }

            public function getCode(): string
            {
                $declaredPropertyNames = array_values(array_map(
                    static fn(PropertyInterface $property): string => $property->getName(),
                    array_filter(
                        $this->nestedSchema->getProperties(),
                        static fn(PropertyInterface $property): bool => !$property->isInternal(),
                    ),
                ));

                return sprintf(
                    '
                    #[Internal]
                    public function _getEvaluatedProperties(): array
                    {
                        $evaluated = [];
                        foreach (%s as $propName) {
                            if (array_key_exists($propName, $this->_rawModelDataInput)) {
                                $evaluated[$propName] = true;
                            }
                        }
                        // Keys matched by this schema\'s own patternProperties (with passing
                        // values) are evaluated too, so an enclosing schema must see them. Only the
                        // keys matter (the result is array_keys($evaluated)), so union the maps.
                        if (property_exists($this, "_patternProperties")) {
                            foreach ($this->_patternProperties as $patternMatches) {
                                $evaluated += $patternMatches;
                            }
                        }
                        // Keys evaluated by this schema\'s own unevaluatedProperties: {schema}
                        // validator are tracked so an enclosing schema can see them.
                        if (property_exists($this, "_evaluatedPropertyKeys")) {
                            $evaluated += $this->_evaluatedPropertyKeys;
                        }
                        return array_keys($evaluated);
                    }',
                    var_export($declaredPropertyNames, true),
                );
            }
        };
    }

    /**
     * Returns true when the schema or any reachable subschema contains unevaluatedProperties
     * or unevaluatedItems, meaning composition validators on this schema must emit the
     * _compositionEvaluations cache.
     *
     * Uses a $seen map keyed by file+pointer to break reference cycles.
     */
    private function needsActivation(Schema $schema, array &$seen): bool
    {
        $schemaKey = $schema->getJsonSchema()->getFile() . '#' . $schema->getJsonSchema()->getPointer();

        if (array_key_exists($schemaKey, $seen)) {
            return $seen[$schemaKey];
        }

        // Mark false initially so cycles terminate without infinite recursion.
        $seen[$schemaKey] = false;

        $json = $schema->getJsonSchema()->getJson();

        if (array_key_exists('unevaluatedProperties', $json) || array_key_exists('unevaluatedItems', $json)) {
            return $seen[$schemaKey] = true;
        }

        // Check each schema-level composition: both the branch-level JSON and any nested schema.
        if ($this->compositionValidatorsNeedActivation($schema->getBaseValidators(), $seen)) {
            return $seen[$schemaKey] = true;
        }

        // Check each property: its own JSON, its nested schema, and any composition sitting
        // directly on it. Array properties never carry a nested schema — their unevaluatedItems
        // lives in the property's own JSON or in a composition branch on the property (e.g.
        // {tags: {type: array, allOf: [{unevaluatedItems: {schema}}]}}), so both must be checked.
        foreach ($schema->getProperties() as $schemaProperty) {
            $propertyJson = $schemaProperty->getJsonSchema()->getJson();

            if (
                array_key_exists('unevaluatedProperties', $propertyJson)
                || array_key_exists('unevaluatedItems', $propertyJson)
            ) {
                return $seen[$schemaKey] = true;
            }

            $nestedSchema = $schemaProperty->getNestedSchema();

            if ($nestedSchema !== null && $this->needsActivation($nestedSchema, $seen)) {
                return $seen[$schemaKey] = true;
            }

            if ($this->compositionValidatorsNeedActivation($schemaProperty->getOrderedValidators(), $seen)) {
                return $seen[$schemaKey] = true;
            }
        }

        return $seen[$schemaKey] = false;
    }

    /**
     * True when any composition validator in the given list carries a branch that declares an
     * unevaluated keyword — either in the branch-level JSON, in a nested schema the branch
     * produces, or in a further composition nested inside the branch's own JSON. Shared by the
     * schema-level (base validators) and property-level checks.
     *
     * An object-typed branch always gets its own nested `Schema` (routed through
     * `processSchema()`), so a further composition nested inside it is reached by recursing
     * into that `Schema` via `needsActivation()`. An array-typed branch never gets one, so a
     * branch shaped like `{oneOf: [{unevaluatedItems: ...}]}` — a composition nested directly
     * inside another branch, with no intervening object type — has no `Schema` object for that
     * recursion to reach. Recursing through the branch's wrapped property's own validators
     * instead mirrors how `activateValidatorsInBranch()` already walks this exact structure for
     * the activation step itself. Without this, the branch's own nested validator still renders
     * (schema processing is not gated by this detection), but the class it renders into never
     * receives `CompositionEvaluationTrait` or the `_evaluatedItemIndices` field the rendered
     * code calls into — a fatal `Error: Call to undefined method`, not a silent gap.
     *
     * @param iterable<mixed> $validators
     * @param array<string, bool> $seen
     */
    private function compositionValidatorsNeedActivation(iterable $validators, array &$seen): bool
    {
        foreach ($validators as $validator) {
            if (!$validator instanceof AbstractComposedPropertyValidator) {
                continue;
            }

            foreach ($validator->getComposedProperties() as $composedProperty) {
                $branchJson = $composedProperty->getBranchSchema()->getJson();

                if (
                    array_key_exists('unevaluatedProperties', $branchJson)
                    || array_key_exists('unevaluatedItems', $branchJson)
                ) {
                    return true;
                }

                $nestedSchema = $composedProperty->getNestedSchema();

                if ($nestedSchema !== null) {
                    if ($this->needsActivation($nestedSchema, $seen)) {
                        return true;
                    }

                    continue;
                }

                if (
                    $this->compositionValidatorsNeedActivation(
                        $composedProperty->getWrappedProperty()->getOrderedValidators(),
                        $seen,
                    )
                ) {
                    return true;
                }
            }
        }

        return false;
    }
}
