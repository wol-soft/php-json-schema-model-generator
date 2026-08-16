<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\SchemaDefinition\ResolvedDefinitionsCollection;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\InstanceOfValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\Utils\RenderHelper;

class CompositionPropertyDecorator extends PropertyProxy
{
    private const string PROPERTY_KEY = 'composition';

    /**
     * Object keywords whose outcome is decided by the shape or the count of the instance's keys
     * rather than by the names listed in `properties`. A branch declaring any of them reacts to
     * keys it never names, so no static name list can describe which mutations affect it.
     */
    private const array UNDECLARED_KEY_SENSITIVE_KEYWORDS = [
        'additionalProperties',
        'patternProperties',
        'unevaluatedProperties',
        'propertyNames',
        'minProperties',
        'maxProperties',
        'dependencies',
        'dependentRequired',
        'dependentSchemas',
    ];

    /**
     * Store all properties from nested schemas of the composed property validator. If the composition validator fails
     * all affected properties must be set to null to adopt only valid values in the base model.
     *
     * @var PropertyInterface[]
     */
    protected $affectedObjectProperties = [];

    private bool $alwaysTrueBranch = false;

    /**
     * CompositionPropertyDecorator constructor.
     *
     * @throws SchemaException
     */
    public function __construct(string $propertyName, JsonSchema $jsonSchema, PropertyInterface $property)
    {
        parent::__construct(
            $propertyName,
            $jsonSchema,
            new ResolvedDefinitionsCollection([self::PROPERTY_KEY => $property]),
            self::PROPERTY_KEY,
        );

        $property->onResolve(function (): void {
            $this->resolve();
        });
    }

    /**
     * Append an object property which is affected by the composition validator
     */
    public function appendAffectedObjectProperty(PropertyInterface $property): void
    {
        $this->affectedObjectProperties[] = $property;
    }

    /**
     * @return PropertyInterface[]
     */
    public function getAffectedObjectProperties(): array
    {
        return $this->affectedObjectProperties;
    }

    public function markAsAlwaysTrueBranch(): void
    {
        $this->alwaysTrueBranch = true;
    }

    public function isAlwaysTrueBranch(): bool
    {
        return $this->alwaysTrueBranch;
    }

    /**
     * Return the branch-level JSON schema (the composition element schema, which may contain
     * additionalProperties constraints). This is distinct from getJsonSchema(), which proxies
     * to the inner wrapped property's schema via PropertyProxy.
     */
    public function getBranchSchema(): JsonSchema
    {
        return $this->jsonSchema;
    }

    /**
     * Return the wrapped property whose validators back this branch. Iterating its
     * validators directly returns the source validator instances, whereas
     * `getOrderedValidators()` on the decorator returns fresh `withProperty(...)` clones on
     * every call. Mutations targeted at clones are invisible at render time; mutations
     * targeted at the wrapped property's source validators propagate.
     */
    public function getWrappedProperty(): PropertyInterface
    {
        return $this->definitionsCollection->offsetGet(self::PROPERTY_KEY);
    }

    /**
     * Returns the property names declared in this branch's `properties` keyword.
     *
     * Used by the composition post processor to harvest names that must invalidate the
     * setter-side validation cache when those properties change.
     *
     * @return string[]
     */
    public function getBranchDeclaredPropertyNames(): array
    {
        return array_keys($this->jsonSchema->getJson()['properties'] ?? []);
    }

    /**
     * Returns the declared property names as a PHP array literal ready for direct template
     * embedding. var_export emits a syntactically-valid PHP literal regardless of which
     * characters the names contain (quotes, backslashes, multibyte sequences).
     */
    public function getBranchDeclaredPropertyNamesPhpLiteral(): string
    {
        return var_export($this->getBranchDeclaredPropertyNames(), true);
    }

    /**
     * Returns the patternProperties regexes as a PHP array literal ready for direct template
     * embedding. Each pattern is wrapped with `/` delimiters and any embedded `/` is escaped so
     * the result can be passed straight to `preg_match`.
     */
    public function getBranchPatternPropertyPatternsPhpLiteral(): string
    {
        return RenderHelper::varExportPcrePatterns(
            array_keys($this->jsonSchema->getJson()['patternProperties'] ?? []),
        );
    }

