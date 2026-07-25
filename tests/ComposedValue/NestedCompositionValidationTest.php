<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers issue #167: a composition keyword nested inside a branch of a property-level
 * composition must be validated even when the branch does not resolve to an explicit object
 * schema (no generated class exists to re-validate it independently).
 */
class NestedCompositionValidationTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * Exact reproduction from issue #167: an allOf branch containing a bare anyOf, and an allOf
     * branch containing a bare oneOf. Before the fix both nested compositions were silently
     * stripped and any value was accepted.
     */
    #[DataProvider('allOfWithNestedAnyOfOneOfDataProvider')]
    public function testAllOfWithNestedAnyOfOneOfBranches(mixed $propertyValue, ?string $expectedMessage): void
    {
        $className = $this->generateClassFromFile('AllOfWithNestedAnyOfOneOfBranches.json');

        if ($expectedMessage === null) {
            $object = new $className(['p' => $propertyValue]);
            $this->assertSame($propertyValue, $object->getP());

            return;
        }

        try {
            new $className(['p' => $propertyValue]);
            $this->fail('Expected AllOfException');
        } catch (AllOfException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
            $this->assertSame('/properties/p/allOf', $exception->getJsonPointer()->pointer);
        }
    }

    public static function allOfWithNestedAnyOfOneOfDataProvider(): array
    {
        return [
            // satisfies the anyOf (integer) and matches exactly one branch of the oneOf (integer)
            'int 3 is accepted' => [3, null],
            // fails the nested anyOf (not a string with >=5 chars, not an integer); matches the
            // nested oneOf (boolean, exactly one match) -> outer allOf matched 1 of 2 branches
            'bool true is rejected' => [
                true,
                "Invalid value for 'p' declined by composition constraint\n"
                    . '  Requires to match all composition elements but matched 1 element',
            ],
            // fails both nested compositions: too short for the anyOf's string branch and not an
            // integer; neither an integer nor a boolean for the oneOf
            '"ab" is rejected' => [
                'ab',
                "Invalid value for 'p' declined by composition constraint\n"
                    . '  Requires to match all composition elements but matched 0 elements',
            ],
            // satisfies the nested anyOf (string with >=5 chars); fails the nested oneOf (neither
            // integer nor boolean matches)
            '"abcdef" is rejected' => [
                'abcdef',
                "Invalid value for 'p' declined by composition constraint\n"
                    . '  Requires to match all composition elements but matched 1 element',
            ],
        ];
    }

    /**
     * Same-keyword nesting: an allOf branch that is itself a bare allOf (no "type" declared).
     */
    #[DataProvider('nestedAllOfInAllOfBranchDataProvider')]
    public function testNestedAllOfInAllOfBranch(mixed $propertyValue, ?string $expectedMessage): void
    {
        $className = $this->generateClassFromFile('NestedAllOfInAllOfBranch.json');

        if ($expectedMessage === null) {
            $object = new $className(['p' => $propertyValue]);
            $this->assertSame($propertyValue, $object->getP());

            return;
        }

        try {
            new $className(['p' => $propertyValue]);
            $this->fail('Expected AllOfException');
        } catch (AllOfException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
        }
    }

    public static function nestedAllOfInAllOfBranchDataProvider(): array
    {
        return [
            // multiple of 2 and of 3 (i.e. of 6), and <= 100 -> matches both outer branches
            'int 12 is accepted' => [12, null],
            // multiple of 2 but not of 3 -> fails the nested allOf; <= 100 -> matches the plain
            // outer branch: 1 of 2 outer branches matched
            'int 8 is rejected' => [
                8,
                "Invalid value for 'p' declined by composition constraint\n"
                    . '  Requires to match all composition elements but matched 1 element',
            ],
        ];
    }

    /**
     * Mixed nesting: an allOf branch that is itself a bare not.
     */
    #[DataProvider('nestedNotInAllOfBranchDataProvider')]
    public function testNestedNotInAllOfBranch(mixed $propertyValue, ?string $expectedMessage): void
    {
        $className = $this->generateClassFromFile('NestedNotInAllOfBranch.json');

        if ($expectedMessage === null) {
            $object = new $className(['p' => $propertyValue]);
            $this->assertSame($propertyValue, $object->getP());

            return;
        }

        try {
            new $className(['p' => $propertyValue]);
            $this->fail('Expected AllOfException');
        } catch (AllOfException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
        }
    }

    public static function nestedNotInAllOfBranchDataProvider(): array
    {
        return [
            // an integer that is not 5 or 6 -> matches both outer branches
            'int 7 is accepted' => [7, null],
            // an integer, but the nested not (enum: [5, 6]) is violated -> 1 of 2 outer branches
            // matched
            'int 5 is rejected' => [
                5,
                "Invalid value for 'p' declined by composition constraint\n"
                    . '  Requires to match all composition elements but matched 1 element',
            ],
        ];
    }

    /**
     * The if/then/else branches are built by IfValidatorFactory in a separate code path from
     * AbstractCompositionValidatorFactory::getCompositionProperties() but have the identical bug:
     * a "then" branch that is itself a bare anyOf (no "type" declared) must still be validated.
     */
    #[DataProvider('nestedAnyOfInThenBranchDataProvider')]
    public function testNestedAnyOfInThenBranch(mixed $propertyValue, ?string $expectedMessage): void
    {
        $className = $this->generateClassFromFile('NestedAnyOfInThenBranch.json');

        if ($expectedMessage === null) {
            $object = new $className(['p' => $propertyValue]);
            $this->assertSame($propertyValue, $object->getP());

            return;
        }

        try {
            new $className(['p' => $propertyValue]);
            $this->fail('Expected ConditionalException');
        } catch (ConditionalException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
        }
    }

    public static function nestedAnyOfInThenBranchDataProvider(): array
    {
        return [
            // not an integer -> the if-condition fails -> then never applies -> no constraint
            'string is accepted (if-condition fails)' => ['hello', null],
            // an integer satisfying the then-branch's anyOf (>= 10)
            'int 15 is accepted' => [15, null],
            // an integer satisfying the then-branch's anyOf (<= 0)
            'int -10 is accepted' => [-10, null],
            // an integer satisfying neither anyOf branch of the then-composition
            'int 5 is rejected' => [
                5,
                "Invalid value for 'p' declined by conditional composition constraint\n"
                    . "  - Condition: Valid\n"
                    . "  - Conditional branch failed:\n"
                    . "    * Invalid value for 'p' declined by composition constraint\n"
                    . '      Requires to match at least one composition element',
            ],
        ];
    }

    /**
     * Regression guard: a nested "if" inside an untyped allOf branch (no nested schema) was
     * already validated before the fix (ConditionalPropertyValidator was never filtered out) and
     * must remain validated after widening the filtered class from ComposedPropertyValidator to
     * AbstractComposedPropertyValidator.
     */
    #[DataProvider('nestedIfInAllOfBranchUntypedDataProvider')]
    public function testNestedIfInAllOfBranchUntypedStillValidates(mixed $propertyValue, bool $valid): void
    {
        $className = $this->generateClassFromFile('NestedIfInAllOfBranchUntyped.json');

        if ($valid) {
            $object = new $className(['p' => $propertyValue]);
            $this->assertSame($propertyValue, $object->getP());

            return;
        }

        try {
            new $className(['p' => $propertyValue]);
            $this->fail('Expected AllOfException');
        } catch (AllOfException $exception) {
            $this->assertSame(
                "Invalid value for 'p' declined by composition constraint\n"
                    . '  Requires to match all composition elements but matched 1 element',
                $exception->getMessage(),
            );
        }
    }

    public static function nestedIfInAllOfBranchUntypedDataProvider(): array
    {
        return [
            // if-condition true (>= 0), then-branch satisfied (<= 10); also an integer
            'int 5 is accepted' => [5, true],
            // if-condition true (>= 0), then-branch violated (> 10); still an integer
            'int 15 is rejected' => [15, false],
        ];
    }

    /**
     * Regression guard for the symmetrize change: a nested "if" inside an anyOf branch that DOES
     * resolve to an explicit object (has a nested schema) must still generate a class that
     * validates correctly. Its own generated class already re-validates the if/then on
     * instantiation, so the outer branch's ConditionalPropertyValidator is now stripped here
     * (previously kept unconditionally, which was redundant but happened to still work).
     */
    #[DataProvider('nestedIfInAnyOfBranchWithNestedSchemaDataProvider')]
    public function testNestedIfInAnyOfBranchWithNestedSchema(array $propertyValue, bool $valid): void
    {
        $className = $this->generateClassFromFile('NestedIfInAnyOfBranchWithNestedSchema.json');

        if ($valid) {
            $object = new $className(['p' => $propertyValue]);
            $this->assertNotNull($object->getP());

            return;
        }

        $this->expectException(AnyOfException::class);
        new $className(['p' => $propertyValue]);
    }

    public static function nestedIfInAnyOfBranchWithNestedSchemaDataProvider(): array
    {
        return [
            // kind = A -> the then-branch requires "value"; provided -> object branch matches
            'object satisfying the then-branch is accepted' => [['kind' => 'A', 'value' => 'hello'], true],
            // kind = A -> the then-branch requires "value"; missing -> object branch fails to
            // instantiate; not an integer either -> no branch of the outer anyOf matches
            'object violating the then-branch is rejected' => [['kind' => 'A'], false],
        ];
    }
}
