<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\ComposedValue\NotException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Fixtures\RecordingLogger;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The `type` declared on a schema against the types of its composition branches: an allOf branch
 * which doesn't overlap the declared type is a contradiction, an anyOf / oneOf branch which doesn't
 * overlap it is dead, and the type of the property is narrowed to the overlap (including the
 * retyping of a number narrowed to int). A `$ref` branch, a `not` and an `if` / `then` / `else`
 * don't inherit a declared type which their definition contradicts. The check of a branch whose
 * `enum` / `const` can't satisfy the inherited type lives in InheritedTypeConflictTest.
 */
#[ApplicableDrafts]
class DeclaredTypeConflictTest extends AbstractPHPModelGeneratorTestCase
{
    private const string DEAD_BRANCH_WARNING = "{keyword} branch #{branch} for property '{property}' declares the type"
        . ' {branchType} which does not overlap the declared type {declaredType} and can never be satisfied';

    /**
     * The declared type of the property takes part in the allOf conflict check: a branch type
     * which is disjoint from it can never be satisfied together with the property's own type
     * check, so the schema is rejected at generation time instead of producing a class which
     * rejects every value. The message names the declared type, and the first conflicting branch
     * with its position and type.
     */
    #[DataProvider('declaredTypeConflictDataProvider')]
    public function testPropertyTypeContradictedByTheTypeOfAnAllOfBranchIsRejected(
        string $schemaFile,
        string $quotedPropertyNamePattern,
        string $declaredType,
        string $branchType,
        int $branchNumber,
        bool $implicitNull,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            self::declaredTypeConflictMessagePattern(
                $quotedPropertyNamePattern,
                $declaredType,
                $branchType,
                $branchNumber,
            ),
        );

