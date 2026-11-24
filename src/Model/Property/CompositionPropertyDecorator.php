<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Property;

use PHPModelGenerator\Draft\Draft;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\SchemaDefinition\ResolvedDefinitionsCollection;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\AbstractUnevaluatedItemsValidator;
use PHPModelGenerator\Model\Validator\AdditionalItemsValidator;
use PHPModelGenerator\Model\Validator\ArrayContainsValidator;
use PHPModelGenerator\Model\Validator\ArrayItemValidator;
use PHPModelGenerator\Model\Validator\ArrayTupleValidator;
use PHPModelGenerator\Model\Validator\DependencyValidatorInterface;
use PHPModelGenerator\Model\Validator\InstanceOfValidator;
use PHPModelGenerator\Model\Validator\ObjectUpdateDependency;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\Model\Validator\TypeCheckInterface;
use PHPModelGenerator\Utils\ArrayItemFilterScope;
use PHPModelGenerator\Utils\ArrayItemsCoverage;
use PHPModelGenerator\Utils\EvaluatedItemsScope;
use PHPModelGenerator\Utils\RenderHelper;

class CompositionPropertyDecorator extends PropertyProxy
{
    private const string PROPERTY_KEY = 'composition';

    /**
     * Store all properties from nested schemas of the composed property validator. If the composition validator fails
     * all affected properties must be set to null to adopt only valid values in the base model.
     *
     * @var PropertyInterface[]
     */
    protected $affectedObjectProperties = [];

    private bool $alwaysTrueBranch = false;

    private ?bool $filtersArrayItems = null;

