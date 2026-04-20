<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\Filter;

use PHPModelGenerator\Draft\Draft;
use PHPModelGenerator\Utils\TypeCheck;
use PHPModelGenerator\Utils\TypeConverter;

/**
 * Classifies a single composition branch as Input, Output, Mixed, or Empty with
 * respect to the type-spaces of a transforming filter T: InputTypes → OutputTypes.
 *
 * Classification rules:
 *   - Input  — every constraint targets the filter's input type-space.
 *   - Output — every constraint targets the filter's output type-space.
 *   - Mixed  — constraints span both type-spaces.
 *   - Empty  — the branch imposes no structural constraints (empty schema `{}`).
 *
 * Ambiguous keywords (registered only on the 'any' type, or not registered at all)
 * are treated as non-constraining and do not shift the branch away from a unanimous
 * single-space classification. When ALL keywords in a branch are ambiguous, the
 * liberal policy applies and the branch is classified as Input.
 */
class CompositionBranchClassifier
{
    /**
     * JSON Schema composition keywords that may contain nested branch schemas.
     * Each is classified recursively rather than via the Draft type registry.
     */
    private const NESTED_COMPOSITION_KEYWORDS = ['allOf', 'anyOf', 'oneOf', 'not'];

    /**
     * @param Draft    $draft       The active Draft instance used to resolve keyword type-spaces.
     * @param string[] $inputTypes  PHP type names accepted by the transforming filter
     *                              (e.g. ['string']).
     * @param string[] $outputTypes PHP type names returned by the transforming filter
     *                              (e.g. ['DateTime']).
     */
    public function __construct(
        private readonly Draft $draft,
        private readonly array $inputTypes,
        private readonly array $outputTypes,
    ) {}

    /**
     * Classify a single composition branch schema.
     *
     * @param array<string, mixed> $branchSchema Decoded JSON object for one branch.
     */
    public function classify(array $branchSchema): TypeSpace
    {
        if (empty($branchSchema)) {
            return TypeSpace::Empty;
        }

        $hasInput  = false;
        $hasOutput = false;

        foreach ($branchSchema as $keyword => $value) {
            $contribution = $this->classifyKeyword($keyword, $value);

            if ($contribution === TypeSpace::Mixed) {
                return TypeSpace::Mixed;
            }

            match ($contribution) {
                TypeSpace::Input  => $hasInput  = true,
                TypeSpace::Output => $hasOutput = true,
                default           => null,  // Empty — no constraint, skip
            };
        }

        return match (true) {
            $hasInput && $hasOutput => TypeSpace::Mixed,
            $hasInput               => TypeSpace::Input,
            $hasOutput              => TypeSpace::Output,
            default                 => TypeSpace::Input,  // all ambiguous: liberal policy
        };
    }

    /**
     * Determine the type-space contribution of a single keyword within a branch.
     *
     * Returns TypeSpace::Empty when the keyword carries no spatial information
     * (registered only on 'any', not registered at all, or an unrecognised key).
     */
    private function classifyKeyword(string $keyword, mixed $value): TypeSpace
    {
        if ($keyword === 'type') {
            return $this->resolveTypeSpace(array_map(
                static fn(string $jsonType): string => TypeConverter::jsonSchemaToPHP($jsonType),
                (array) $value,
            ));
        }

        if (in_array($keyword, self::NESTED_COMPOSITION_KEYWORDS, true)) {
            return $this->classifyNestedComposition($keyword, $value);
        }

        // Derive the spatial contribution from the Draft type registry.
        $draftTypeNames = $this->draft->getTypesForKeyword($keyword);

        if (empty($draftTypeNames)) {
            // Keyword not registered in any type (e.g. $schema, title, description).
            return TypeSpace::Empty;
        }

        // Convert JSON Schema type names to PHP type names and discard the 'any'
        // pseudo-type — it is not spatially specific (e.g. enum, const, filter).
        $phpTypeNames = array_values(array_filter(
            array_map(
                static fn(string $draftType): string => TypeConverter::jsonSchemaToPHP($draftType),
                $draftTypeNames,
            ),
            static fn(string $type): bool => $type !== 'any',
        ));

        return empty($phpTypeNames) ? TypeSpace::Empty : $this->resolveTypeSpace($phpTypeNames);
    }

    /**
     * Classify nested composition keywords (allOf, anyOf, oneOf, not) by recursing
     * into their inner branch schemas.
     */
    private function classifyNestedComposition(string $keyword, mixed $value): TypeSpace
    {
        if ($keyword === 'not') {
            return !is_array($value) ? TypeSpace::Empty : $this->classify($value);
        }

        // allOf, anyOf, oneOf: value must be an array of branch schemas.
        if (!is_array($value)) {
            return TypeSpace::Empty;
        }

        $nonEmpty = array_values(array_filter(
            array_map(
                fn(mixed $branch): TypeSpace => is_array($branch)
                    ? $this->classify($branch)
                    : TypeSpace::Empty,
                $value,
            ),
            static fn(TypeSpace $space): bool => $space !== TypeSpace::Empty,
        ));

        if (empty($nonEmpty)) {
            return TypeSpace::Empty;
        }

        $hasMixed  = in_array(TypeSpace::Mixed, $nonEmpty, true);
        $hasInput  = in_array(TypeSpace::Input, $nonEmpty, true);
        $hasOutput = in_array(TypeSpace::Output, $nonEmpty, true);

        return match (true) {
            $hasMixed || ($hasInput && $hasOutput) => TypeSpace::Mixed,
            $hasInput                              => TypeSpace::Input,
            default                                => TypeSpace::Output,
        };
    }

    /**
     * Map a list of PHP type names onto the Input / Output / Mixed / Empty TypeSpace
     * based on whether they overlap with the filter's declared input and output types.
     *
     * @param string[] $phpTypeNames
     */
    private function resolveTypeSpace(array $phpTypeNames): TypeSpace
    {
        $effectiveOutputTypes = $this->getEffectiveOutputTypes();
        $inInput  = !empty(array_intersect($phpTypeNames, $this->inputTypes));
        $inOutput = !empty(array_intersect($phpTypeNames, $effectiveOutputTypes));

        return match (true) {
            $inInput && $inOutput => TypeSpace::Mixed,
            $inInput              => TypeSpace::Input,
            $inOutput             => TypeSpace::Output,
            default               => TypeSpace::Empty,
        };
    }

    /**
     * Expand the declared output types to include 'object' when any output type is a
     * non-primitive class name. This allows object-type Draft keywords (e.g. minProperties,
     * properties) to be classified as output-targeted when the filter returns a class instance.
     *
     * @return string[]
     */
    private function getEffectiveOutputTypes(): array
    {
        return array_values(array_unique(array_merge(
            $this->outputTypes,
            array_map(
                static fn(string $type): string => !TypeCheck::isPrimitive($type) && $type !== 'null'
                    ? 'object'
                    : $type,
                $this->outputTypes,
            ),
        )));
    }
}
