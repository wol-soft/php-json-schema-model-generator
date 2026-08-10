<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\ObjectShape;

use Closure;
use PHPModelGenerator\Draft\Draft;
use PHPModelGenerator\Draft\Producer\ExclusiveProducer;
use PHPModelGenerator\Model\SchemaDefinition\SchemaDefinitionDictionary;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use Throwable;
use TypeError;

/**
 * Statically classifies a raw decoded schema as ObjectAsserting, ObjectDescribing, NotObject, or
 * Undecidable (see ObjectShape for the semantics of each), resolving `$ref` chains through an
 * injected resolver callable so the classification works on definitions, external files, and
 * inline subschemas alike.
 *
 * The classification is deliberately conservative: a mixed-type composition that cannot assert
 * object-ness classifies as NotObject, and a component whose object-ness genuinely cannot be
 * established (unresolvable or cyclic references, schemas owned by other subsystems such as
 * transforming filters) classifies as Undecidable. Both keep the affected schema on its current
 * processing path via ObjectShapeResolver::resolve() (see BranchObjectShape::Blocking and
 * ::Undecidable respectively), but only Undecidable is exempted from
 * SchemaProcessor::checkObjectRepresentability()'s rejection, since a genuinely unknown shape
 * must be left to the subsystem that owns it to report precisely, rather than be reported here
 * as a confident "not an object" verdict.
 *
 * The set of object-describing keywords is derived from the injected Draft's own object Type
 * registrations (see the constructor), so a custom Draft that adds, removes, or renames
 * object-constraining keywords via addValidator()/addModifier() is picked up automatically -
 * with one documented exception: UNREGISTERED_OBJECT_DESCRIBING_KEYWORDS hardcodes 'dependencies'
 * for Draft 7, since that keyword is consumed through a side channel with no Draft-level
 * registration to derive it from. See that constant's docblock for what a custom Draft would
 * need to do differently.
 */
class ObjectShapeResolver
{
    /**
     * 'dependencies' constrains object values (a dependency on a property forces its target to
     * exist) but, unlike every other keyword below, is never registered on the Draft's object
     * Type via addValidator(): PropertiesValidatorFactory and RequiredValidatorFactory each read
     * $json['dependencies'][$propertyName] as a side channel instead (see
     * PropertyDependencyTrait), so it never appears in Type::getModifiers() and must be listed
     * explicitly - the same reason AbstractCompositionValidatorFactory::
     * MODIFIER_ONLY_VALIDATION_KEYWORDS lists keywords addModifier() hides from the registry.
     *
     * This list is Draft-7-specific, unlike the rest of $objectDescribingKeywords, which is
     * derived from the injected Draft and therefore adapts automatically to a custom Draft. A
     * Draft that splits 'dependencies' into 'dependentSchemas'/'dependentRequired' (as later
     * JSON Schema drafts do) - or that reads some other keyword through an equivalent side
     * channel invisible to Type::getModifiers() - would need its own entry here; this constant
     * is not derived from the Draft because PropertyDependencyTrait's side-channel reads have no
     * Draft-level registration to derive it from (see the class docblock on ObjectShapeResolver
     * for why a general mechanism was not built for this: no second Draft exists in this repo to
     * generalize against).
     */
    private const array UNREGISTERED_OBJECT_DESCRIBING_KEYWORDS = ['dependencies'];

    /** @var string[] every keyword whose presence (without a `type` declaration) makes a schema ObjectDescribing */
    private readonly array $objectDescribingKeywords;

    /**
     * Whether the injected Draft ignores keywords sitting next to `$ref` (Draft 07 and earlier)
     * rather than applying them alongside the reference (Draft 2019-09 and later).
     */
    private readonly bool $referenceSuppressesSiblings;

    /**
     * Composition keywords whose branches participate in shape aggregation via uniform per-branch
     * iteration. `not` is deliberately excluded: its subschema describes rejected values, not
     * accepted ones, so it structurally cannot assert a shape for the values a schema accepts.
     * `if`/`then`/`else` is also excluded from this array - not because it is unsupported, but
     * because it is a single triple rather than an array of branch schemas, so it does not fit
     * this array's uniform iteration and is classified separately by classifyIfThenElse().
     */
    private const array COMPOSITION_KEYWORDS = ['allOf', 'anyOf', 'oneOf'];

