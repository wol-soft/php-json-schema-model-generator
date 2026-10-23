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
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Detects whether unevaluatedProperties or unevaluatedItems is evaluated by a schema's generated
 * class and, when it is, activates evaluation tracking on that schema's composition validators.
 *
 * Activation emits the _compositionEvaluations cache field on the generated class and marks
 * each composition validator with per-branch slot writes. A schema whose class evaluates
 * neither keyword is left untouched; a nested class activates its own tracking when this post
 * processor processes it.
 *
 * The validation of updates (setters, populate(), the additional and unevaluated property
 * accessors) is *not* handled here: the update paths call _executePostCompositionValidators
 * against the candidate state (see SetterValidationRenderer and the populate / accessor update
 * templates), and a rejected update restores the complete model state, including the
 * evaluation caches. The unevaluatedProperties validator participates in that pass simply by
 * being registered as a post-composition validator on the schema; this post processor's only
 * responsibility is the activation-side work that makes the cache available to it.
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
        if (!$this->needsActivation($schema)) {
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
     * input or accept invalid input, so this fails loudly at generation time instead. A general
     * fix was rejected as either unsound (anyOf/oneOf: two sibling branches each gating on the
     * other's claims can have no unique consistent answer at all, not just an expensive one to
     * compute) or out of proportion to a pattern of unknown real-world frequency (allOf, where
     * a fix is sound but non-trivial).
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
            || array_key_exists('prefixItems', $json)
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
     *
     * @return bool Whether any composition was activated, i.e. whether the cache field now exists
     */
    private function activateSchemaLevelTracking(Schema $schema): bool
    {
        $activated = false;
        foreach ($schema->getBaseValidators() as $baseValidator) {
            if ($baseValidator instanceof AbstractComposedPropertyValidator) {
                $baseValidator->enableEvaluationTracking();
                $activated = true;
            }
        }

        if (!$activated) {
            return false;
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

        return true;
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
     * cycle protection, not defensive. Keyed on file+pointer rather than object identity because
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
     *
     * Activation recurses through the branch's own composition branches. A branch class can only
     * answer what it evaluated if its own compositions record their outcomes, and its `process()`
     * pass will not enable that: the walk that decides activation looks for an unevaluated keyword
     * reachable from the schema being processed, and a branch class that merely sits under an
     * enclosing `unevaluatedProperties` has none of its own. Without recursing here, a branch whose
     * body is itself a composition answers from a static name list — crediting names its losing
     * branches happened to declare and missing what its winning ones actually claimed.
     *
     * `hasMethod()` doubles as the already-activated marker, so a branch class shared between
     * several enclosing schemas is set up exactly once.
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

                // Only a branch class that has compositions of its own needs the trait: its own
                // branches call collectEvaluatedProperties() from it when they write their slots.
                // A branch class without compositions would just carry an unused trait and import.
                if ($this->activateSchemaLevelTracking($nestedSchema)) {
                    $nestedSchema->addTrait(CompositionEvaluationTrait::class);
                }

                $this->addGetEvaluatedPropertiesToNestedBranchSchemas($nestedSchema);
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
                $json = $this->nestedSchema->getJsonSchema()->getJson();

                // A non-false additionalProperties claims every key the schema did not otherwise
                // evaluate, so nothing is left to compute — the whole instance is covered. Only a
                // branch that succeeded is ever queried, so its claim definitely applied.
                if (isset($json['additionalProperties']) && $json['additionalProperties'] !== false) {
                    return implode("\n", [
                        '#[Internal]',
                        'public function _getEvaluatedProperties(): array',
                        '{',
                        '    // additionalProperties claims every remaining key.',
                        '    return array_keys($this->_rawModelDataInput);',
                        '}',
                    ]);
                }

                // The schema's own `properties` keyword, not getProperties(): the latter also holds
                // names transferred from anyOf/oneOf/if branches purely so the generated class can
                // expose getters for them, and those are evaluated only when their branch wins —
                // reading them as declared credits a losing branch's properties. Names materialised
                // from `required` land in getProperties() too, and `required` evaluates nothing.
                $declaredPropertyNames = array_values(array_unique(array_merge(
                    array_map(strval(...), array_keys($json['properties'] ?? [])),
                    $this->nestedSchema->getUnconditionallyEvaluatedPropertyNames(),
                )));

                // Both fields are added by post processors, and RenderQueue::execute() runs every
                // process() before the first render(), so by now the nested schema's final field
                // set is known. Emitting a runtime property_exists() guard instead would leave
                // generated code probing for a field the generator already knows is absent.
                $lines = [
                    '#[Internal]',
                    'public function _getEvaluatedProperties(): array',
                    '{',
                    '    $evaluated = [];',
                    '    foreach (' . RenderHelper::varExportArray($declaredPropertyNames) . ' as $declaredName) {',
                    '        if (array_key_exists($declaredName, $this->_rawModelDataInput)) {',
                    '            $evaluated[$declaredName] = true;',
                    '        }',
                    '    }',
                ];

                if ($this->declaresInternalProperty('patternProperties')) {
                    // Keys matched by this schema's own patternProperties (with passing values) are
                    // evaluated too, so an enclosing schema must see them. Only the keys matter
                    // (the result is array_keys($evaluated)), so the maps are unioned.
                    array_push(
                        $lines,
                        '    foreach ($this->_patternProperties as $patternMatches) {',
                        '        $evaluated += $patternMatches;',
                        '    }',
                    );
                }

                if ($this->declaresInternalProperty('evaluatedPropertyKeys')) {
                    // Keys evaluated by this schema's own unevaluatedProperties: {schema} validator
                    // are tracked so an enclosing schema can see them.
                    $lines[] = '    $evaluated += $this->_evaluatedPropertyKeys;';
                }

                array_push($lines, ...$this->renderCompositionContribution());
                array_push($lines, '', '    return array_keys($evaluated);', '}');

                return implode("\n", $lines);
            }

            /**
             * The lines that fold in whatever this schema's own composition branches claimed, read
             * from `_compositionEvaluations` with the slot conventions `collectUnevaluatedKeys()`
             * uses. Only branches that succeeded hold a slot value, so a losing branch's
             * declarations stay out — which no static name list could express.
             *
             * Empty for a schema without compositions: the cache field is not declared there, so
             * the loop would iterate an empty key list to no effect.
             *
             * @return string[]
             */
            private function renderCompositionContribution(): array
            {
                $compositionValidatorKeys = $this->nestedSchema->getCompositionValidatorKeys();

                if ($compositionValidatorKeys === []) {
                    return [];
                }

                return [
                    '    foreach (' . RenderHelper::varExportArray($compositionValidatorKeys)
                        . ' as $validatorIndex) {',
                    '        foreach ($this->_compositionEvaluations[$validatorIndex] ?? [] as $slot) {',
                    '            if ($slot === null) {',
                    '                continue;',
                    '            }',
                    '            if ($slot === true) {',
                    '                return array_keys($this->_rawModelDataInput);',
                    '            }',
                    '            if (is_object($slot)) {',
                    '                foreach ($slot->_getEvaluatedProperties() as $branchPropertyName) {',
                    '                    $evaluated[$branchPropertyName] = true;',
                    '                }',
                    '                continue;',
                    '            }',
                    '            foreach ($slot as $branchPropertyName) {',
                    '                $evaluated[$branchPropertyName] = true;',
                    '            }',
                    '        }',
                    '    }',
                ];
            }

            private function declaresInternalProperty(string $propertyName): bool
            {
                foreach ($this->nestedSchema->getProperties() as $property) {
                    if ($property->isInternal() && $property->getName() === $propertyName) {
                        return true;
                    }
                }

                return false;
            }
        };
    }

    /**
     * Returns true when unevaluatedProperties or unevaluatedItems is evaluated by this schema's own
     * generated class: declared by the schema itself, by one of its properties or by a branch of
     * one of its compositions, meaning composition validators on this schema must emit the
     * _compositionEvaluations cache.
     *
     * A nested schema (the class of an object property or of an object-typed branch) is not
     * searched: it is processed by this post processor on its own and activates its own tracking
     * if the keyword is reachable from it. Searching the nested schemas as well was rejected: a
     * keyword deep inside a nested class activated the tracking of every enclosing class, which
     * no unevaluated keyword of the enclosing classes reads.
     */
    private function needsActivation(Schema $schema): bool
    {
        $json = $schema->getJsonSchema()->getJson();

        if (array_key_exists('unevaluatedProperties', $json) || array_key_exists('unevaluatedItems', $json)) {
            return true;
        }

        if ($this->compositionValidatorsNeedActivation($schema->getBaseValidators())) {
            return true;
        }

        // Check each property: its own JSON and any composition sitting directly on it. Array
        // properties never carry a nested schema — their unevaluatedItems lives in the property's
        // own JSON or in a composition branch on the property (e.g.
        // {tags: {type: array, allOf: [{unevaluatedItems: {schema}}]}}), so both must be checked.
        foreach ($schema->getProperties() as $schemaProperty) {
            $propertyJson = $schemaProperty->getJsonSchema()->getJson();

            if (array_key_exists('unevaluatedItems', $propertyJson)) {
                return true;
            }

            if ($this->compositionValidatorsNeedActivation($schemaProperty->getOrderedValidators())) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when any composition validator in the given list carries a branch that declares an
     * unevaluated keyword — either in the branch-level JSON or in a further composition nested
     * inside the branch's own JSON. Shared by the schema-level (base validators) and
     * property-level checks.
     *
     * An object-typed branch always gets its own nested `Schema` (routed through
     * `processSchema()`), which detects the keywords nested inside it itself (see
     * `needsActivation()`). An array-typed branch never gets one, so a branch shaped like
     * `{oneOf: [{unevaluatedItems: ...}]}` — a composition nested directly inside another branch,
     * with no intervening object type — belongs to this schema's class. Recursing through the
     * branch's wrapped property's own validators mirrors how `activateValidatorsInBranch()`
     * already walks this exact structure for the activation step itself. Without this, the
     * branch's own nested validator still renders (schema processing is not gated by this
     * detection), but the class it renders into never receives `CompositionEvaluationTrait` or
     * the `_evaluatedItemIndices` field the rendered code calls into — a fatal
     * `Error: Call to undefined method`, not a silent gap.
     *
     * @param iterable<mixed> $validators
     */
    private function compositionValidatorsNeedActivation(iterable $validators): bool
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

                if (
                    $composedProperty->getNestedSchema() === null
                    && $this->compositionValidatorsNeedActivation(
                        $composedProperty->getWrappedProperty()->getOrderedValidators(),
                    )
                ) {
                    return true;
                }
            }
        }

        return false;
    }
}
