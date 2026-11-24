<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PropertyConstraint;

use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

/**
 * The schema objects which apply to an instance unconditionally (the schema itself, its allOf branches and the target
 * of a reference), and the anyOf/oneOf compositions of these objects: each composition applies one or more of its
 * branches, so what holds for every branch holds for the instance.
 */
class ConstraintUnit
{
    /**
     * @param JsonSchema[] $nodes
     * @param array<int, array{pointer: string, branches: ConstraintUnit[]}> $groups The pointer of the composition
     *                                                                              keyword and its branches
     */
    public function __construct(public readonly array $nodes, public readonly array $groups)
    {}

    public function merge(self $other): self
    {
        return new self(array_merge($this->nodes, $other->nodes), array_merge($this->groups, $other->groups));
    }
}