    /**
     * Returns true if this branch's JSON schema explicitly declares an `additionalProperties`
     * value that is not `false` (i.e., `true` or a schema object).
     *
     * Absent `additionalProperties` returns false: omitting the keyword does not contribute
     * annotation results that unevaluatedProperties checks. When this method returns true,
     * a successful branch is treated as evaluating every model key.
     */
    public function branchHasNonFalseAdditionalProperties(): bool
    {
        $branchJson = $this->jsonSchema->getJson();

        return isset($branchJson['additionalProperties'])
            && $branchJson['additionalProperties'] !== false;
    }

    /**
     * Returns true when a successful branch is treated as evaluating every model key. That holds
     * when the branch declares a non-false `additionalProperties` (claims every extra key) or an
     * `unevaluatedProperties: true` (claims every key the branch did not otherwise evaluate).
     * Either way the whole instance is covered, so the branch's evaluation slot becomes an
     * all-keys-evaluated marker rather than an explicit name list or nested-instance query.
     *
     * `unevaluatedProperties: {schema}` is deliberately excluded: it claims only the remaining
     * keys whose values validate against the subschema, which is instance-dependent and cannot be
     * reduced to an all-keys marker at generation time.
     */
    public function branchClaimsAllKeys(): bool
    {
        return $this->branchHasNonFalseAdditionalProperties()
            || ($this->jsonSchema->getJson()['unevaluatedProperties'] ?? null) === true;
    }

