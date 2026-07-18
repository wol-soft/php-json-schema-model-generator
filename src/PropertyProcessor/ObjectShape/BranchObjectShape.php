<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\ObjectShape;

/**
 * Internal four-valued shape used while aggregating composition branches inside
 * ObjectShapeResolver. The public ObjectShape enum cannot express the difference between a
 * branch that BLOCKS object-assertion (an explicit scalar type - combining it with an object
 * branch in an allOf makes the schema unsatisfiable, so the aggregate must not claim
 * object-ness) and a branch that is merely NEUTRAL (a vacuous or annotation-only branch, which
 * imposes nothing and must not prevent the sibling branches from asserting object-ness).
 *
 * @internal only ObjectShapeResolver may use this enum; consumers work with ObjectShape.
 */
enum BranchObjectShape
{
    case Asserting;
    case Describing;
    case Blocking;
    case Neutral;
}
