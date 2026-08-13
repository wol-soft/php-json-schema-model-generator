<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\ObjectShape;

/**
 * Internal five-valued shape used while aggregating composition branches inside
 * ObjectShapeResolver. The public ObjectShape enum cannot express the difference between a
 * branch that BLOCKS object-assertion (an explicit scalar type - combining it with an object
 * branch in an allOf makes the schema unsatisfiable, so the aggregate must not claim
 * object-ness), a branch that is UNDECIDABLE (object-ness genuinely cannot be determined - an
 * unresolvable or cyclic `$ref`, or a schema owned by another subsystem such as a
 * transforming filter), and a branch that is merely NEUTRAL (a vacuous or annotation-only
 * branch, which imposes nothing and must not prevent the sibling branches from asserting
 * object-ness).
 *
 * Blocking and Undecidable both degrade the aggregate below Asserting, but they are not
 * interchangeable: Undecidable takes precedence over Blocking in both combineConjunctive() and
 * combineDisjunctive(), since a definite "not an object" verdict cannot be derived from a
 * component whose shape is unknown, and the subsystem that owns the undecidable component
 * ($ref resolution, the filter machinery) produces its own precise, correctly-attributed error
 * moments later - see ObjectShapeResolver's combine methods for the full rationale.
 *
 * @internal only ObjectShapeResolver may use this enum; consumers work with ObjectShape.
 */
enum BranchObjectShape
{
    case Asserting;
    case Describing;
    case Blocking;
    case Undecidable;
    case Neutral;
}
