<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\Filter;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Checks composition keywords for type-space conflicts with a transforming filter,
 * and detects filter keywords inside composition branches.
 */
class CompositionCompatibilityChecker
{
    private const array ARRAY_COMPOSITION_KEYWORDS = ['allOf', 'anyOf', 'oneOf'];
    private const array SINGLE_COMPOSITION_KEYWORDS = ['not', 'if', 'then', 'else'];

    public function __construct(
        private readonly CompositionBranchClassifier $classifier,
        private readonly PropertyInterface $property,
    ) {}

    /**
     * Validate that composition keywords directly on the filtered property's schema do not
     * produce type-space conflicts with the transforming filter. Enforced rules:
     *   - allOf: no branch may span both type-spaces (Mixed).
     *   - anyOf / oneOf: all branches must share one type-space.
     *   - not: the inner schema must not span both type-spaces (Mixed).
     *   - if/then/else: all three sub-schemas must share one type-space.
     *
     * A SchemaException is thrown for every detected conflict. Ambiguous branches (those
     * that classify as Empty) are not treated as conflicts; liberal-Input policy applies
     * (consistent with CompositionBranchClassifier).
     *
     * @param array<string, mixed> $propertySchema
     *
     * @throws SchemaException
     */
    public function checkTransformingFilterCompositionConflicts(array $propertySchema): void
    {
        $this->checkAllOf($propertySchema['allOf'] ?? null);
        $this->checkAnyOfOrOneOf('anyOf', $propertySchema['anyOf'] ?? null);
        $this->checkAnyOfOrOneOf('oneOf', $propertySchema['oneOf'] ?? null);
        $this->checkNot($propertySchema['not'] ?? null);
        $this->checkIfThenElse($propertySchema);
    }

    /**
     * Validate that root-level composition branches on the parent object schema do not
     * constrain the filtered subproperty with output-type-space constraints.
     *
     * Throws when any root-level composition branch references the filtered subproperty
     * by name via a "properties" constraint whose content targets the output type-space.
     * Ambiguous and input-space constraints are permitted: they operate against the
     * original (pre-transform) value, which is the correct behaviour for root-level
     * composition today.
     *
     * @param array<string, mixed> $parentSchema
     *
     * @throws SchemaException
     */
    public function checkTransformingFilterRootCompositionConflicts(array $parentSchema): void
    {
        $propertyName = $this->property->getName();

        foreach (self::ARRAY_COMPOSITION_KEYWORDS as $keyword) {
            if (!isset($parentSchema[$keyword]) || !is_array($parentSchema[$keyword])) {
                continue;
            }

            foreach ($parentSchema[$keyword] as $index => $branch) {
                if (!is_array($branch) || !isset($branch['properties'][$propertyName])) {
                    continue;
                }

                $innerConstraint = $branch['properties'][$propertyName];

                if (!is_array($innerConstraint)) {
                    continue;
                }

                $space = $this->classifier->classify($innerConstraint);

                if ($space === TypeSpace::Output || $space === TypeSpace::Mixed) {
                    // TODO: proper handling is deferred to a follow-up topic.
                    // Root-level composition branches cannot yet be split around the
                    // filter's transform boundary.
                    throw new SchemaException(
                        sprintf(
                            'Composition %s in file %s constrains filtered subproperty %s'
                                . ' (branch #%d) with output-type-space constraints;'
                                . ' this combination is not yet supported.',
                            $keyword,
                            $this->property->getJsonSchema()->getFile(),
                            $propertyName,
                            $index,
                        ),
                        $this->property->getJsonSchema(),
                    );
                }
            }
        }

        foreach (self::SINGLE_COMPOSITION_KEYWORDS as $keyword) {
            if (
                !isset($parentSchema[$keyword])
                || !is_array($parentSchema[$keyword])
                || !isset($parentSchema[$keyword]['properties'][$propertyName])
            ) {
                continue;
            }

            $innerConstraint = $parentSchema[$keyword]['properties'][$propertyName];

            if (!is_array($innerConstraint)) {
                continue;
            }

            $space = $this->classifier->classify($innerConstraint);

            if ($space === TypeSpace::Output || $space === TypeSpace::Mixed) {
                // TODO: see above.
                throw new SchemaException(
                    sprintf(
                        'Composition %s in file %s constrains filtered subproperty %s'
                            . ' with output-type-space constraints; this combination is not yet supported.',
                        $keyword,
                        $this->property->getJsonSchema()->getFile(),
                        $propertyName,
                    ),
                    $this->property->getJsonSchema(),
                );
            }
        }
    }

    /**
     * @param mixed $branches
     *
     * @throws SchemaException
     */
    private function checkAllOf(mixed $branches): void
    {
        if (!is_array($branches)) {
            return;
        }

        foreach ($branches as $index => $branch) {
            if ($this->classifier->classify($branch) === TypeSpace::Mixed) {
                throw new SchemaException(
                    sprintf(
                        'Composition allOf under property %s in file %s cannot be resolved:'
                            . ' branch #%d spans both input and output type-spaces;'
                            . ' allOf branches must not contain constraints from both type-spaces'
                            . ' when combined with a transforming filter.',
                        $this->property->getName(),
                        $this->property->getJsonSchema()->getFile(),
                        $index,
                    ),
                    $this->property->getJsonSchema(),
                );
            }
        }
    }

