<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #167: a composition keyword nested inside a branch of a property-level composition must
 * be validated even when the branch does not resolve to an explicit object schema (no generated
 * class exists to re-validate it independently). Before the fix, AbstractCompositionValidatorFactory
 * ::getCompositionProperties() and IfValidatorFactory::modify() unconditionally stripped a
 * branch's own nested composed/conditional validator, silently accepting any value for a branch
 * that was itself a bare composition (e.g. "allOf": [{"anyOf": [...]}], no "type").
 */
class Issue167Test extends AbstractIssueTestCase
{
    /**
     * Exact reproduction from the issue: an allOf branch containing a bare anyOf, and an allOf
     * branch containing a bare oneOf. An integer satisfies the anyOf (as an integer) and matches
     * exactly one branch of the oneOf (as an integer).
     */
    public function testAllOfWithNestedAnyOfOneOfBranchesAcceptsAMatchingInteger(): void
    {
        $className = $this->generateClassFromFile('AllOfWithNestedAnyOfOneOfBranches.json');

        $object = new $className(['p' => 3]);

        $this->assertSame(3, $object->getP());
    }

    /**
     * Before the fix, both nested compositions were silently stripped and any value was accepted.
     */
    #[DataProvider('allOfWithNestedAnyOfOneOfBranchesRejectedDataProvider')]
    public function testAllOfWithNestedAnyOfOneOfBranchesRejectsNonMatchingValues(
        mixed $propertyValue,
        string $expectedMessage,
    ): void {
        $className = $this->generateClassFromFile('AllOfWithNestedAnyOfOneOfBranches.json');

        try {
            new $className(['p' => $propertyValue]);
            $this->fail('Expected AllOfException');
        } catch (AllOfException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
            $this->assertSame('/properties/p/allOf', $exception->getJsonPointer()->pointer);
        }
    }

    public static function allOfWithNestedAnyOfOneOfBranchesRejectedDataProvider(): array
    {
        return [
            // fails the nested anyOf (not a string with >=5 chars, not an integer); matches the
            // nested oneOf (boolean, exactly one match) -> outer allOf matched 1 of 2 branches
            'bool true fails the nested anyOf' => [
                true,
                <<<ERROR
                Invalid value for 'p' declined by composition constraint
                  Requires to match all composition elements but matched 1 element
                  - Composition element #1: Failed
                    * Invalid value for 'p' declined by composition constraint
                      Requires to match at least one composition element
                      - Composition element #1: Failed
                        * Invalid type for 'p': requires 'string', got 'boolean'
                      - Composition element #2: Failed
                        * Invalid type for 'p': requires 'int', got 'boolean'
                  - Composition element #2: Valid
                ERROR,
            ],
            // fails both nested compositions: too short for the anyOf's string branch and not an
            // integer; neither an integer nor a boolean for the oneOf
            '"ab" fails both nested compositions' => [
                'ab',
                <<<ERROR
                Invalid value for 'p' declined by composition constraint
                  Requires to match all composition elements but matched 0 elements
                  - Composition element #1: Failed
                    * Invalid value for 'p' declined by composition constraint
                      Requires to match at least one composition element
                      - Composition element #1: Failed
                        * Value for 'p' must not be shorter than 5
                      - Composition element #2: Failed
                        * Invalid type for 'p': requires 'int', got 'string'
                  - Composition element #2: Failed
                    * Invalid value for 'p' declined by composition constraint
                      Requires to match one composition element but matched 0 elements
                      - Composition element #1: Failed
                        * Invalid type for 'p': requires 'int', got 'string'
                      - Composition element #2: Failed
                        * Invalid type for 'p': requires 'bool', got 'string'
                ERROR,
            ],
            // satisfies the nested anyOf (string with >=5 chars); fails the nested oneOf (neither
            // integer nor boolean matches)
            '"abcdef" fails the nested oneOf' => [
                'abcdef',
                <<<ERROR
                Invalid value for 'p' declined by composition constraint
                  Requires to match all composition elements but matched 1 element
                  - Composition element #1: Valid
                  - Composition element #2: Failed
                    * Invalid value for 'p' declined by composition constraint
                      Requires to match one composition element but matched 0 elements
                      - Composition element #1: Failed
                        * Invalid type for 'p': requires 'int', got 'string'
                      - Composition element #2: Failed
                        * Invalid type for 'p': requires 'bool', got 'string'
                ERROR,
            ],
        ];
    }

