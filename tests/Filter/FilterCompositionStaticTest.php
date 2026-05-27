<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Filter;

use PHPModelGenerator\Draft\Draft_07;
use PHPModelGenerator\Draft\DraftFactoryInterface;
use PHPModelGenerator\Draft\DraftInterface;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
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
            // JSON Schema silently ignores then/else without a matching if, but the checker
            // still parses them and must reject output-type-space constraints.
            'root-level then constrains filtered subproperty with output-type constraint' => [
                'FilterCompositionRootThenConstrainsFilteredSubproperty.json',
                '/Composition then.*constrains filtered subproperty filteredProperty.*output-type-space/',
            ],
            'root-level else constrains filtered subproperty with output-type constraint' => [
                'FilterCompositionRootElseConstrainsFilteredSubproperty.json',
                '/Composition else.*constrains filtered subproperty filteredProperty.*output-type-space/',
            ],
            // Filter inside an if sub-schema within an allOf branch: the SINGLE_COMPOSITION_KEYWORDS
            // loop in branchContainsFilter must descend into if and detect the filter keyword.
            'filter inside if sub-schema within allOf branch' => [
                'FilterCompositionFilterInNestedIfBranch.json',
                '/A filter keyword inside a allOf composition branch is not supported'
                    . ' for property filteredProperty.*branch #0/',
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
            // Filter inside a not sub-schema within an allOf branch: the recursive scan for
            // SINGLE_COMPOSITION_KEYWORDS descends into not and finds the filter keyword.
            'filter inside not sub-schema within allOf branch' => [
                'FilterCompositionFilterInNestedNotBranch.json',
                '/A filter keyword inside a allOf composition branch is not supported'
                    . ' for property filteredProperty.*branch #0/',
            ],
            // anyOf branch spanning both input and output type-spaces is ambiguous.
            'anyOf with single Mixed branch' => [
                'FilterCompositionAnyOfMixedBranch.json',
                '/Composition anyOf under property filteredProperty'
                    . '.*branch #0 spans both input and output type-spaces/',
            ],
            // A non-object-typed allOf branch has a properties map containing a nested filter keyword.
            // branchContainsFilter scans properties of non-object branches and detects the inner filter.
            'filter in properties of non-object-typed allOf branch' => [
                'FilterCompositionFilterInNonObjectBranchProperty.json',
                '/A filter keyword inside a allOf composition branch is not supported'
                    . ' for property filteredProperty.*branch #0/',
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
            // All allOf branches constrain only the input type-space (e.g. minLength, pattern).
            // These run pre-transform and do not conflict with the filter boundary.
            'allOf with input-only branches'                       => ['FilterCompositionAllOfInputOnly.json'],
            // All anyOf branches constrain only the input type-space.
            'anyOf with input-only branches'                       => ['FilterCompositionAnyOfInputOnly.json'],
            // All oneOf branches constrain only the input type-space.
            'oneOf with input-only branches'                       => ['FilterCompositionOneOfInputOnly.json'],
            // All if/then/else sub-schemas constrain only the input type-space.
            'if/then/else input-only branches'                     => ['FilterCompositionIfThenElseInputOnly.json'],
            // A conditional with only if+then (no else) where both sub-schemas are input-space.
            'if/then only (no else) input-only branches'           => ['FilterCompositionIfThenOnlyInputSpace.json'],
            // A conditional with only if+else (no then) where both sub-schemas are input-space.
            'if/else only (no then) input-only branches'           => ['FilterCompositionIfElseOnlyInputSpace.json'],
            // An allOf branch that is an empty object ({}) has no keywords and classifies as
            // TypeSpace::Empty, which does not conflict with either type-space boundary.
            'allOf with empty {} branch'                           => ['FilterCompositionAllOfEmptyBranch.json'],
            // A root-level allOf constrains the filtered sub-property with an input-space keyword
            // (minLength). This is accepted because input-space constraints target the raw value.
            'root-level allOf: input-space constraint on filtered subproperty' =>
                ['FilterCompositionRootInputSpaceConstrainsFilteredSubproperty.json'],
            // Root-level not with an input-space keyword (minLength) is accepted. minLength targets
            // the raw string input before transformation — no output-type-space conflict.
            'root-level not: input-space constraint on filtered subproperty' =>
                ['FilterCompositionRootNotInputSpaceConstrainsFilteredSubproperty.json'],
            // A root-level allOf branch introduces an inherited-object property that itself
            // declares a filter. The filter is on a nested property, not on the composition
            // branch directly, so no filter-in-branch rejection fires.
            'root-level allOf branch: filter in inherited-object branch property' =>
                ['FilterCompositionRootBranchWithFilterInProperty.json'],
            // anyOf branch typed as object via the array form (["object"]) is correctly
            // identified as object-typed. Its properties are not scanned for filter keywords,
            // so the inner trim filter does not trigger a filter-in-branch rejection.
            'anyOf with object branch using array-form type with inner filter in properties' =>
                ['FilterCompositionObjectBranchArrayTypeForm.json'],
            // A branch that itself contains a nested allOf with all output-space constraints
            // classifies the branch as TypeSpace::Output. Both branches here are output-space
            // (object-typed, post-dateTime-filter), so the anyOf is accepted without error.
            'anyOf with nested all-output allOf branch (output-space composition)' =>
                ['FilterCompositionNestedAllOfOutputSpace.json'],
            // A root-level allOf branch that does not reference filteredProperty at all is skipped
            // by checkTransformingFilterRootCompositionConflicts (no properties[filteredProperty]).
            'root-level allOf branch without filteredProperty is skipped' =>
                ['FilterCompositionRootAllOfBranchMissingProperty.json'],
            // A root-level allOf branch where filteredProperty is constrained by schema false (a boolean
            // schema) instead of an object schema. Boolean schemas carry no type-space keywords, so the
            // checker skips the branch without throwing.
            'root-level allOf branch with boolean false constraint on filtered subproperty' =>
                ['FilterCompositionRootAllOfBooleanConstraint.json'],
            // Root-level if schema where filteredProperty is constrained by schema false (a boolean schema).
            // Same reasoning as for allOf: boolean schemas carry no type-space keywords; checker skips.
            'root-level if with boolean false constraint on filtered subproperty' =>
                ['FilterCompositionRootIfBooleanConstraint.json'],
            // allOf branch sets the parent type to string via synchronous transferPropertyType.
            // if/then/else branches have no type keyword so getBranchTypeNames returns null —
            // they cannot conflict with the parent type and the schema is accepted without error.
            'allOf-derived parent type with if\/then\/else untyped branches' =>
                ['FilterCompositionAllOfThenElseUntypedBranches.json'],
            // allOf with {type:integer} and {type:number}: TypeIntersection treats int as a subtype
            // of float so the intersection is non-empty (no contradiction), but array_intersect of
            // the raw PHP type names ['int'] and ['float'] is empty, causing detectDeadFilterViaAllOfConstraints
            // to return early without throwing (effective types empty means no dead-filter conclusion).
            'allOf with integer and number branches alongside dateTime filter' =>
                ['FilterCompositionAllOfIntegerNumberBranches.json'],
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
     * When multiple allOf branches all declare type constraints, the effective input type
     * is the intersection across all branch type sets. The foreach in
     * detectDeadFilterViaAllOfConstraints runs for every branch after the first. Two
     * integer branches intersect to integer, which the string-accepting stringToInt filter
     * cannot handle — dead-filter SchemaException is thrown.
     */
    public function testDeadFilterViaMultiBranchAllOfTypeConstraintThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/Filter stringToInt on property filteredProperty.*can never be executed'
                . '.*allOf type constraints \(int\) exclude all input types accepted by the filter \(string\)/',
        );

        $this->generateClassFromFile(
            'FilterCompositionAllOfDeadFilterMultiBranch.json',
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

    /**
     * When GeneratorConfiguration::getDraft() returns a DraftFactoryInterface, FilterProcessor
     * calls getDraftForSchema() to obtain the Draft for the property's schema instead of using
     * the DraftInterface directly. Generation must succeed with a factory-provided draft.
     */
    public function testTransformingFilterWithDraftFactoryGeneratesSuccessfully(): void
    {
        $draftFactory = new class implements DraftFactoryInterface {
            public function getDraftForSchema(JsonSchema $jsonSchema): DraftInterface
            {
                return new Draft_07();
            }
        };

        $this->generateClassFromFile(
            'FilterCompositionAllOfInputOnly.json',
            (new GeneratorConfiguration())->setDraft($draftFactory),
        );
        $this->addToAssertionCount(1);
    }

    /**
     * A filter whose callable returns float causes expandNumericTypes to add 'int' to the
     * expanded output type set so that integer-subtype numeric keywords (minimum, maximum, …)
     * classify correctly as output-space. The anyOf branch {minimum: 0} is output-space and
     * uniform, so generation succeeds without error.
     */
    public function testFloatReturningFilterWithNumericOutputSpaceCompositionGeneratesSuccessfully(): void
    {
        $this->generateClassFromFile(
            'FilterCompositionAnyOfMinimumOutputSpace.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomTransformingFilter(
                    [self::class, 'serializeFloatToString'],
                    [self::class, 'convertStringToFloat'],
                    'stringToFloat',
                ),
            ),
        );
        $this->addToAssertionCount(1);
    }
}
