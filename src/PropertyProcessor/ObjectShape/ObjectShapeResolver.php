<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\ObjectShape;

use Closure;

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
     * Composition keywords whose branches participate in shape aggregation. `not` and
     * `if`/`then`/`else` are deliberately excluded and treated as neutral: `not` never asserts
     * a shape for the accepted values, and a conditional only asserts object-ness when both
     * the then and the else branch do AND the condition covers all inputs - classifying that
     * correctly is not required for any current consumer, so the conservative neutral fallback
     * applies.
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
            // A multi-type declaration (e.g. ["object", "string"]) is not exclusively
            // object-valued and therefore blocks, like any scalar type.
            return $json['type'] === 'object' ? BranchObjectShape::Asserting : BranchObjectShape::Blocking;
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

        if (array_intersect(array_keys($json), self::OBJECT_DESCRIBING_KEYWORDS) !== []) {
            $componentShapes[] = BranchObjectShape::Describing;
        }

        // All constraints of a single schema object apply simultaneously, so multiple
        // components (e.g. describing keywords next to an allOf) combine conjunctively.
        return $componentShapes === [] ? BranchObjectShape::Neutral : $this->combineConjunctive($componentShapes);
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