    /**
     * True when a key the branch does not declare in `properties` can still flip the branch's
     * outcome.
     *
     * The setter-side validation cache decides "nothing relevant changed" by intersecting the
     * mutated keys with this branch's declared property names. That test is only sound while
     * every keyword in the branch reacts to names it declares. A branch carrying
     * `additionalProperties`, `patternProperties`, `minProperties`, ... is decided by keys it
     * never names, so the intersection reports "no change" for a mutation that does change the
     * outcome and the branch is wrongly skipped.
     *
     * `required` is deliberately absent from the list even though a required name need not
     * appear in `properties`: the schema processor materialises every required name as a
     * property of the branch's nested schema, so such a name is already part of the declared
     * set the cache tests against. Adding `required` here would disable the cache for a large
     * share of real schemas without closing any gap.
     */
    public function branchEvaluationDependsOnUndeclaredKeys(): bool
    {
        $branchJson = $this->jsonSchema->getJson();

        foreach (self::UNDECLARED_KEY_SENSITIVE_KEYWORDS as $keyword) {
            if (array_key_exists($keyword, $branchJson)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the branch declares `items` as a schema object (not a tuple list, not a
     * boolean). A schema-form `items` claims every index in the validated array.
     */
    public function branchHasItemsSchema(): bool
    {
        $items = $this->jsonSchema->getJson()['items'] ?? null;

        return is_array($items) && $items !== [] && !array_is_list($items);
    }

    /**
     * Count of items in a tuple-form `items` array. Returns 0 when items is absent, boolean,
     * or schema-form. The tuple claims indices 0..count-1 when the branch succeeds.
     */
    public function getBranchTupleItemsCount(): int
    {
        $items = $this->jsonSchema->getJson()['items'] ?? null;

        if (!is_array($items) || $items === [] || !array_is_list($items)) {
            return 0;
        }

        return count($items);
    }

    /**
     * True when the branch declares `additionalItems` as anything other than `false`. The
     * `false` value rejects tail indices; any other value (true or schema object) accepts
     * them and contributes them to the evaluated set.
     */
    public function branchHasNonFalseAdditionalItems(): bool
    {
        $branchJson = $this->jsonSchema->getJson();

        return array_key_exists('additionalItems', $branchJson)
            && $branchJson['additionalItems'] !== false;
    }

    /**
     * True when the branch declares the `contains` keyword. The actual matched indices are
     * collected at runtime by the contains validator into a local accumulator; the composition
     * template unions that accumulator into the branch's evaluated set on success.
     */
    public function branchHasContains(): bool
    {
        return array_key_exists('contains', $this->jsonSchema->getJson());
    }

    /**
     * True when the branch carries at least one array-side applicator (`items`,
     * `additionalItems`, `contains`) and no object-side applicators (`properties`,
     * `additionalProperties`, `patternProperties`). Drives whether the composition template
     * writes the branch's slot into `_compositionAnnotated`.
     */
    public function branchIsArrayKind(): bool
    {
        $branchJson = $this->jsonSchema->getJson();

        $hasArrayApplicator = array_key_exists('items', $branchJson)
            || array_key_exists('additionalItems', $branchJson)
            || array_key_exists('contains', $branchJson);

        $hasObjectApplicator = array_key_exists('properties', $branchJson)
            || array_key_exists('additionalProperties', $branchJson)
            || array_key_exists('patternProperties', $branchJson);

        return $hasArrayApplicator && !$hasObjectApplicator;
    }

    /**
     * Slot keys of composition validators nested directly inside this branch's own JSON (the
     * branch is itself `{allOf: [...]}`, `{oneOf: [...]}`, etc.) that also carry evaluation
     * tracking. An array-typed branch never gets its own nested `Schema` (unlike an
     * object-typed one, which always routes through `processSchema()`), so a composition
     * nested inside it renders as a further validator call on the same wrapped property
     * rather than inside a separate class — its claimed indices are reachable only by reading
     * back `_compositionAnnotated[<slot>]` at runtime, not by recursing into a nested
     * `Schema`'s own composed properties the way an object-typed branch's nesting would allow.
     * The composition template unions each returned slot's tracked indices into this branch's
     * own evaluated set.
     *
     * @return string[]
     */
    public function getNestedCompositionSlotKeys(): array
    {
        $slotKeys = [];

        foreach ($this->getWrappedProperty()->getOrderedValidators() as $validator) {
            if ($validator instanceof AbstractComposedPropertyValidator && $validator->getSlotKey() !== null) {
                $slotKeys[] = $validator->getSlotKey();
            }
        }

        return $slotKeys;
    }

    /**
     * True when the composition template must build an evaluated-index set for this branch —
     * either from the branch's own direct array applicators ({@see branchIsArrayKind()}) or
     * from a composition nested inside the branch ({@see getNestedCompositionSlotKeys()}).
     * Combines both into one boolean because the template engine's conditional expressions do
     * not support parenthesised boolean grouping.
     */
    public function needsEvaluatedIndexAggregation(): bool
    {
        return $this->branchIsArrayKind() || $this->getNestedCompositionSlotKeys() !== [];
    }

    /**
     * @inheritdoc
     *
     * A composition branch must not render validators that only make sense for the property as
     * a whole: RequiredPropertyValidator checks presence of the outer property (already checked
     * once, outside any branch); InstanceOfValidator against an empty-property placeholder class
     * would incorrectly reject any object value that satisfies the branch's actual (unconstrained)
     * semantics.
     *
     * A branch's own nested composition/conditional validator (allOf, anyOf, oneOf, not,
     * if/then/else — see AbstractComposedPropertyValidator) is deliberately NOT excluded here:
     * $nestedSchema is only ever set for a branch whose own schema declares "type": "object"
     * (PropertyFactory::createObjectProperty()), and for any such branch AbstractCompositionValidatorFactory
     * ::shouldSkip() already blocks every composition-keyword factory from attaching a validator
     * to that same branch property in the first place — the object's own composition is instead
     * processed entirely inside its generated nested class. So a branch here never simultaneously
     * has a nested schema and its own composed/conditional validator; the validator, when present,
     * always belongs to a bare (untyped) branch and must be kept and rendered, or the nested
     * composition would silently accept every value (issue #167).
     *
     * Filtering here — at render time, without mutating the wrapped property's own validator
     * list — keeps the exclusion branch-local. The wrapped property may be shared (via
     * PropertyProxy's underlying-property delegation) with a completely different use of the same
     * $ref definition, e.g. as a directly required property elsewhere; that other use must keep
     * its own RequiredPropertyValidator intact.
     */
    public function getOrderedValidators(): array
    {
        $nestedSchema = $this->getNestedSchema();

        return array_values(array_filter(
            parent::getOrderedValidators(),
            static function (PropertyValidatorInterface $validator) use ($nestedSchema): bool {
                if (is_a($validator, RequiredPropertyValidator::class)) {
                    return false;
                }

                return !(
                    is_a($validator, InstanceOfValidator::class)
                    && $nestedSchema !== null
                    && empty($nestedSchema->getProperties())
                );
            },
        ));
    }
}