    /**
     * @param Draft                                    $draft       Used to derive the set of
     *                                                               object-describing keywords
     *                                                               from the object Type's own
     *                                                               registered validators.
     * @param Closure(string): (array|bool|null)|null $refResolver Resolves a `$ref` string to
     *                                                             the raw decoded JSON of its
     *                                                             target, or null when the
     *                                                             reference cannot be resolved.
     *                                                             Without a resolver every
     *                                                             `$ref`-bearing schema
     *                                                             classifies as Undecidable.
     */
    public function __construct(Draft $draft, private readonly ?Closure $refResolver = null)
    {
        $objectType = $draft->hasType('object') ? $draft->getTypes()['object'] : null;
        $registeredKeywords = $objectType !== null ? array_keys($objectType->getModifiers()) : [];

        $this->objectDescribingKeywords = array_merge(
            array_values(array_filter($registeredKeywords, 'is_string')),
            self::UNREGISTERED_OBJECT_DESCRIBING_KEYWORDS,
        );

        // Read the draft's own `$ref` producer rather than hardcoding a sibling policy, so the
        // classification always agrees with what the generator will actually do (see
        // classifyReference()). An ExclusiveProducer means the draft suppresses every keyword
        // sitting next to `$ref`.
        $this->referenceSuppressesSiblings =
            $draft->getProducerForKeyword('$ref') instanceof ExclusiveProducer;
    }

    /**
     * Build a resolver whose `$refResolver` peeks through `$ref` chains via the given schema
     * definition dictionary - a raw, un-processed peek, so no property is created for the target
     * and the classification stays side-effect-free for the common same-file reference case
     * (cross-file references may parse the external file, which the order-independent
     * external-schema machinery would parse moments later anyway).
     */
    public static function forDictionary(
        SchemaProcessor $schemaProcessor,
        SchemaDefinitionDictionary $dictionary,
        Draft $draft,
    ): self {
        $refResolver = static function (string $reference) use ($schemaProcessor, $dictionary): array|bool|null {
            $path = [];

            try {
                $definition = $dictionary->getDefinition($reference, $schemaProcessor, $path);

                return $definition?->getSource()->navigate(implode('/', $path))->getJson();
            } catch (TypeError) {
                // A $ref that resolves to a boolean-valued definition (`true` or `false`) hits
                // JsonSchema::navigate(), which assigns the resolved leaf into a property typed
                // `array` and throws a TypeError for a boolean leaf. Unlike an unresolvable
                // reference, this is NOT undecidable - the target is known and is definitely not
                // representable as an object - so it must keep mapping to
                // BranchObjectShape::Blocking, not ::Undecidable. Returning false (rather than
                // null) achieves that: classify() already treats a literal `false` json value as
                // Blocking via its own is_bool() branch, so this reuses that path instead of
                // adding a second one. The true/false distinction of the actual definition is
                // deliberately not preserved (both collapse to `false` here): classifying `true`
                // faithfully as Neutral was tried and reverted, because it lets generation
                // proceed past checkObjectRepresentability() and then fail deeper in the pipeline
                // with this exact same uncaught TypeError once the real (non-speculative) $ref
                // resolution reaches JsonSchema::navigate() - trading a clean SchemaException for
                // an uncaught TypeError is a regression, not an improvement.
                return false;
            } catch (Throwable) {
                // An unresolvable, malformed, or cyclic reference leaves object-ness genuinely
                // undecidable - the target might turn out to be an object; returning null makes
                // the resolver bail out to BranchObjectShape::Undecidable, keeping the schema on
                // its current processing path and deferring to the real $ref resolution's own,
                // more precise error.
                return null;
            }
        };

        return new self($draft, $refResolver);
    }

    public function resolve(array|bool $json): ObjectShape
    {
        return match ($this->classify($json, [])) {
            BranchObjectShape::Asserting => ObjectShape::ObjectAsserting,
            BranchObjectShape::Describing => ObjectShape::ObjectDescribing,
            BranchObjectShape::Blocking, BranchObjectShape::Neutral => ObjectShape::NotObject,
            BranchObjectShape::Undecidable => ObjectShape::Undecidable,
        };
    }