    /**
     * Same-keyword nesting: an allOf branch that is itself a bare allOf (no "type" declared).
     * 12 is a multiple of 2 and of 3 (i.e. of 6), and <= 100, so it satisfies both outer branches.
     */
    public function testNestedAllOfInAllOfBranchAcceptsAValueSatisfyingBothLevels(): void
    {
        $className = $this->generateClassFromFile('NestedAllOfInAllOfBranch.json');

        $object = new $className(['p' => 12]);

        $this->assertSame(12, $object->getP());
    }

    /**
     * 8 is a multiple of 2 but not of 3 -> fails the nested allOf; it is still <= 100, so the
     * plain outer branch matches: 1 of 2 outer branches matched. Before the fix the nested allOf
     * was stripped and 8 was silently accepted.
     */
    public function testNestedAllOfInAllOfBranchRejectsAValueViolatingTheNestedAllOf(): void
    {
        $className = $this->generateClassFromFile('NestedAllOfInAllOfBranch.json');

        try {
            new $className(['p' => 8]);
            $this->fail('Expected AllOfException');
        } catch (AllOfException $exception) {
            $this->assertSame(
                <<<ERROR
                Invalid value for 'p' declined by composition constraint
                  Requires to match all composition elements but matched 1 element
                  - Composition element #1: Failed
                    * Invalid value for 'p' declined by composition constraint
                      Requires to match all composition elements but matched 1 element
                      - Composition element #1: Valid
                      - Composition element #2: Failed
                        * Value for 'p' must be a multiple of 3
                  - Composition element #2: Valid
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * Mixed nesting: an allOf branch that is itself a bare not. 7 is an integer that is not 5 or
     * 6, so it matches both outer branches.
     */
    public function testNestedNotInAllOfBranchAcceptsAValueNotMatchingTheNestedNot(): void
    {
        $className = $this->generateClassFromFile('NestedNotInAllOfBranch.json');

        $object = new $className(['p' => 7]);

        $this->assertSame(7, $object->getP());
    }

    /**
     * 5 is an integer, but the nested not (enum: [5, 6]) is violated -> 1 of 2 outer branches
     * matched. Before the fix the nested not was stripped and 5 was silently accepted.
     */
    public function testNestedNotInAllOfBranchRejectsAValueMatchingTheNestedNot(): void
    {
        $className = $this->generateClassFromFile('NestedNotInAllOfBranch.json');

        try {
            new $className(['p' => 5]);
            $this->fail('Expected AllOfException');
        } catch (AllOfException $exception) {
            $this->assertSame(
                <<<ERROR
                Invalid value for 'p' declined by composition constraint
                  Requires to match all composition elements but matched 1 element
                  - Composition element #1: Valid
                  - Composition element #2: Failed
                    * Invalid value for 'p' declined by composition constraint
                      Requires to match none composition element but matched 1 element
                      - Composition element #1: Valid
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * The if/then/else branches are built by IfValidatorFactory in a separate code path from
     * AbstractCompositionValidatorFactory::getCompositionProperties() but have the identical bug:
     * a "then" branch that is itself a bare anyOf (no "type" declared) must still be validated.
     */
    #[DataProvider('nestedAnyOfInThenBranchAcceptedDataProvider')]
    public function testNestedAnyOfInThenBranchAcceptsValuesSatisfyingTheCondition(mixed $propertyValue): void
    {
        $className = $this->generateClassFromFile('NestedAnyOfInThenBranch.json');

        $object = new $className(['p' => $propertyValue]);

        $this->assertSame($propertyValue, $object->getP());
    }

    public static function nestedAnyOfInThenBranchAcceptedDataProvider(): array
    {
        return [
            // not an integer -> the if-condition fails -> then never applies -> no constraint
            'a string is accepted because the if-condition fails' => ['hello'],
            // an integer satisfying the then-branch's anyOf (>= 10)
            'an integer satisfying the upper anyOf branch is accepted' => [15],
            // an integer satisfying the then-branch's anyOf (<= 0)
            'an integer satisfying the lower anyOf branch is accepted' => [-10],
        ];
    }

    /**
     * An integer satisfying neither anyOf branch of the then-composition. Before the fix the
     * nested anyOf was stripped from the "then" branch and any integer was silently accepted.
     */
    public function testNestedAnyOfInThenBranchRejectsAnIntegerFailingTheNestedAnyOf(): void
    {
        $className = $this->generateClassFromFile('NestedAnyOfInThenBranch.json');

        try {
            new $className(['p' => 5]);
            $this->fail('Expected ConditionalException');
        } catch (ConditionalException $exception) {
            $this->assertSame(
                <<<ERROR
                Invalid value for 'p' declined by conditional composition constraint
                  - Condition: Valid
                  - Conditional branch failed:
                    * Invalid value for 'p' declined by composition constraint
                      Requires to match at least one composition element
                      - Composition element #1: Failed
                        * Value for 'p' must not be smaller than 10
                      - Composition element #2: Failed
                        * Value for 'p' must not be larger than 0
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * Regression guard: a nested "if" inside an untyped allOf branch (no nested schema) was
     * already validated before the fix (ConditionalPropertyValidator was never filtered out) and
     * must remain validated after the fix. 5 satisfies the if-condition (>= 0) and the then-branch
     * (<= 10); it is also an integer, so both outer branches match.
     */
    public function testNestedIfInAllOfBranchUntypedAcceptsAValueSatisfyingTheThenBranch(): void
    {
        $className = $this->generateClassFromFile('NestedIfInAllOfBranchUntyped.json');

        $object = new $className(['p' => 5]);

        $this->assertSame(5, $object->getP());
    }

    /**
     * 15 satisfies the if-condition (>= 0) but violates the then-branch (<= 10); it is still an
     * integer, so 1 of 2 outer branches matched.
     */
    public function testNestedIfInAllOfBranchUntypedRejectsAValueViolatingTheThenBranch(): void
    {
        $className = $this->generateClassFromFile('NestedIfInAllOfBranchUntyped.json');

        try {
            new $className(['p' => 15]);
            $this->fail('Expected AllOfException');
        } catch (AllOfException $exception) {
            $this->assertSame(
                <<<ERROR
                Invalid value for 'p' declined by composition constraint
                  Requires to match all composition elements but matched 1 element
                  - Composition element #1: Failed
                    * Invalid value for 'p' declined by conditional composition constraint
                      - Condition: Valid
                      - Conditional branch failed:
                        * Value for 'p' must not be larger than 10
                  - Composition element #2: Valid
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * Regression guard for a nested "if" inside an anyOf branch that DOES resolve to an explicit
     * object (has a nested schema): its own generated class already re-validates the if/then on
     * instantiation, so the outer branch's ConditionalPropertyValidator is stripped here. kind = A
     * requires the "value" property; it is provided, so the object branch matches.
     */
    public function testNestedIfInAnyOfBranchWithNestedSchemaAcceptsAnObjectSatisfyingTheThenBranch(): void
    {
        $className = $this->generateClassFromFile('NestedIfInAnyOfBranchWithNestedSchema.json');

        $object = new $className(['p' => ['kind' => 'A', 'value' => 'hello']]);

        $this->assertNotNull($object->getP());
    }

    /**
     * kind = A requires the "value" property; it is missing, so the object branch fails to
     * instantiate, and the value is not an integer either -> no branch of the outer anyOf matches.
     */
    public function testNestedIfInAnyOfBranchWithNestedSchemaRejectsAnObjectViolatingTheThenBranch(): void
    {
        $className = $this->generateClassFromFile('NestedIfInAnyOfBranchWithNestedSchema.json');

        $this->expectException(AnyOfException::class);

        new $className(['p' => ['kind' => 'A']]);
    }
}