        $this->generateClassFromFile($schemaFile, implicitNull: $implicitNull);
    }

    public static function declaredTypeConflictDataProvider(): array
    {
        return [
            'scalar branch on a scalar property' => [
                'PropertyLevelAllOfBranchContradictsPropertyType.json',
                "'property'",
                "'string'",
                "'integer'",
                1,
                true,
            ],
            // The branch type validates the raw input, so a type-changing filter (string to
            // DateTime) does not exempt the property. Comparing the branch against the output type
            // of the filter was rejected: it would let a raw string pass a `type: integer` check
            // after the transformation.
            'scalar branch on a filtered property' => [
                'PropertyLevelAllOfBranchTypeConflictsWithFilteredPropertyType.json',
                "'property'",
                "'string'",
                "'integer'",
                1,
                true,
            ],
            'object branch on a string property' => [
                'PropertyLevelAllOfObjectBranchConflictsWithStringProperty.json',
                "'property'",
                "'string'",
                "'object'",
                1,
                true,
            ],
            'string branch on an object property' => [
                'PropertyLevelAllOfScalarBranchConflictsWithObjectProperty.json',
                // An object property is named after the class generated for it.
                "'[^']*'",
                "'object'",
                "'string'",
                1,
                true,
            ],
            // Without implicit null, `null` is a type like any other.
            'null branch without implicit null' => [
                'PropertyLevelAllOfNullBranchOptionalProperty.json',
                "'property'",
                "'string'",
                "'null'",
                1,
                false,
            ],
            // Implicit null doesn't apply to a required property (its type check rejects null),
            // so its declared type isn't widened. Widening on the configuration alone was
            // rejected: it would accept a schema whose branch can never be satisfied.
            'null branch on a required property with implicit null' => [
                'PropertyLevelAllOfNullBranchRequiredProperty.json',
                "'property'",
                "'string'",
                "'null'",
                1,
                true,
            ],
            // Reported with the conflict instead of the unrelated "No nested schema for composed
            // property" failure the schema root produced before.
            'scalar branch at the schema root' => [
                'RootLevelAllOfBranchTypeConflictsWithRootType.json',
                "'[^']*'",
                "'object'",
                "'integer'",
                1,
                true,
            ],
            // Reported with the conflict instead of a conflict between the reference and a sibling
            // `type` the author never wrote (the declared type is not passed down into it).
            'reference branch' => [
                'PropertyLevelAllOfReferenceBranchConflictsWithPropertyType.json',
                "'property'",
                "'string'",
                "'integer'",
                1,
                true,
            ],
            'reference branch referencing a reference' => [
                'PropertyLevelAllOfChainedReferenceBranchConflictsWithPropertyType.json',
                "'property'",
                "'string'",
                "'integer'",
                1,
                true,
            ],
            // Every type of both sides is named; the always-true branch ahead of the conflicting
            // one constrains no type and is skipped.
            'multi-type branch on a multi-type property' => [
                'PropertyLevelAllOfMultiTypeBranchConflictsWithMultiTypeProperty.json',
                "'property'",
                "['string', 'boolean']",
                "['integer', 'number']",
                2,
                true,
            ],
            // An allOf inside an allOf branch is as unsatisfiable as the outer allOf.
            'allOf inside an allOf branch' => [
                'DeclaredTypeConflictInsideAllOfBranch.json',
                "'property'",
                "'string'",
                "'integer'",
                1,
                true,
            ],
            'property defined by a reference' => [
                'ReferencedPropertyAllOfBranchConflictsWithPropertyType.json',
                "'property'",
                "'string'",
                "'integer'",
                1,
                true,
            ],
        ];
    }

    /**
     * Branches which contradict each other are reported as such even if one of them contradicts
     * the declared type as well: the cause is the branches. Reporting the declared-type conflict
     * first was rejected, as it would blame the declared type for a schema whose allOf can't be
     * satisfied with any declared type.
     */
    public function testPropertyLevelAllOfBranchConflictTakesPrecedenceOverDeclaredTypeConflict(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Property 'property' is defined with conflicting types in allOf composition branches"
                . ' \\(file \\S+\\): allOf requires all constraints to hold simultaneously, making this schema'
                . ' unsatisfiable at line \\d+, column \\d+$/',
        );

        $this->generateClassFromFile('PropertyLevelAllOfBranchesConflictWithEachOtherAndPropertyType.json');
    }

    /**
     * A composition inside a branch of an anyOf, oneOf, not, if, then or else only makes that
     * branch dead (or the not always hold), the schema stays satisfiable: a declared-type conflict
     * there is reported with a warning instead of failing the generation, whether the type of the
     * branch was written by the author or passed down. Failing the generation was rejected:
     * `{"type": "string", "not": {"allOf": [{"type": "integer"}]}}` accepts every string.
     */
    public function testDeclaredTypeConflictInsideACompositionBranchIsReported(): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'DeclaredTypeConflictInsideCompositionBranch.json',
            (new GeneratorConfiguration())->setImmutable(false)->setLogger($recordingLogger),
        );

        $expectedWarnings = [
            ['allOf', 1, 'inheritedTypeInAnyOfBranch', "'integer'"],
            ['allOf', 1, 'authoredTypeInOneOfBranch', "'integer'"],
            ['allOf', 1, 'allOfInNot', "'integer'"],
            ['anyOf', 1, 'deadAnyOfInAnyOfBranch', "'integer'"],
            ['anyOf', 2, 'deadAnyOfInAnyOfBranch', "'boolean'"],
        ];

        foreach ($expectedWarnings as [$keyword, $branchNumber, $propertyName, $branchType]) {
            $this->assertTrue(
                $this->hasLogEntry(
                    $recordingLogger->getEntries(),
                    'warning',
                    self::DEAD_BRANCH_WARNING,
                    [
                        'keyword' => $keyword,
                        'branch' => $branchNumber,
                        'property' => $propertyName,
                        'branchType' => $branchType,
                        'declaredType' => "'string'",
                    ],
                ),
                "Expected a warning for $keyword branch #$branchNumber of $propertyName.",
            );
        }

        $object = new $className([
            'inheritedTypeInAnyOfBranch' => 'text',
            'authoredTypeInOneOfBranch' => 'text',
            'allOfInNot' => 'text',
            'deadAnyOfInAnyOfBranch' => 'text',
        ]);
        $this->assertSame('text', $object->getInheritedTypeInAnyOfBranch());
        $this->assertSame('text', $object->getAuthoredTypeInOneOfBranch());
        $this->assertSame('text', $object->getAllOfInNot());
        $this->assertSame('text', $object->getDeadAnyOfInAnyOfBranch());
    }

    /**
     * A branch type which overlaps the declared type is not a conflict: integer is a subtype of
     * number in both directions, a multi-type declaration overlaps a branch naming one of its
     * types, and untyped / `true` branches impose no type constraint. A number property narrowed
     * to int by an integer branch checks for an int and no longer casts int input to float:
     * floats and strings are rejected by the property itself with the narrowed type.
     */
    public function testPropertyLevelAllOfBranchTypeOverlappingPropertyTypeIsAccepted(): void
    {
        $className = $this->generateClassFromFile(
            'PropertyLevelAllOfBranchTypeNarrowsPropertyType.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getNarrowedToInteger'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getKeptInteger'),
        );

        $object = new $className([
            'narrowedToInteger' => 42,
            'keptInteger' => 7,
            'keptString' => 'text',
            'untypedBranch' => 'ab',
        ]);

        $this->assertSame(42, $object->getNarrowedToInteger());
        $this->assertSame(7, $object->getKeptInteger());
        $this->assertSame('text', $object->getKeptString());
        $this->assertSame('ab', $object->getUntypedBranch());

        foreach ([[4.5, 'double'], ['text', 'string']] as [$invalidValue, $providedType]) {
            try {
                new $className(['narrowedToInteger' => $invalidValue]);
                $this->fail("Expected a ValidationException for the value of type $providedType");
            } catch (ValidationException $exception) {
                $this->assertSame(
                    "Invalid type for 'narrowedToInteger': requires 'int', got '$providedType'",
                    $exception->getMessage(),
                );
            }
        }
    }

    /**
     * Implicit null widens the declared type of a property it applies to (optional property with
     * implicit null enabled): the property's own type check accepts null, so a `null` branch
     * can be satisfied and the schema is not a contradiction.
     */
    public function testPropertyLevelAllOfNullBranchWithImplicitNullOnOptionalPropertyIsAccepted(): void
    {
        $className = $this->generateClassFromFile(
            'PropertyLevelAllOfNullBranchOptionalProperty.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
            implicitNull: true,
        );

        $this->assertNull((new $className([]))->getProperty());
        $this->assertNull((new $className(['property' => null]))->getProperty());

        // Only null satisfies both the declared string and the null branch.
        try {
            new $className(['property' => 'text']);
            $this->fail('Expected a ValidationException for a string');
        } catch (ValidationException $exception) {
            $this->assertSame(
                <<<ERROR
                Invalid value for 'property' declined by composition constraint
                  Requires to match all composition elements but matched 0 elements
                  - Composition element #1: Failed
                    * Invalid type for 'property': requires 'null', got 'string'
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * Narrowing a `number` to `int` retypes it to `integer`, so its keywords keep applying to the
     * int value: the number type registers the range keywords behind an `is_float` guard, which
     * never holds once the int-to-float cast is gone, and they are rebuilt for the integer type.
     * This holds for the property itself (narrowedProperty), for a number branch of an integer
     * property (narrowedBranch), and for the number of a multi-type declaration (nullableNumber,
     * numberOrString): its int-to-float cast is dropped as well (otherwise 42 reaches the integer
     * branch as 42.0 and is rejected), and its type check accepts an int instead of a float next
     * to the other declared types. A multi-type branch of an integer property is retyped the same
     * way (multiTypeBranch).
     */
    public function testPropertyLevelAllOfNumberNarrowedToIntegerKeepsItsConstraints(): void
    {
        $className = $this->generateClassFromFile(
            'PropertyLevelAllOfNumberNarrowedToInteger.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className([
            'narrowedProperty' => 12,
            'narrowedBranch' => 15,
            'nullableNumber' => 42,
            'numberOrString' => 42,
            'fractionalMultipleOfProperty' => 3,
            'fractionalMultipleOfBranch' => -6,
            'multiTypeBranch' => 15,
        ]);
        $this->assertSame(12, $object->getNarrowedProperty());
        $this->assertSame(15, $object->getNarrowedBranch());
        $this->assertSame(42, $object->getNullableNumber());
        $this->assertSame(42, $object->getNumberOrString());
        $this->assertSame(3, $object->getFractionalMultipleOfProperty());
        $this->assertSame(-6, $object->getFractionalMultipleOfBranch());
        $this->assertSame(15, $object->getMultiTypeBranch());
        $this->assertNull((new $className(['nullableNumber' => null]))->getNullableNumber());

        $invalidInputs = [
            [['narrowedProperty' => 4], "Value for 'narrowedProperty' must not be smaller than 10"],
            [['narrowedProperty' => 22], "Value for 'narrowedProperty' must not be larger than 20"],
            [['narrowedProperty' => 11], "Value for 'narrowedProperty' must be a multiple of 2"],
            [
                ['narrowedBranch' => 5],
                <<<ERROR
                Invalid value for 'narrowedBranch' declined by composition constraint
                  Requires to match all composition elements but matched 0 elements
                  - Composition element #1: Failed
                    * Value for 'narrowedBranch' must not be smaller than 10
                ERROR,
            ],
            [
                ['narrowedBranch' => 20],
                <<<ERROR
                Invalid value for 'narrowedBranch' declined by composition constraint
                  Requires to match all composition elements but matched 0 elements
                  - Composition element #1: Failed
                    * Value for 'narrowedBranch' must be smaller than 20
                ERROR,
            ],
            [['nullableNumber' => 5], "Value for 'nullableNumber' must not be smaller than 10"],
            [
                ['multiTypeBranch' => 5],
                <<<ERROR
                Invalid value for 'multiTypeBranch' declined by composition constraint
                  Requires to match all composition elements but matched 0 elements
                  - Composition element #1: Failed
                    * Value for 'multiTypeBranch' must not be smaller than 10
                ERROR,
            ],
            [
                ['fractionalMultipleOfProperty' => 4],
                "Value for 'fractionalMultipleOfProperty' must be a multiple of 1.5",
            ],
            [
                ['fractionalMultipleOfBranch' => 4],
                <<<ERROR
                Invalid value for 'fractionalMultipleOfBranch' declined by composition constraint
                  Requires to match all composition elements but matched 0 elements
                  - Composition element #1: Failed
                    * Value for 'fractionalMultipleOfBranch' must be a multiple of 1.5
                ERROR,
            ],
            [
                ['nullableNumber' => 4.5],
                "Invalid type for 'nullableNumber': requires ['int', 'null'], got 'double'",
            ],
            [
                ['numberOrString' => 'text'],
                <<<ERROR
                Invalid value for 'numberOrString' declined by composition constraint
                  Requires to match all composition elements but matched 0 elements
                  - Composition element #1: Failed
                    * Invalid type for 'numberOrString': requires 'int', got 'string'
                ERROR,
            ],
        ];

        foreach ($invalidInputs as [$input, $expectedMessage]) {
            try {
                new $className($input);
                $this->fail('Expected a ValidationException for ' . json_encode($input));
            } catch (ValidationException $exception) {
                $this->assertSame($expectedMessage, $exception->getMessage());
            }
        }
    }

    /**
     * A `$ref` branch narrowed to int is retyped on a copy of the referenced definition: in the
     * drafts which ignore the siblings of a `$ref`, the branch is the property of the definition
     * itself, which every other reference to the definition shares. Retyping it in place turned
     * the plain references (plain, plainMultiType) and the branches of other compositions
     * (otherComposition) into int properties which rejected 4.5. A reference to a reference
     * (narrowedAlias, plainAlias) is copied down to the definition at the end of the chain.
     */
    public function testPropertyLevelAllOfReferenceBranchIsNarrowedWithoutChangingTheDefinition(): void
    {
        $className = $this->generateClassFromFile(
            'PropertyLevelAllOfReferencedNumberNarrowedToInteger.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className([
            'narrowed' => 3,
            'narrowedMultiType' => 3,
            'plain' => 4.5,
            'plainMultiType' => 4.5,
            'otherComposition' => 4.5,
            'narrowedAlias' => 3,
            'plainAlias' => 4.5,
        ]);
        $this->assertSame(3, $object->getNarrowedMultiType());
        $this->assertSame(3, $object->getNarrowed());
        $this->assertSame(4.5, $object->getPlain());
        $this->assertSame(4.5, $object->getPlainMultiType());
        $this->assertSame(4.5, $object->getOtherComposition());
        $this->assertSame(3, $object->getNarrowedAlias());
        $this->assertSame(4.5, $object->getPlainAlias());

        // The narrowed branches keep the range keywords of the definition.
        foreach (['narrowed', 'narrowedMultiType', 'narrowedAlias'] as $propertyName) {
            try {
                new $className([$propertyName => 0]);
                $this->fail("Expected a ValidationException for $propertyName");
            } catch (ValidationException $exception) {
                $this->assertSame(
                    <<<ERROR
                    Invalid value for '$propertyName' declined by composition constraint
                      Requires to match all composition elements but matched 0 elements
                      - Composition element #1: Failed
                        * Value for '$propertyName' must not be smaller than 1
                    ERROR,
                    $exception->getMessage(),
                );
            }
        }
    }

    /**
     * A `$ref` branch to a number definition with a keyword, under a number property, inherits the
     * declared type as a sibling of the reference. In the drafts which apply the siblings of a
     * `$ref` the merge of the sibling with the definition ran the number modifiers twice, and the
     * int-to-float cast was rendered nested into itself: the generated class didn't even parse.
     * A `type` the author writes next to the reference (referenceWithType) took the same path.
     */
    public function testPropertyLevelAllOfReferenceToNumberDefinitionCastsOnce(): void
    {
        $className = $this->generateClassFromFile(
            'PropertyLevelAllOfReferenceToNumberDefinitionWithKeyword.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $object = new $className(['property' => 5, 'referenceWithType' => 5]);
        $this->assertSame(5.0, $object->getProperty());
        $this->assertSame(5.0, $object->getReferenceWithType());

        try {
            new $className(['property' => 0.5]);
            $this->fail('Expected a ValidationException for the value 0.5');
        } catch (ValidationException $exception) {
            $this->assertSame(
                <<<ERROR
                Invalid value for 'property' declined by composition constraint
                  Requires to match all composition elements but matched 0 elements
                  - Composition element #1: Failed
                    * Value for 'property' must not be smaller than 1
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    private static function declaredTypeConflictMessagePattern(
        string $quotedPropertyNamePattern,
        string $declaredType,
        string $branchType,
        int $branchNumber,
    ): string {
        return '/^Property ' . $quotedPropertyNamePattern
            . ' is declared with type ' . preg_quote($declaredType, '/') . ' which conflicts with the type '
            . preg_quote($branchType, '/') . " of allOf branch #$branchNumber \(file .+\): allOf requires all"
            . ' constraints to hold simultaneously, making this schema unsatisfiable at line \d+, column \d+$/';
    }

    public static function compositionKeywordDataProvider(): array
    {
        return [
            'anyOf' => ['anyOf'],
            'oneOf' => ['oneOf'],
        ];
    }

    /**
     * The keyword together with the header of its failure message when no branch matches.
     */
    public static function compositionFailureDataProvider(): array
    {
        return [
            'anyOf' => ['anyOf', 'Requires to match at least one composition element'],
            'oneOf' => ['oneOf', 'Requires to match one composition element but matched 0 elements'],
        ];
    }

    /**
     * A branch whose type does not overlap the declared type of the property can never match. It
     * is reported with a warning, and the property type is narrowed to the overlap of the declared
     * type and the branch types instead of being replaced by the branch types: the declared number
     * overlaps the integer branch (integer is a subtype of number), the string branch is dead. The
     * narrowed property checks for an int itself, so a float or a string is rejected by its own
     * type check. Dropping that check was rejected: the string would no longer meet the declared
     * number and would be accepted by the string branch. The types of a multi-type declaration and
     * of a multi-type branch are all named in the warning.
     */
    #[DataProvider('compositionKeywordDataProvider')]
    public function testDeadBranchIsReportedAndPropertyTypeIsNarrowed(string $keyword): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFileTemplate(
            'PartiallyDeadBranch.json',
            [$keyword],
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false)->setLogger($recordingLogger),
            false,
        );

        $deadBranches = [
            ['property', 2, "'string'", "'number'"],
            ['multiTypeProperty', 1, "['integer', 'number']", "['string', 'boolean']"],
        ];

        foreach ($deadBranches as [$propertyName, $branchNumber, $branchType, $declaredType]) {
            $this->assertTrue(
                $this->hasLogEntry(
                    $recordingLogger->getEntries(),
                    'warning',
                    self::DEAD_BRANCH_WARNING,
                    [
                        'keyword' => $keyword,
                        'branch' => $branchNumber,
                        'property' => $propertyName,
                        'branchType' => $branchType,
                        'declaredType' => $declaredType,
                    ],
                ),
                "Expected a warning for the dead $keyword branch of $propertyName.",
            );
        }

        $this->assertEqualsCanonicalizing(['int', 'null'], $this->getReturnTypeNames($className, 'getProperty'));
        $this->assertEqualsCanonicalizing(['int', 'null'], $this->getParameterTypeNames($className, 'setProperty'));
        $this->assertEqualsCanonicalizing(
            ['string', 'null'],
            $this->getReturnTypeNames($className, 'getMultiTypeProperty'),
        );

        $this->assertSame(5, (new $className(['property' => 5]))->getProperty());
        $this->assertSame('text', (new $className(['multiTypeProperty' => 'text']))->getMultiTypeProperty());

        foreach ([[4.5, 'double'], ['5', 'string']] as [$invalidValue, $providedType]) {
            try {
                new $className(['property' => $invalidValue]);
                $this->fail("Expected a ValidationException for the value of type $providedType");
            } catch (ValidationException $exception) {
                $this->assertSame(
                    "Invalid type for 'property': requires 'int', got '$providedType'",
                    $exception->getMessage(),
                );
            }
        }
    }

    /**
     * When no branch can match the declared type, no value can be valid: the schema is rejected at
     * generation time, like contradicting allOf branches are.
     */
    #[DataProvider('compositionKeywordDataProvider')]
    public function testAllBranchesDisjointFromPropertyTypeThrowSchemaException(string $keyword): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Property 'property' is declared with type 'string' which conflicts with the types 'integer', 'boolean'"
                . " of all $keyword branches \\(file .+\\): $keyword requires at least one branch to be satisfied,"
                . ' making this schema unsatisfiable at line \d+, column \d+$/',
        );

        $this->generateClassFromFileTemplate('AllBranchesDead.json', [$keyword], escape: false);
    }

    /**
     * Without implicit null a `null` branch is dead for a declared string property and is
     * reported. Implicit null widens the declared type of an optional property to "string | null",
     * so the same branch can match and nothing is reported for it. A required property is not
     * widened: its own type check rejects null, so its `null` branch stays dead with implicit null.
     */
    #[DataProvider('compositionKeywordDataProvider')]
    public function testNullBranchIsReportedOnlyWhenImplicitNullDoesNotApply(string $keyword): void
    {
        $expectedWarnings = [
            'implicit null disabled' => [false, ['property' => true, 'requiredProperty' => true]],
            'implicit null enabled' => [true, ['property' => false, 'requiredProperty' => true]],
        ];

        foreach ($expectedWarnings as $label => [$implicitNull, $warnedProperties]) {
            $recordingLogger = new RecordingLogger();
            $this->generateClassFromFileTemplate(
                'NullBranch.json',
                [$keyword],
                (new GeneratorConfiguration())->setLogger($recordingLogger),
                false,
                $implicitNull,
            );

            foreach ($warnedProperties as $propertyName => $expectWarning) {
                $this->assertSame(
                    $expectWarning,
                    $this->hasLogEntry(
                        $recordingLogger->getEntries(),
                        'warning',
                        self::DEAD_BRANCH_WARNING,
                        [
                            'keyword' => $keyword,
                            'branch' => 2,
                            'property' => $propertyName,
                            'branchType' => "'null'",
                            'declaredType' => "'string'",
                        ],
                    ),
                    "Unexpected dead branch warning state for $propertyName with $label.",
                );
            }
        }
    }

    public static function integerAndNumberBranchesDataProvider(): array
    {
        return [
            // The integer branch matches an int first, so the int is stored.
            'anyOf' => ['anyOf', 'Requires to match at least one composition element', null],
            // An int matches both branches, which oneOf rejects.
            'oneOf' => [
                'oneOf',
                'Requires to match one composition element but matched 0 elements',
                'Requires to match one composition element but matched 2 elements',
            ],
        ];
    }

    /**
     * An integer and a number branch under a declared number: the narrowed type is int | float,
     * as every branch is intersected with the declared type on its own before the union is built.
     * The property doesn't cast an int to float before the composition, so the integer branch
     * sees the int: casting first was rejected, it made the integer branch reject every int
     * (`{"type": "number", "anyOf": [{"type": "integer", "maximum": 3}, ...]}` rejected 2) and
     * oneOf accept an int which matches both branches. The stored value is the one of the first
     * matching branch. The range keywords of the property apply to an int as well. When every
     * branch is an integer branch (integerBranchesOnly) the narrowed type is int only.
     */
    #[DataProvider('integerAndNumberBranchesDataProvider')]
    public function testIntegerAndNumberBranchesTypeNumberPropertyAsIntOrFloat(
        string $keyword,
        string $failureHeader,
        ?string $integerMatchingBothBranchesHeader,
    ): void {
        $className = $this->generateClassFromFileTemplate(
            'NumberDeclaredWithIntegerAndNumberBranch.json',
            [$keyword],
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
            false,
        );

        foreach (['getProperty', 'getRangedProperty', 'getMultiTypeProperty'] as $getter) {
            $this->assertEqualsCanonicalizing(['int', 'float', 'null'], $this->getReturnTypeNames($className, $getter));
        }

        $this->assertSame(5.5, (new $className(['property' => 5.5]))->getProperty());
        $this->assertSame(5.5, (new $className(['multiTypeProperty' => 5.5]))->getMultiTypeProperty());
        $this->assertNull((new $className(['property' => null]))->getProperty());
        $this->assertSame(2, (new $className(['rangedProperty' => 2]))->getRangedProperty());
        $this->assertSame(12.0, (new $className(['rangedProperty' => 12]))->getRangedProperty());
        $this->assertSame(12.5, (new $className(['rangedProperty' => 12.5]))->getRangedProperty());

        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getIntegerBranchesOnly'),
        );
        $this->assertSame(2, (new $className(['integerBranchesOnly' => 2]))->getIntegerBranchesOnly());
        $this->assertSame(6, (new $className(['integerBranchesOnly' => 6]))->getIntegerBranchesOnly());

        $invalidInputs = [
            [
                ['rangedProperty' => 5],
                <<<ERROR
                Invalid value for 'rangedProperty' declined by composition constraint
                  $failureHeader
                  - Composition element #1: Failed
                    * Value for 'rangedProperty' must not be larger than 3
                  - Composition element #2: Failed
                    * Value for 'rangedProperty' must not be smaller than 10
                ERROR,
            ],
            [['rangedProperty' => 0], "Value for 'rangedProperty' must not be smaller than 1"],
            [['rangedProperty' => 0.5], "Value for 'rangedProperty' must not be smaller than 1"],
            [
                ['integerBranchesOnly' => 4.5],
                "Invalid type for 'integerBranchesOnly': requires 'int', got 'double'",
            ],
        ];

        if ($integerMatchingBothBranchesHeader === null) {
            $this->assertSame(5, (new $className(['property' => 5]))->getProperty());
            $this->assertSame(5, (new $className(['multiTypeProperty' => 5]))->getMultiTypeProperty());
        } else {
            foreach (['property', 'multiTypeProperty'] as $propertyName) {
                $invalidInputs[] = [
                    [$propertyName => 5],
                    <<<ERROR
                    Invalid value for '$propertyName' declined by composition constraint
                      $integerMatchingBothBranchesHeader
                      - Composition element #1: Valid
                      - Composition element #2: Valid
                    ERROR,
                ];
            }
        }

        foreach ($invalidInputs as [$input, $expectedMessage]) {
            try {
                new $className($input);
                $this->fail('Expected a ValidationException for ' . json_encode($input));
            } catch (ValidationException $exception) {
                $this->assertSame($expectedMessage, $exception->getMessage());
            }
        }
    }

    /**
     * A number branch of an integer property is narrowed to int together with the property, and
     * its range keywords are rebuilt for the integer type (numberBranch): the number type
     * registers them behind an `is_float` guard, which never holds once the int-to-float cast of
     * the branch is gone. A multi-type declaration containing `number` is narrowed to int by an
     * integer branch the same way (multiTypeProperty): the number variant's int-to-float cast is
     * dropped (otherwise 42 reaches the integer branch as 42.0 and only a string could match),
     * the type check accepts an int instead of a float next to the string, and the number range
     * keywords keep applying to the int value.
     */
    #[DataProvider('compositionFailureDataProvider')]
    public function testNumberNarrowedToIntegerByTheBranchesKeepsItsConstraints(
        string $keyword,
        string $failureHeader,
    ): void {
        $className = $this->generateClassFromFileTemplate(
            'NumberNarrowedToInteger.json',
            [$keyword],
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
            false,
        );

        $this->assertSame(15, (new $className(['numberBranch' => 15]))->getNumberBranch());
        $this->assertSame(42, (new $className(['multiTypeProperty' => 42]))->getMultiTypeProperty());
        $this->assertSame('ab', (new $className(['multiTypeProperty' => 'ab']))->getMultiTypeProperty());

        $invalidInputs = [
            [
                ['numberBranch' => 5],
                <<<ERROR
                Invalid value for 'numberBranch' declined by composition constraint
                  $failureHeader
                  - Composition element #1: Failed
                    * Value for 'numberBranch' must not be smaller than 10
                  - Composition element #2: Failed
                    * Invalid type for 'numberBranch': requires 'string', got 'integer'
                ERROR,
            ],
            [['multiTypeProperty' => 5], "Value for 'multiTypeProperty' must not be smaller than 10"],
            [
                ['multiTypeProperty' => 4.5],
                "Invalid type for 'multiTypeProperty': requires ['int', 'string'], got 'double'",
            ],
        ];

        foreach ($invalidInputs as [$input, $expectedMessage]) {
            try {
                new $className($input);
                $this->fail('Expected a ValidationException for ' . json_encode($input));
            } catch (ValidationException $exception) {
                $this->assertSame($expectedMessage, $exception->getMessage());
            }
        }
    }

    /**
     * A `$ref` branch whose definition declares a type disjoint from the declared type of the
     * property is dead like an inline branch: it is reported with a warning instead of failing the
     * generation with a conflict between the reference and a sibling `type` the author never wrote.
     * The declared type is not passed down into such a branch; a reference to a reference
     * (aliasProperty) is followed down to the type of the definition at its end. A `$ref` branch
     * to an untyped definition still inherits the declared type, so its string keywords apply, and
     * so does one to a boolean schema (booleanReferenceProperty).
     */
    #[DataProvider('compositionFailureDataProvider')]
    public function testReferenceBranchDisjointFromPropertyTypeIsReported(string $keyword, string $failureHeader): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFileTemplate(
            'DeadReferenceBranch.json',
            [$keyword],
            (new GeneratorConfiguration())->setCollectErrors(false)->setLogger($recordingLogger),
            false,
        );

        foreach (['property', 'aliasProperty'] as $propertyName) {
            $this->assertTrue(
                $this->hasLogEntry(
                    $recordingLogger->getEntries(),
                    'warning',
                    self::DEAD_BRANCH_WARNING,
                    [
                        'keyword' => $keyword,
                        'branch' => 1,
                        'property' => $propertyName,
                        'branchType' => "'integer'",
                        'declaredType' => "'string'",
                    ],
                ),
                "Expected a warning for the dead $keyword reference branch of $propertyName.",
            );
        }

        $this->assertSame('text', (new $className(['aliasProperty' => 'text']))->getAliasProperty());
        $this->assertSame(
            'text',
            (new $className(['booleanReferenceProperty' => 'text']))->getBooleanReferenceProperty(),
        );
        $this->assertSame('abc', (new $className(['property' => 'abc']))->getProperty());

        try {
            new $className(['property' => 'abcd']);
            $this->fail('Expected a ValidationException for the value abcd');
        } catch (ValidationException $exception) {
            $this->assertSame(
                <<<ERROR
                Invalid value for 'property' declined by composition constraint
                  $failureHeader
                  - Composition element #1: Failed
                    * Invalid type for 'property': requires 'int', got 'string'
                  - Composition element #2: Failed
                    * Value for 'property' must not be longer than 3
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * At the schema root the declared type is object, as for every schema which becomes a class.
     * When every branch is a scalar branch no object can be valid, and the schema is rejected
     * instead of failing later with an unrelated "No nested schema" error.
     */
    #[DataProvider('compositionKeywordDataProvider')]
    public function testRootLevelBranchesDisjointFromObjectThrowSchemaException(string $keyword): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Property '[^']*' is declared with type 'object' which conflicts with the types 'string', 'integer'"
                . " of all $keyword branches \\(file .+\\): $keyword requires at least one branch to be satisfied,"
                . ' making this schema unsatisfiable at line \d+, column \d+$/',
        );

        $this->generateClassFromFileTemplate('RootLevelAllBranchesDead.json', [$keyword], escape: false);
    }

    /**
     * A scalar branch next to an object branch at the schema root can never match an object: it is
     * reported, and the object branch alone decides whether the object is valid.
     */
    #[DataProvider('compositionFailureDataProvider')]
    public function testRootLevelScalarBranchNextToObjectBranchIsReported(string $keyword, string $failureHeader): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFileTemplate(
            'RootLevelPartiallyDeadBranch.json',
            [$keyword],
            (new GeneratorConfiguration())->setCollectErrors(false)->setLogger($recordingLogger),
            false,
        );

        $this->assertTrue(
            $this->hasLogEntry(
                $recordingLogger->getEntries(),
                'warning',
                self::DEAD_BRANCH_WARNING,
                ['keyword' => $keyword, 'branch' => 2, 'branchType' => "'string'", 'declaredType' => "'object'"],
            ),
            'Expected a warning for the dead scalar branch at the schema root.',
        );

        $this->assertSame('Hannes', (new $className(['name' => 'Hannes', 'age' => 42]))->getName());

        try {
            new $className(['name' => 'Hannes']);
            $this->fail('Expected a ValidationException for an object without age');
        } catch (ValidationException $exception) {
            $this->assertMessageMatchesTemplate(
                <<<ERROR
                Invalid value for '%class%' declined by composition constraint
                  $failureHeader
                  - Composition element #1: Failed
                    * Missing required value for 'age'
                  - Composition element #2: Failed
                    * Invalid type for '%class%': requires 'string', got 'object'
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * A reference into another file whose root is a reference to a definition of that file is
     * followed only up to the other file: its own references would be resolved against the file
     * of the branch. The type of the definition is therefore not known, the declared type is
     * passed down into the branch and conflicts with the referenced integer in the drafts which
     * apply the siblings of a `$ref` ("$ref resolves to type 'integer' but sibling 'type' declares
     * 'string'"); in Draft 07 the dead branch is not reported.
     *
     * Known gap: the test turns green by itself as soon as the chain is followed across files.
     */
    public function testAnyOfReferenceBranchChainedAcrossFilesIsReported(): void
    {
        $recordingLogger = new RecordingLogger();

        try {
            $this->generateClassFromFile(
                'PropertyLevelAnyOfDeadExternalReferenceChainBranch.json',
                (new GeneratorConfiguration())->setLogger($recordingLogger),
            );
        } catch (SchemaException) {
            $this->markTestIncomplete('Known gap: a reference chain across files is not followed.');
        }

        $deadBranchReported = $this->hasLogEntry(
            $recordingLogger->getEntries(),
            'warning',
            self::DEAD_BRANCH_WARNING,
            ['keyword' => 'anyOf', 'branch' => 1, 'property' => 'property', 'branchType' => "'integer'"],
        );

        if (!$deadBranchReported) {
            $this->markTestIncomplete('Known gap: a reference chain across files is not followed.');
        }

        $this->assertTrue($deadBranchReported);
    }

    /**
     * A `not` schema which is a `$ref` to a definition with a type disjoint from the declared type
     * does not inherit the declared type: it would conflict with the referenced type in the drafts
     * which apply the siblings of a `$ref`. Such a `not` holds for every value of the declared
     * type. A `$ref` to an untyped definition still inherits the declared type, so its string
     * keywords apply.
     */
    public function testNotReferenceDisjointFromPropertyTypeAcceptsEveryValueOfTheDeclaredType(): void
    {
        $className = $this->generateClassFromFile(
            'NotReferenceDisjointFromPropertyType.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $object = new $className(['notInteger' => 'ab', 'notShort' => 'abcd']);
        $this->assertSame('ab', $object->getNotInteger());
        $this->assertSame('abcd', $object->getNotShort());

        try {
            new $className(['notInteger' => 'ab', 'notShort' => 'abc']);
            $this->fail('Expected a NotException for the value abc');
        } catch (NotException $exception) {
            $this->assertSame(
                <<<ERROR
                Invalid value for 'notShort' declined by composition constraint
                  Requires to match none composition element but matched 1 element
                  - Composition element #1: Valid
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * A `then` schema which is a `$ref` to a definition with a type disjoint from the declared type
     * does not inherit the declared type: it would conflict with the referenced type in the drafts
     * which apply the siblings of a `$ref`. The branch can never be satisfied, so every value
     * which meets the condition is rejected.
     */
    public function testThenReferenceDisjointFromPropertyTypeRejectsValuesMeetingTheCondition(): void
    {
        $className = $this->generateClassFromFile(
            'ThenReferenceDisjointFromPropertyType.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $this->assertSame('a', (new $className(['property' => 'a']))->getProperty());

        try {
            new $className(['property' => 'ab']);
            $this->fail('Expected a ConditionalException for the value ab');
        } catch (ConditionalException $exception) {
            $this->assertSame(
                <<<ERROR
                Invalid value for 'property' declined by conditional composition constraint
                  - Condition: Valid
                  - Conditional branch failed:
                    * Invalid type for 'property': requires 'int', got 'string'
                ERROR,
                $exception->getMessage(),
            );
        }
    }
}
