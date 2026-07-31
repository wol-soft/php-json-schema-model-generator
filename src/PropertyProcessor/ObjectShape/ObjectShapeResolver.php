<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\ObjectShape;

use Closure;
use PHPModelGenerator\Model\SchemaDefinition\SchemaDefinitionDictionary;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use Throwable;

/**
 * Statically classifies a raw decoded schema as ObjectAsserting, ObjectDescribing, or
 * NotObject (see ObjectShape for the semantics of each), resolving `$ref` chains through an
 * injected resolver callable so the classification works on definitions, external files, and
 * inline subschemas alike.
 *
 * The classification is deliberately conservative: whenever object-ness cannot be established
 * with certainty (unresolvable or cyclic references, mixed-type compositions, schemas owned by
 * other subsystems such as transforming filters), the resolver falls back to NotObject, which
 * keeps the affected schema on its current processing path.
 */
class ObjectShapeResolver
{
    /**
     * Keywords that constrain object values. Their presence without a `type` declaration makes
     * a schema ObjectDescribing - constraining objects while remaining vacuously satisfied by
     * non-object values.
     *
     * TODO: derive this list from the Draft instead of hardcoding it, the same way
     * warnIfVacuousBranch() derives its "is this a real validation keyword" check from
     * Draft::getTypesForKeyword(). Not currently possible: Draft only exposes a per-keyword
     * lookup (which types register a given keyword), not the reverse (which keywords a given
     * type registers), so there is no way to enumerate "every keyword registered on the object
     * Type" without first knowing the full keyword set to probe.
     */
    private const array OBJECT_DESCRIBING_KEYWORDS = [
        'properties',
        'required',
        'patternProperties',
        'additionalProperties',
        'propertyNames',
        'minProperties',
        'maxProperties',
        'dependencies',
    ];

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
     * @param Closure(string): (array|bool|null)|null $refResolver Resolves a `$ref` string to
     *                                                             the raw decoded JSON of its
     *                                                             target, or null when the
     *                                                             reference cannot be resolved.
     *                                                             Without a resolver every
     *                                                             `$ref`-bearing schema
     *                                                             classifies as NotObject.
     */
    public function __construct(private readonly ?Closure $refResolver = null)
    {
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
    ): self {
        $refResolver = static function (string $reference) use ($schemaProcessor, $dictionary): array|bool|null {
            $path = [];

            try {
                $definition = $dictionary->getDefinition($reference, $schemaProcessor, $path);

                return $definition?->getSource()->navigate(implode('/', $path))->getJson();
            } catch (Throwable) {
                // An unresolvable, malformed, or boolean-leaf reference leaves object-ness
                // undecidable; returning null makes the resolver bail out conservatively to
                // NotObject, keeping the schema on its current processing path.
                return null;
            }
        };

        return new self($refResolver);
    }

    public function resolve(array|bool $json): ObjectShape
    {
        return match ($this->classify($json, [])) {
            BranchObjectShape::Asserting => ObjectShape::ObjectAsserting,
            BranchObjectShape::Describing => ObjectShape::ObjectDescribing,
            BranchObjectShape::Blocking, BranchObjectShape::Neutral => ObjectShape::NotObject,
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
        // that machinery, so they block conservatively.
        if (array_key_exists('filter', $json)) {
            return BranchObjectShape::Blocking;
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

        if (array_intersect(array_keys($json), self::OBJECT_DESCRIBING_KEYWORDS) !== []) {
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

        // Unresolvable and cyclic references block: without seeing the target, claiming
        // object-ness (or even neutrality, which would let sibling branches assert it) is
        // unsound - a hidden scalar target would make the aggregate unsatisfiable.
        if (
            !is_string($reference)
            || $this->refResolver === null
            || in_array($reference, $visitedReferences, true)
        ) {
            return BranchObjectShape::Blocking;
        }

        $targetJson = ($this->refResolver)($reference);

        if ($targetJson === null) {
            return BranchObjectShape::Blocking;
        }

        $visitedReferences[] = $reference;
        $targetShape = $this->classify($targetJson, $visitedReferences);

        // Draft 7 ignores keywords next to $ref, but this generator deliberately merges them
        // (the JsonSchema constructor rewrites `{$ref, siblings}` into an allOf of both), so
        // the shape must reflect that merge: target and siblings combine conjunctively.
        $siblingJson = array_diff_key($json, ['$ref' => null]);
        $siblingShape = $this->classify($siblingJson, $visitedReferences);

        return $this->combineConjunctive([$targetShape, $siblingShape]);
    }

    /**
     * Combine shapes that must all hold for the same value (allOf branches, or the components
     * of one schema object): one unsatisfiable-with-object component poisons the aggregate;
     * otherwise a single asserting component makes the whole aggregate object-asserting.
     *
     * @param BranchObjectShape[] $shapes
     */
    private function combineConjunctive(array $shapes): BranchObjectShape
    {
        return match (true) {
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
     * @param BranchObjectShape[] $shapes
     */
    private function combineDisjunctive(array $shapes): BranchObjectShape
    {
        return match (true) {
            $shapes === [] => BranchObjectShape::Neutral,
            in_array(BranchObjectShape::Blocking, $shapes, true) => BranchObjectShape::Blocking,
            in_array(BranchObjectShape::Neutral, $shapes, true) => BranchObjectShape::Neutral,
            in_array(BranchObjectShape::Describing, $shapes, true) => BranchObjectShape::Describing,
            default => BranchObjectShape::Asserting,
        };
    }
}
