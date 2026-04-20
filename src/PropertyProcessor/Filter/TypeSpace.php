<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\Filter;

/**
 * Classification of a composition branch with respect to a transforming filter's type-spaces.
 *
 * Given a transforming filter T: InputType → OutputType, each composition branch is
 * classified as:
 *
 * - Input  — every constraint in the branch targets the input type-space.
 * - Output — every constraint in the branch targets the output type-space.
 * - Mixed  — the branch contains constraints from both type-spaces (unresolvable for
 *             anyOf/oneOf; allOf can split by branch).
 * - Empty  — the branch imposes no structural constraints (e.g. an empty schema `{}`).
 */
enum TypeSpace
{
    case Input;
    case Output;
    case Mixed;
    case Empty;
}