    /**
     * @param string[] $visitedReferences `$ref` strings on the current resolution path,
     *                                    used to bail out of reference cycles
     */
    private function classify(array|bool $json, array $visitedReferences): BranchObjectShape
    {
        if (is_bool($json)) {
            // true imposes nothing (neutral); false is unsatisfiable, so it must block a
            // sibling object branch from claiming a re-routable object assertion.
            return $json ? BranchObjectShape::Neutral : BranchObjectShape::Blocking;
        }

        // Filter-bearing schemas are owned by the filter-composition subsystem (input/output
        // type-space classification); classifying them as object-shaped would pull them out of
        // that machinery. This is genuinely undecidable rather than a "not an object" verdict -
        // the filter subsystem's own compatibility check runs moments later and reports a
        // precise, correctly-attributed error (e.g. naming the incompatible filter and property
        // type), which SchemaProcessor::checkObjectRepresentability() must not preempt with a
        // generic representability failure.
        if (array_key_exists('filter', $json)) {
            return BranchObjectShape::Undecidable;
        }

        if (array_key_exists('$ref', $json)) {
            return $this->classifyReference($json, $visitedReferences);
        }

        if (array_key_exists('type', $json)) {
            // A multi-type array asserts object-ness only when "object" is its sole listed
            // type - e.g. ["object"] is exactly equivalent to the bare "object" string. Any
            // other multi-type array (even ["object", "null"]) lets a non-object value satisfy
            // this branch, so it must not be treated as Asserting: a sibling allOf branch would
            // then be routed through the object path on the false assumption that every value
            // satisfying the composition is an object, silently mishandling the non-object
            // match (e.g. instantiating null as a nested class) instead of passing it through.
            return (array) $json['type'] === ['object'] ? BranchObjectShape::Asserting : BranchObjectShape::Blocking;
        }

        $componentShapes = [];

        foreach (self::COMPOSITION_KEYWORDS as $compositionKeyword) {
            if (!isset($json[$compositionKeyword]) || !is_array($json[$compositionKeyword])) {
                continue;
            }

            $branchShapes = array_map(
                fn(array|bool $branchJson): BranchObjectShape => $this->classify($branchJson, $visitedReferences),
                $json[$compositionKeyword],
            );

            $componentShapes[] = $compositionKeyword === 'allOf'
                ? $this->combineConjunctive($branchShapes)
                : $this->combineDisjunctive($branchShapes);
        }

        if (isset($json['if'])) {
            $componentShapes[] = $this->classifyIfThenElse($json, $visitedReferences);
        }

        if (array_intersect(array_keys($json), $this->objectDescribingKeywords) !== []) {
            $componentShapes[] = BranchObjectShape::Describing;
        }

        // All constraints of a single schema object apply simultaneously, so multiple
        // components (e.g. describing keywords next to an allOf) combine conjunctively.
        return $componentShapes === [] ? BranchObjectShape::Neutral : $this->combineConjunctive($componentShapes);
    }

    /**
     * Classify an if/then/else conditional. Every instance takes exactly one of two paths -
     * satisfies `if` (then `then` applies) or doesn't (then `else` applies) - so the aggregate
     * only asserts object-ness when BOTH paths do, exactly like combineDisjunctive()'s existing
     * "every branch must assert" rule for anyOf/oneOf applied to these two branches. A missing
     * `then` or `else` imposes no constraint on the instances routed through it (same as a
     * boolean `true` schema), so it classifies as Neutral, which - via combineDisjunctive -
     * degrades the aggregate below Asserting rather than blocking it outright: `if` without full
     * then/else coverage does not guarantee object-ness, but it also does not contradict a
     * sibling component that does.
     */
    private function classifyIfThenElse(array $json, array $visitedReferences): BranchObjectShape
    {
        $thenShape = isset($json['then'])
            ? $this->classify($json['then'], $visitedReferences)
            : BranchObjectShape::Neutral;
        $elseShape = isset($json['else'])
            ? $this->classify($json['else'], $visitedReferences)
            : BranchObjectShape::Neutral;

        return $this->combineDisjunctive([$thenShape, $elseShape]);
    }

