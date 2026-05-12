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
    private const array NESTED_COMPOSITION_KEYWORDS = ['allOf', 'anyOf', 'oneOf', 'not'];

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
     * Classify a single schema keyword by looking up which Draft-registered types carry it
     * and mapping those types onto the filter's input / output type-spaces.
     *
     * Composition and structural keywords ('type', 'allOf', 'anyOf', 'oneOf', 'not') are not
     * handled here — call classify() for full branch analysis.  Returns TypeSpace::Empty for
     * keywords registered only on the 'any' pseudo-type (e.g. 'enum', 'filter') or for
     * keywords not registered at all (e.g. '$schema', 'description').
     */
    public function classifySchemaKey(string $key): TypeSpace
    {
        $phpTypeNames = array_values(array_filter(
            array_map(
                static fn(string $draftType): string => TypeConverter::jsonSchemaToPHP($draftType),
                $this->draft->getTypesForKeyword($key),
            ),
            static fn(string $type): bool => $type !== 'any',
        ));

        return empty($phpTypeNames) ? TypeSpace::Empty : $this->resolveTypeSpace($phpTypeNames);
    }

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
            // The 'type' keyword asserts raw JSON value structure.  It must classify
            // against the declared output types directly — without the 'object' expansion
            // from getEffectiveOutputTypes() — so that 'type: object' stays input-space for
            // filters that return a PHP class instance (e.g. DateTime for dateTime filter).
            return $this->classifyAgainstSpaces(
                array_map(
                    static fn(string $jsonType): string => TypeConverter::jsonSchemaToPHP($jsonType),
                    (array) $value,
                ),
                $this->outputTypes,
            );
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
     * Non-primitive class names in the declared output types are expanded to include
     * 'object', so that object-targeted Draft keywords (e.g. minProperties) classify as
     * output-space when the filter returns a class instance.
     *
     * 'int' and 'float' are treated as equivalent for overlap detection (JSON Schema:
     * integer is a subtype of number). Number-typed Draft keywords such as 'minimum' and
     * 'maximum' register under the 'number' → PHP 'float' type, so they must classify as
     * output-space for filters that return 'int', and as input-space for filters that
     * accept 'int'.
     *
     * @param string[] $phpTypeNames
     */
    private function resolveTypeSpace(array $phpTypeNames): TypeSpace
    {
        return $this->classifyAgainstSpaces($phpTypeNames, $this->getEffectiveOutputTypes());
    }

    /**
     * Core classification: map a list of PHP type names onto the Input / Output / Mixed /
     * Empty TypeSpace based on whether they overlap with the filter's input types and the
     * given output type list.
     *
     * @param string[] $phpTypeNames
     * @param string[] $outputTypes
     */
    private function classifyAgainstSpaces(array $phpTypeNames, array $outputTypes): TypeSpace
    {
        $inInput  = !empty(array_intersect($phpTypeNames, $this->expandNumericTypes($this->inputTypes)));
        $inOutput = !empty(array_intersect($phpTypeNames, $this->expandNumericTypes($outputTypes)));

        return match (true) {
            $inInput && $inOutput => TypeSpace::Mixed,
            $inInput              => TypeSpace::Input,
            $inOutput             => TypeSpace::Output,
            default               => TypeSpace::Empty,
        };
    }

    /**
     * Expand a set of PHP type names so that 'int' and 'float' are treated as
     * interchangeable for overlap detection.
     *
     * JSON Schema defines integer as a subtype of number. In the Draft type registry
     * 'number' maps to PHP 'float', so number-typed keywords (minimum, maximum, …)
     * carry 'float' as their type name. A filter that returns 'int' should still make
     * those keywords classify as output-space; a filter that accepts 'int' should make
     * them classify as input-space.
     *
     * @param string[] $types
     * @return string[]
     */
    private function expandNumericTypes(array $types): array
    {
        $hasInt   = in_array('int', $types, true);
        $hasFloat = in_array('float', $types, true);

        if ($hasInt && !$hasFloat) {
            $types[] = 'float';
        } elseif ($hasFloat && !$hasInt) {
            $types[] = 'int';
        }

        return $types;
    }

    /**
     * Expand the declared output types to include 'object' when any output type is a
     * non-primitive class name. This allows object-type Draft keywords (e.g. minProperties,
     * properties) to be classified as output-targeted when the filter returns a class instance.
     *
     * The 'type' keyword is deliberately excluded from this expansion: it validates raw JSON
     * value structure and must classify against the declared output types only (see
     * classifyKeyword). This method is used only for non-'type' structural keywords.
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
