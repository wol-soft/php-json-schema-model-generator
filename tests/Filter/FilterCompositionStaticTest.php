<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Filter;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for generation-time (static) rejection and acceptance of composition schemas
 * combined with transforming filters.
 *
 * Covers: unresolvable mixed/cross-space branches (allOf, anyOf, oneOf, not, if/then/else),
 * prohibition of filter keywords inside composition branches, root-level composition
 * constraining a filtered sub-property with output-type-space keywords, dead-filter detection
 * (allOf type constraints exclude all inputs accepted by the filter), contradictory allOf type
 * constraints, and the full set of composition schemas that must be accepted without error.
 */
class FilterCompositionStaticTest extends AbstractFilterTestCase
{
    /** @return array<string, array{string, string}> */
    public static function rejectedCompositionProvider(): array
    {
        return [
            // A single allOf branch spans both input-space and output-space keywords; it cannot
            // be placed on either side of the filter boundary without losing one of the constraints.
            'allOf with Mixed branch' => [
                'FilterCompositionAllOfMixedBranch.json',
                '/Composition allOf under property filteredProperty'
                    . '.*branch #0 spans both input and output type-spaces/',
            ],
            // anyOf branches disagree on type-space (one input-space, one output-space); all
            // branches of a non-allOf composition must be uniformly pre- or post-transform.
            'anyOf with cross-space branches' => [
                'FilterCompositionAnyOfCrossSpace.json',
                '/Composition anyOf under property filteredProperty'
                    . '.*branch #0 constrains input type-space but branch #1 constrains output type-space/',
            ],
            // Same as anyOf: oneOf branches cannot span different type-spaces.
            'oneOf with cross-space branches' => [
                'FilterCompositionOneOfCrossSpace.json',
                '/Composition oneOf under property filteredProperty'
                    . '.*branch #0 constrains input type-space but branch #1 constrains output type-space/',
            ],
            // The not inner schema spans both spaces; the type-space classification is ambiguous.
            'not with Mixed inner schema' => [
                'FilterCompositionNotMixed.json',
                '/Composition not under property filteredProperty'
                    . '.*inner schema spans both input and output type-spaces/',
            ],
            // if/then/else sub-schemas span different type-spaces; all three sub-schemas must be
            // uniformly classified so the whole conditional can be placed on one side of the filter.
            'if\/then with cross-space sub-schemas' => [
                'FilterCompositionIfThenElseCrossSpace.json',
                '/Composition if\/then\/else under property filteredProperty.*sub-schemas span different type-spaces/',
            ],
            // A filter keyword inside a composition branch cannot be correctly applied because the
            // ComposedItem template resets $value to the original input after each branch evaluation.
            'filter inside allOf branch (with outer filter)' => [
                'FilterCompositionFilterInBranch.json',
                '/A filter keyword inside a allOf composition branch is not supported'
                    . ' for property filteredProperty.*branch #0/',
            ],
            // Same as above; the rejection applies regardless of whether the property itself also
            // declares an outer filter.
            'filter inside allOf branch (no outer filter)' => [
                'FilterCompositionFilterInBranchNoOuterFilter.json',
                '/A filter keyword inside a allOf composition branch is not supported'
                    . ' for property filteredProperty.*branch #0/',
            ],
            // Root-level allOf constrains the filtered subproperty with output-type-space keywords.
            'root-level allOf constrains filtered subproperty with output-type constraint' => [
                'FilterCompositionRootConstrainsFilteredSubproperty.json',
                '/Composition allOf.*constrains filtered subproperty filteredProperty.*branch #0.*output-type-space/',
            ],
            // Same constraint applies to root-level anyOf.
            'root-level anyOf constrains filtered subproperty with output-type constraint' => [
                'FilterCompositionRootAnyOfConstrainsFilteredSubproperty.json',
                '/Composition anyOf.*constrains filtered subproperty filteredProperty.*branch #0.*output-type-space/',
            ],
            // Same constraint applies to root-level oneOf.
            'root-level oneOf constrains filtered subproperty with output-type constraint' => [
                'FilterCompositionRootOneOfConstrainsFilteredSubproperty.json',
                '/Composition oneOf.*constrains filtered subproperty filteredProperty.*branch #0.*output-type-space/',
            ],
            // Same constraint applies to root-level not.
            'root-level not constrains filtered subproperty with output-type constraint' => [
                'FilterCompositionRootNotConstrainsFilteredSubproperty.json',
                '/Composition not.*constrains filtered subproperty filteredProperty.*output-type-space/',
            ],
            // Same constraint applies to root-level if/then/else.
            'root-level if constrains filtered subproperty with output-type constraint' => [
                'FilterCompositionRootIfConstrainsFilteredSubproperty.json',
                '/Composition if.*constrains filtered subproperty filteredProperty.*output-type-space/',
            ],
            // Filter inside a not branch: same $value-reset issue as for array composition keywords.
            'filter inside not branch' => [
                'FilterCompositionFilterInNotBranch.json',
                '/A filter keyword inside a not composition branch is not supported'
                    . ' for property filteredProperty/',
            ],
            // Filter inside an anyOf branch: same $value-reset issue.
            'filter inside anyOf branch' => [
                'FilterCompositionFilterInAnyOfBranch.json',
                '/A filter keyword inside a anyOf composition branch is not supported'
                    . ' for property filteredProperty.*branch #0/',
            ],
            // Filter inside a oneOf branch: same $value-reset issue.
            'filter inside oneOf branch' => [
                'FilterCompositionFilterInOneOfBranch.json',
                '/A filter keyword inside a oneOf composition branch is not supported'
                    . ' for property filteredProperty.*branch #0/',
            ],
            // Filter inside an if/then/else sub-schema: same $value-reset issue.
            'filter inside if\/then\/else branch' => [
                'FilterCompositionFilterInIfThenElseIfThenElseBranch.json',
                '/A filter keyword inside an if\/then\/else composition branch is not supported'
                    . ' for property filteredProperty.*if sub-schema/',
            ],
            // Filter inside a deeply-nested allOf/anyOf branch: recursive scan must descend.
            'filter inside nested allOf\/anyOf branch' => [
                'FilterCompositionFilterInNestedBranch.json',
                '/A filter keyword inside a allOf composition branch is not supported'
                    . ' for property filteredProperty.*branch #0/',
            ],
            // anyOf branch spanning both input and output type-spaces is ambiguous.
            'anyOf with single Mixed branch' => [
                'FilterCompositionAnyOfMixedBranch.json',
                '/Composition anyOf under property filteredProperty'
                    . '.*branch #0 spans both input and output type-spaces/',
            ],
        ];
    }