    private function classifyReference(array $json, array $visitedReferences): BranchObjectShape
    {
        $reference = $json['$ref'];

        // Unresolvable and cyclic references are undecidable, not decidably non-object: without
        // seeing the target, claiming object-ness (or even neutrality, which would let sibling
        // branches assert it) is unsound - a hidden scalar target would make the aggregate
        // unsatisfiable - but the target might just as well turn out to be an object. The real
        // $ref resolution that runs moments later reports a precise, correctly-attributed error
        // (e.g. naming the missing reference), which
        // SchemaProcessor::checkObjectRepresentability() must not preempt with a generic
        // representability failure.
        if (
            !is_string($reference)
            || $this->refResolver === null
            || in_array($reference, $visitedReferences, true)
        ) {
            return BranchObjectShape::Undecidable;
        }

        $targetJson = ($this->refResolver)($reference);

        if ($targetJson === null) {
            return BranchObjectShape::Undecidable;
        }

        $visitedReferences[] = $reference;
        $targetShape = $this->classify($targetJson, $visitedReferences);

        // Classification has to mirror how the generator will actually process the schema: a
        // classifier that disagreed would reject schemas the generator handles fine, or route ones
        // it cannot. Sibling handling is draft-dependent, so this follows the draft's own `$ref`
        // producer rather than reimplementing the policy.
        //
        // Draft 07 and earlier suppress every keyword next to `$ref` (an ExclusiveProducer), so
        // the target's shape IS the node's shape and siblings must not be merged. Merging them
        // anyway is not a harmlessly stricter reading: `allOf: [{$ref: <object>, type: string}]`
        // generates fine under Draft 07 because the `type` is ignored, and merging it conjunctively
        // would classify the branch Blocking and reject a schema the generator supports.
        //
        // Draft 2019-09 and later apply siblings alongside the reference, so they genuinely
        // constrain the same value and combine conjunctively here.
        if ($this->referenceSuppressesSiblings) {
            return $targetShape;
        }

        $siblingJson = array_diff_key($json, ['$ref' => null]);
        $siblingShape = $this->classify($siblingJson, $visitedReferences);

        return $this->combineConjunctive([$targetShape, $siblingShape]);
    }

    /**
     * Combine shapes that must all hold for the same value (allOf branches, or the components
     * of one schema object): one unsatisfiable-with-object component poisons the aggregate;
     * otherwise a single asserting component makes the whole aggregate object-asserting.
     *
     * Undecidable is checked BEFORE Blocking, even though a Blocking component alone would
     * already be enough to fail the composition. A definite Blocking verdict cannot be derived
     * from an aggregate that also contains a component whose own shape is unknown - the unknown
     * component might resolve to something that changes the picture, and more importantly the
     * subsystem that owns it ($ref resolution, the filter machinery) reports its own precise,
     * correctly-attributed error moments later. Checking Blocking first was considered and
     * rejected: it would let e.g. `allOf: [{type: string}, {$ref: '#/definitions/missing'}]`
     * report the generic "does not resolve to a definite object" message from
     * SchemaProcessor::checkObjectRepresentability() instead of the unresolved-reference error
     * the real $ref resolution produces for the same schema.
     *
     * @param BranchObjectShape[] $shapes
     */
    private function combineConjunctive(array $shapes): BranchObjectShape
    {
        return match (true) {
            in_array(BranchObjectShape::Undecidable, $shapes, true) => BranchObjectShape::Undecidable,
            in_array(BranchObjectShape::Blocking, $shapes, true) => BranchObjectShape::Blocking,
            in_array(BranchObjectShape::Asserting, $shapes, true) => BranchObjectShape::Asserting,
            in_array(BranchObjectShape::Describing, $shapes, true) => BranchObjectShape::Describing,
            default => BranchObjectShape::Neutral,
        };
    }

    /**
     * Combine shapes of alternative branches (anyOf/oneOf): the aggregate only asserts
     * object-ness when EVERY branch does. A neutral branch matches everything and a describing
     * branch is vacuously satisfied by non-objects, so either degrades the aggregate below
     * Asserting. A scalar branch also yields Blocking, but for a different reason than in the
     * conjunctive case: `anyOf: [object, string]` is a perfectly satisfiable union, not a
     * conflict - it is simply not object-asserting. Returning Neutral instead was considered
     * and rejected: it would let an OUTER allOf containing such a mixed union claim
     * object-assertion (semantically sound - the sibling object branch narrows the union - but
     * it would route a cross-typed nested composition through the object path, which is
     * deliberately out of the conservative initial scope).
     *
     * Undecidable is checked BEFORE Blocking here too, for the same reason as in
     * combineConjunctive(): a branch whose own shape is unknown must not let a sibling Blocking
     * branch produce a confident aggregate verdict, since the subsystem that owns the unknown
     * branch reports its own precise error moments later.
     *
     * @param BranchObjectShape[] $shapes
     */
    private function combineDisjunctive(array $shapes): BranchObjectShape
    {
        return match (true) {
            $shapes === [] => BranchObjectShape::Neutral,
            in_array(BranchObjectShape::Undecidable, $shapes, true) => BranchObjectShape::Undecidable,
            in_array(BranchObjectShape::Blocking, $shapes, true) => BranchObjectShape::Blocking,
            in_array(BranchObjectShape::Neutral, $shapes, true) => BranchObjectShape::Neutral,
            in_array(BranchObjectShape::Describing, $shapes, true) => BranchObjectShape::Describing,
            default => BranchObjectShape::Asserting,
        };
    }
}