    /**
     * @param mixed $branches
     *
     * @throws SchemaException
     */
    private function checkAnyOfOrOneOf(string $keyword, mixed $branches): void
    {
        if (!is_array($branches)) {
            return;
        }

        $firstInputIndex  = null;
        $firstOutputIndex = null;

        foreach ($branches as $index => $branch) {
            $space = $this->classifier->classify($branch);

            if ($space === TypeSpace::Mixed) {
                throw new SchemaException(
                    sprintf(
                        'Composition %s under property %s in file %s cannot be resolved:'
                            . ' branch #%d spans both input and output type-spaces;'
                            . ' branches must not contain constraints from both type-spaces'
                            . ' when combined with a transforming filter.',
                        $keyword,
                        $this->property->getName(),
                        $this->property->getJsonSchema()->getFile(),
                        $index,
                    ),
                    $this->property->getJsonSchema(),
                );
            }

            if ($space === TypeSpace::Input && $firstInputIndex === null) {
                $firstInputIndex = $index;
            }

            if ($space === TypeSpace::Output && $firstOutputIndex === null) {
                $firstOutputIndex = $index;
            }
        }

        if ($firstInputIndex !== null && $firstOutputIndex !== null) {
            throw new SchemaException(
                sprintf(
                    'Composition %s under property %s in file %s cannot be resolved:'
                        . ' branch #%d constrains input type-space but branch #%d constrains output type-space;'
                        . ' %s branches must share a single type-space when combined with a transforming filter.',
                    $keyword,
                    $this->property->getName(),
                    $this->property->getJsonSchema()->getFile(),
                    $firstInputIndex,
                    $firstOutputIndex,
                    $keyword,
                ),
                $this->property->getJsonSchema(),
            );
        }
    }

    /**
     * @throws SchemaException
     */
    private function checkNot(mixed $innerSchema): void
    {
        if (!is_array($innerSchema)) {
            return;
        }

        if ($this->classifier->classify($innerSchema) === TypeSpace::Mixed) {
            throw new SchemaException(
                sprintf(
                    'Composition not under property %s in file %s cannot be resolved:'
                        . ' the inner schema spans both input and output type-spaces.',
                    $this->property->getName(),
                    $this->property->getJsonSchema()->getFile(),
                ),
                $this->property->getJsonSchema(),
            );
        }
    }

    /**
     * @param array<string, mixed> $propertySchema
     *
     * @throws SchemaException
     */
    private function checkIfThenElse(array $propertySchema): void
    {
        if (!isset($propertySchema['if'])) {
            return;
        }

        /** @var array<string, TypeSpace> $subSchemaSpaces */
        $subSchemaSpaces = [];

        foreach (['if', 'then', 'else'] as $subKeyword) {
            if (isset($propertySchema[$subKeyword]) && is_array($propertySchema[$subKeyword])) {
                $subSchemaSpaces[$subKeyword] = $this->classifier->classify($propertySchema[$subKeyword]);
            }
        }

        $hasInput  = in_array(TypeSpace::Input, $subSchemaSpaces, true);
        $hasOutput = in_array(TypeSpace::Output, $subSchemaSpaces, true);
        $hasMixed  = in_array(TypeSpace::Mixed, $subSchemaSpaces, true);

        if ($hasMixed || ($hasInput && $hasOutput)) {
            throw new SchemaException(
                sprintf(
                    'Composition if/then/else under property %s in file %s cannot be resolved:'
                        . ' sub-schemas span different type-spaces;'
                        . ' if/then/else sub-schemas must share a single type-space'
                        . ' when combined with a transforming filter.',
                    $this->property->getName(),
                    $this->property->getJsonSchema()->getFile(),
                ),
                $this->property->getJsonSchema(),
            );
        }
    }

    /**
     * Check whether a single composition branch declares a "filter" keyword which the composition
     * cannot apply: either directly on the branch, or (for a branch which is not object-typed) on a
     * named property below "properties".
     *
     * Object-typed branches create nested schemas whose properties are processed independently of
     * the value reset ComposedItem.phptpl performs after each branch, so the filters of their
     * properties are applied correctly and are not scanned.
     *
     * Nested compositions inside the branch (allOf / anyOf / oneOf / not / if / then / else) are
     * deliberately NOT descended into. Every nested composition is built as a branch property of
     * its own and its composition factory runs this check on its own branches after it has
     * inherited the type of its parent. Descending here as well would apply the check to a nested
     * branch before that inheritance happened: the untyped "then" of a conditional inside an
     * object-typed allOf branch would be taken for a scalar branch and its object properties would
     * be rejected for carrying a filter. Passing the inherited object-ness down manually instead
     * would duplicate the inheritance rules and drift from them.
     *
     * @param array<string, mixed> $branchSchema
     */
    public static function branchContainsFilter(array $branchSchema): bool
    {
        if (array_key_exists('filter', $branchSchema)) {
            return true;
        }

        // Accept both the string form ('object') and the single-element array form (['object']) -
        // type inheritance may inject the parent type as an array.
        $branchType = $branchSchema['type'] ?? null;
        $isObjectTyped = $branchType === 'object'
            || (is_array($branchType) && in_array('object', $branchType, true));

        if (
            !$isObjectTyped
            && isset($branchSchema['properties'])
            && is_array($branchSchema['properties'])
        ) {
            foreach ($branchSchema['properties'] as $propertySchema) {
                if (is_array($propertySchema) && static::branchContainsFilter($propertySchema)) {
                    return true;
                }
            }
        }

        return false;
    }
}