    #[DataProvider('rejectedCompositionProvider')]
    public function testUnresolvableCompositionOnTransformingFilterPropertyThrowsSchemaException(
        string $schemaFile,
        string $expectedMessagePattern,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches($expectedMessagePattern);

        $this->generateClassFromFile($schemaFile);
    }

    /** @return array<string, array{string}> */
    public static function acceptedCompositionProvider(): array
    {
        return [
            'allOf with input-only branches'                       => ['FilterCompositionAllOfInputOnly.json'],
            'anyOf with input-only branches'                       => ['FilterCompositionAnyOfInputOnly.json'],
            'oneOf with input-only branches'                       => ['FilterCompositionOneOfInputOnly.json'],
            'if/then/else input-only branches'                     => ['FilterCompositionIfThenElseInputOnly.json'],
            'if/then only (no else) input-only branches'           => ['FilterCompositionIfThenOnlyInputSpace.json'],
            'if/else only (no then) input-only branches'           => ['FilterCompositionIfElseOnlyInputSpace.json'],
            'allOf with empty {} branch'                           => ['FilterCompositionAllOfEmptyBranch.json'],
            'root-level allOf: input-space constraint on filtered subproperty' =>
                ['FilterCompositionRootInputSpaceConstrainsFilteredSubproperty.json'],
            'root-level allOf branch: filter in inherited-object branch property' =>
                ['FilterCompositionRootBranchWithFilterInProperty.json'],
        ];
    }

    #[DataProvider('acceptedCompositionProvider')]
    public function testCompatibleCompositionOnTransformingFilterPropertyGeneratesSuccessfully(
        string $schemaFile,
    ): void {
        // Should not throw — generation must succeed for compatible compositions.
        $this->generateClassFromFile($schemaFile);
        $this->addToAssertionCount(1);
    }

    /**
     * An allOf branch whose 'type' constraint excludes the filter's accepted input types means
     * the filter can never receive any value that passes validation — it is a dead filter.
     */
    public function testDeadFilterViaAllOfTypeConstraintThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/Filter stringToInt on property filteredProperty.*can never be executed'
                . '.*allOf type constraints \(int\) exclude all input types accepted by the filter \(string\)/',
        );

        // allOf requires integer values but stringToInt only accepts strings; no value
        // can pass both the allOf validation and reach the filter.
        $this->generateClassFromFile(
            'FilterCompositionAllOfDeadFilter.json',
            (new GeneratorConfiguration())
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'convertStringToInt'],
                    'stringToInt',
                )),
        );
    }

    /**
     * Contradictory allOf type constraints (no value can satisfy all branches simultaneously)
     * produce an empty intersection. The property-level allOf intersection check fires and
     * throws SchemaException for the type contradiction. The dead-filter check skips on empty
     * intersection and is NOT the source of this exception.
     */
    public function testContradictoryAllOfTypeConstraintsThrowSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/Property 'filteredProperty' is defined with conflicting types in allOf composition branches/",
        );

        // Contradictory branches: integer AND string simultaneously — impossible. The
        // property-level allOf intersection detects the empty intersection and rejects the schema.
        // The dead-filter check skips (empty intersection is handled by type-contradiction logic).
        $this->generateClassFromFile(
            'FilterCompositionAllOfContradictoryTypes.json',
            (new GeneratorConfiguration())
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'convertStringToInt'],
                    'stringToInt',
                )),
        );
    }

    /**
     * An allOf branch with {type: object} makes the dateTime filter unreachable: the filter only
     * accepts strings, but the allOf requires an object — no value can satisfy both constraints.
     */
    public function testObjectOutputTypeConstraintMakingFilterDeadThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/Filter dateTime on property filteredProperty.*can never be executed'
                . '.*allOf type constraints \(object\) exclude/',
        );

        $this->generateClassFromFile('FilterCompositionAllOfObjectBranchOutput.json');
    }
}