    /**
     * @param Draft $draft The draft the branch is evaluated under; decides which keywords of the
     *                     branch's JSON mean anything (`prefixItems` is only a tuple from Draft 2020-12 on)
     * @param array<int, array<string, mixed>> $referencedBranchJsons The JSON of the definitions the branch references
     *                                                               (see getBranchJsons())
     *
     * @throws SchemaException
     */
    public function __construct(
        string $propertyName,
        JsonSchema $jsonSchema,
        PropertyInterface $property,
        private readonly Draft $draft,
        private readonly array $referencedBranchJsons = [],
    ) {
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

    /**
     * How the result of the branch may change with an update of the key, or with an update of any key the branch
     * doesn't declare as property if no key is given (see ObjectUpdateDependency). A key the branch declares is
     * validated with its value. Otherwise the validators of the nested schema decide: its object-level validators
     * (maxProperties, additionalProperties - also implicitly denied via the generator configuration -,
     * patternProperties, propertyNames, a nested composition, unevaluatedProperties, ...) and the dependencies of its
     * properties. The enum, the const and the filter of the branch are validators of the wrapped property, not of the
     * nested schema: they compare or transform the provided data as a whole, any key may change their result. A true
     * branch has no validators, no update can change its result. Any other branch without a nested schema depends on
     * the value of every key, its validators are unknown.
     *
     * Deciding by a whitelist of the keywords in the branch JSON was rejected: a $ref branch only contains the
     * reference and additionalProperties may be denied by the configuration without any keyword in the schema. The
     * validators of the generated nested schema cover both.
     */
    public function getObjectUpdateDependency(?string $key): ObjectUpdateDependency
    {
        // Checked before the nested schema: a true branch has none, treating it as a branch with unknown validators
        // made every setter of the model validate the composition.
        if ($this->alwaysTrueBranch) {
            return ObjectUpdateDependency::None;
        }

        $nestedSchema = $this->getNestedSchema();

        if ($nestedSchema === null) {
            return ObjectUpdateDependency::Value;
        }

        // The post-composition validators (unevaluatedProperties) of the branch validate the keys the branch doesn't
        // evaluate, they depend on every key.
        $dependency = ObjectUpdateDependency::ofValidators(
            [
                ...$nestedSchema->getBaseValidators(),
                ...$nestedSchema->getPostCompositionValidators(),
                ...$this->getValidatorsOfTheWholeValue(),
            ],
            $key,
        );

        foreach ($nestedSchema->getProperties() as $property) {
            if ($property->isInternal()) {
                continue;
            }

            if ($property->getName() === $key) {
                return ObjectUpdateDependency::Value;
            }

            foreach ($property->getOrderedValidators() as $validator) {
                if ($validator instanceof DependencyValidatorInterface) {
                    $dependency = $dependency->strongest(ObjectUpdateDependency::ofValidator($validator, $key));
                }
            }
        }

        return $dependency;
    }

    /**
     * The validators of the wrapped property which validate the provided data as a whole (enum, const, filters). The
     * type check and the instance check only look at the kind of the value: no update of a key can change them.
     * Listing the validators which compare the data (EnumValidator, the const validator, FilterValidator) was rejected:
     * the const validator is a plain PropertyValidator which can't be told apart by its class, and a validator which
     * isn't known here must depend on every key like any validator which doesn't describe its dependency.
     *
     * @return PropertyValidatorInterface[]
     */
    private function getValidatorsOfTheWholeValue(): array
    {
        $validators = [];

        foreach ($this->getWrappedProperty()->getValidators() as $wrapper) {
            $validator = $wrapper->getValidator();

            if (!$validator instanceof TypeCheckInterface && !$validator instanceof InstanceOfValidator) {
                $validators[] = $validator;
            }
        }

        return $validators;
    }

    /**
     * Whether the value of a key the branch doesn't declare may change the result: the branch must be evaluated again
     * with every update, the cached result can't be used.
     */
    public function dependsOnUndeclaredValues(): bool
    {
        return $this->getObjectUpdateDependency(null) === ObjectUpdateDependency::Value;
    }

    /**
     * Whether adding a key the branch doesn't declare may change the result: the cached result is only used as long
     * as no key is added.
     */
    public function dependsOnAddedKeys(): bool
    {
        return $this->getObjectUpdateDependency(null) === ObjectUpdateDependency::AddedKey;
    }

    /**
     * Whether the items of the array are transformed by a filter which is declared by this branch or by a composition
     * nested in this branch. The transformed items are only part of the result of the branch: the composition has to
     * adopt them explicitly.
     *
     * The filters of the items of nested arrays count as well. Only looking at the filter of the items themselves was
     * rejected: a branch `{items: {type: array, items: {filter}}}` transforms the items one level deeper and would be
     * treated as plain, its transformed items would be dropped unless it is the first successful branch.
     *
     * Evaluated on first use (when the composition is rendered): recursive references are resolved after the branch
     * is created.
     */
    public function filtersArrayItems(): bool
    {
        return $this->filtersArrayItems ??= ArrayItemFilterScope::filtersItemsOnAnyLevel($this);
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
     * The property names declared in this branch's `properties` keyword, which a successful branch evaluates
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
     * True when a successful branch evaluates every index of the validated array: `items` as a
     * schema object, an explicit `items: true`, an empty `items` (`{}`), an explicit
     * `unevaluatedItems: true`, or a tuple with a trailing keyword other than `false` next to it
     * in the same schema object (`additionalItems` behind a tuple `items`, `items` behind a
     * `prefixItems` tuple: the tuple evaluates the leading indices, the trailing keyword every index
     * behind them). Each validates trivially or per index and annotates the whole array; only an
     * omitted keyword produces no annotation.
     *
     * Every schema object of the branch (the branch, the definitions it references) is judged on
     * its own: a trailing keyword only has an effect behind a tuple of its own schema object, a lone
     * `additionalItems` claims nothing. Pooling the keywords of the objects was rejected: the
     * additionalItems of a definition would extend the tuple written next to the reference and
     * credit items nothing evaluates. The tuple and its trailing keyword are read through
     * ArrayItemsCoverage, which the sibling unevaluatedItems validators ask as well: the same JSON
     * means the same thing on the property and inside one of its branches.
     *
     * An empty PHP array is treated as the empty schema `{}`, never as an empty tuple `[]`:
     * json_decode(..., true) maps both to the same value, and the sibling-applicator path
     * (UnevaluatedItemsValidatorFactory) makes the same call. Reading it as an empty tuple would
     * credit nothing and over-reject a branch the author wrote as `items: {}`.
     */
    public function branchClaimsAllIndices(): bool
    {
        foreach ($this->getBranchJsons() as $branchJson) {
            if (
                ($branchJson['unevaluatedItems'] ?? null) === true
                || ArrayItemsCoverage::fromJson($branchJson, $this->draft)->claimsEveryIndex()
            ) {
                return true;
            }
        }

        // An AdditionalItemsValidator (and the TrailingItemsValidator of a prefixItems tuple) only
        // exists next to the tuple of its own schema object (see ItemsValidatorFactory), so it
        // claims the indices behind that tuple.
        return $this->getBranchValidatorsOfType(ArrayItemValidator::class) !== []
            || $this->getBranchValidatorsOfType(AdditionalItemsValidator::class) !== [];
    }

    /**
     * Count of items in a tuple (`items` as a list before Draft 2020-12, `prefixItems` from Draft
     * 2020-12 on), the longest one of the schema objects of the branch. Returns 0 without a tuple.
     * The tuple claims indices 0..count-1 when the branch succeeds.
     */
    public function getBranchTupleItemsCount(): int
    {
        $tupleItemsCount = 0;

        foreach ($this->getBranchJsons() as $branchJson) {
            $tupleItemsCount = max(
                $tupleItemsCount,
                ArrayItemsCoverage::fromJson($branchJson, $this->draft)->getTupleLength(),
            );
        }

        foreach ($this->getBranchValidatorsOfType(ArrayTupleValidator::class) as $tupleValidator) {
            $tupleItemsCount = max($tupleItemsCount, count($tupleValidator->getTupleProperties()));
        }

        return $tupleItemsCount;
    }

    /**
     * True when the branch has a `contains` whose matched items count as evaluated (Draft 2020-12 on, see
     * ArrayContainsValidator::evaluatesItems()). The actual matched indices are collected at runtime by the contains
     * validator into a local accumulator; the composition template unions that accumulator into the branch's
     * evaluated set on success.
     *
     * Asks the validators only, not the JSON of the branch: whether the keyword evaluates items depends on the draft,
     * which the validator knows. Every `contains` of the branch or of a definition it references has a validator, but
     * `contains: false`, which matches nothing.
     */
    public function branchHasContains(): bool
    {
        foreach ($this->getBranchValidatorsOfType(ArrayContainsValidator::class) as $containsValidator) {
            if ($containsValidator->evaluatesItems()) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the branch carries at least one array-side applicator (`items`, `prefixItems`
     * where the draft registers it, `additionalItems`, `contains`, or an `unevaluatedItems: true`
     * annotation) and no object-side applicators (`properties`, `additionalProperties`,
     * `patternProperties`). Drives whether the composition template writes the branch's slot into
     * `_compositionAnnotated`.
     */
    public function branchIsArrayKind(): bool
    {
        $hasArrayApplicator = $this->getBranchTupleItemsCount() > 0
            || $this->branchClaimsAllIndices()
            || $this->branchHasContains();
        $hasObjectApplicator = false;
        $positionalKeywords = ArrayItemsCoverage::positionalKeywords($this->draft);

        foreach ($this->getBranchJsons() as $branchJson) {
            $hasArrayApplicator = $hasArrayApplicator
                || array_intersect($positionalKeywords, array_keys($branchJson)) !== [];

            $hasObjectApplicator = $hasObjectApplicator
                || array_key_exists('properties', $branchJson)
                || array_key_exists('additionalProperties', $branchJson)
                || array_key_exists('patternProperties', $branchJson);
        }

        return $hasArrayApplicator && !$hasObjectApplicator;
    }

    /**
     * The JSON of the branch and, if the branch references a definition, the JSON of the definition (of every
     * definition along a chain of references): the JSON of the branch is the reference with the keywords next to it.
     *
     * Reading the keywords of a referenced definition from the validators of the wrapped property only was rejected: a
     * keyword which accepts every item (`items: true`, `additionalItems: true`) creates no validator, a branch
     * referencing such a definition credited none of the items it evaluates. Reading the JSON of the wrapped property
     * was rejected as well: a reference next to other keywords (the type a branch inherits) is merged into a property
     * built from those keywords, whose JSON doesn't show the definition.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getBranchJsons(): array
    {
        return [$this->jsonSchema->getJson(), ...$this->referencedBranchJsons];
    }

    /**
     * The validators of the wrapped property of the given type. A branch which references a definition carries the
     * array keywords of the definition (contains, items, ...) as validators of its property. They complement the JSON
     * of the definitions (see getBranchJsons()) for keywords which reach the definition through a composition of it.
     *
     * @template T of PropertyValidatorInterface
     *
     * @param class-string<T> $validatorClass
     *
     * @return T[]
     */
    private function getBranchValidatorsOfType(string $validatorClass): array
    {
        $validators = [];

        foreach ($this->getWrappedProperty()->getValidators() as $propertyValidator) {
            if ($propertyValidator->getValidator() instanceof $validatorClass) {
                $validators[] = $propertyValidator->getValidator();
            }
        }

        return $validators;
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
     * The PHP literal of the key under which the unevaluatedItems keyword of this branch records the indices it
     * evaluated, null if the branch has no unevaluatedItems validator of its own (the keyword may as well be declared
     * by the definition the branch references). The key is the name of the array property the validator was created
     * for, the key the contains of the branch credits its matches to as well.
     */
    public function getEvaluatedItemsKey(): ?string
    {
        foreach ($this->getWrappedProperty()->getValidators() as $propertyValidator) {
            $validator = $propertyValidator->getValidator();

            if ($validator instanceof AbstractUnevaluatedItemsValidator) {
                return var_export($validator->getArrayPropertyName(), true);
            }
        }

        return null;
    }

    /**
     * Whether the validation of the branch reads or records evaluated indices: the branch, a definition it references
     * or a composition nested in it carries an unevaluatedItems keyword.
     *
     * Such a branch is validated in an evaluation scope of its own, see ComposedItem.phptpl: it starts without any
     * evaluated index, and the indices it recorded are only handed to the enclosing array if the branch and the
     * composition as a whole succeed. Scoping only a branch with an unevaluatedItems keyword of its own was rejected:
     * a composition nested in a branch hands its indices over when it succeeds, a branch which fails afterwards
     * (another keyword of the branch rejects the value) would leave them behind for the enclosing array.
     */
    public function tracksEvaluatedItems(): bool
    {
        return EvaluatedItemsScope::isTracked($this->getWrappedProperty());
    }

    /**
     * True when the composition template must build an evaluated-index set for this branch —
     * either from the branch's own direct array applicators ({@see branchIsArrayKind()}), from
     * a composition nested inside the branch ({@see getNestedCompositionSlotKeys()}) or from the
     * unevaluatedItems keyword of the branch itself ({@see getEvaluatedItemsKey()}).
     * Combines all of them into one boolean because the template engine's conditional expressions
     * do not support parenthesised boolean grouping.
     */
    public function needsEvaluatedIndexAggregation(): bool
    {
        return $this->branchIsArrayKind()
            || $this->getNestedCompositionSlotKeys() !== []
            || $this->getEvaluatedItemsKey() !== null;
    }

    /**
     * @inheritdoc
     *
     * A composition branch must not render an InstanceOfValidator against an empty-property
     * placeholder class: it would incorrectly reject any object value that satisfies the branch's
     * actual (unconstrained) semantics.
     *
     * No RequiredPropertyValidator needs to be excluded: the 'required' keyword attaches its
     * validator to the property owned by the requiring schema (Schema::getOwnedProperty()), never
     * to the property recycled for every reference to a $ref definition, so a branch referencing
     * that definition (through PropertyProxy) cannot receive the required check of another
     * schema. Excluding it here instead was rejected: it would hide a leaked validator in the
     * other classes rendering the recycled property.
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
     * composition would silently accept every value.
     *
     * Filtering here — at render time, without mutating the wrapped property's own validator
     * list — keeps the exclusion branch-local: the wrapped property may be shared (via
     * PropertyProxy's underlying-property delegation) with a completely different use of the same
     * $ref definition.
     */
    public function getOrderedValidators(): array
    {
        $nestedSchema = $this->getNestedSchema();

        return array_values(array_filter(
            parent::getOrderedValidators(),
            static function (PropertyValidatorInterface $validator) use ($nestedSchema): bool {
                return !(
                    is_a($validator, InstanceOfValidator::class)
                    && $nestedSchema !== null
                    && empty($nestedSchema->getProperties())
                );
            },
        ));
    }
}
