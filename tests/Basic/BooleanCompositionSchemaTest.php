<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class BooleanCompositionSchemaTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * if: {type: string}, then: false or else: false with a real if condition is an unsatisfiable
     * composition that the generator detects and rejects at generation time.
     */
    #[DataProvider('unsatisfiableConditionalDataProvider')]
    public function testUnsatisfiableConditionalThrowsSchemaException(
        string $schemaFile,
        string $expectedMessagePattern,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches($expectedMessagePattern);
        $this->generateClassFromFile($schemaFile);
    }

    public static function unsatisfiableConditionalDataProvider(): array
    {
        return [
            // if: {string}, then: false — any string value would always fail the then branch
            'if:string then:false' => ['IfStringThenFalse.json', '/then: false is unsatisfiable for property value/i'],
            // if: {string}, else: false — any non-string would always fail the else branch
            'if:string else:false' => ['IfStringElseFalse.json', '/else: false is unsatisfiable for property value/i'],
        ];
    }

    /**
     * When a composition is always unsatisfiable (all branches are always-false, or not/if
     * structures that can never be satisfied), the generator routes through the composition
     * framework so a proper composition exception is thrown at runtime.
     *
     * - allOf/anyOf/oneOf: AllOf/AnyOf/OneOfException ("Invalid value for value")
     * - not: true: NotException ("Invalid value for value")
     * - if/then/else always-false: ConditionalException ("Invalid value for value")
     */
    #[DataProvider('alwaysFalseCompositionExceptionDataProvider')]
    public function testAlwaysFalseCompositionThrowsCompositionException(
        GeneratorConfiguration $configuration,
        string $schemaFile,
        mixed $value,
    ): void {
        $this->expectValidationError($configuration, 'Invalid value for value');

        $className = $this->generateClassFromFile($schemaFile, $configuration);
        new $className(['value' => $value]);
    }

    public static function alwaysFalseCompositionExceptionDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                // allOf: any false branch makes the whole schema unsatisfiable
                'allOf false+real, string'   => ['AllOfFalseBranch.json', 'hello'],
                'allOf false+real, int'      => ['AllOfFalseBranch.json', 42],
                'allOf false+real, null'     => ['AllOfFalseBranch.json', null],
                // anyOf: all false branches — no branch can ever satisfy
                'anyOf all false, string'    => ['AnyOfAllFalse.json', 'hello'],
                'anyOf all false, int'       => ['AnyOfAllFalse.json', 42],
                // oneOf: all false branches — no branch can ever satisfy
                'oneOf all false, string'    => ['OneOfAllFalse.json', 'hello'],
                'oneOf all false, int'       => ['OneOfAllFalse.json', 42],
                // not: true — negation of always-valid schema; NotException is thrown
                'not true, string'           => ['NotTrue.json', 'hello'],
                'not true, int'              => ['NotTrue.json', 42],
                // if: false, else: false — always-unsatisfiable; ConditionalException is thrown
                'if:false else:false, str'   => ['IfFalseElseFalse.json', 'hello'],
                'if:false else:false, int'   => ['IfFalseElseFalse.json', 42],
                // if: true, then: false — always-unsatisfiable; ConditionalException is thrown
                'if:true then:false, str'    => ['IfTrueThenFalse.json', 'hello'],
                'if:true then:false, int'    => ['IfTrueThenFalse.json', 42],
            ],
        );
    }

    #[DataProvider('alwaysFalseAbsentPropertyDataProvider')]
    public function testAlwaysFalseCompositionAllowsAbsentProperty(string $schemaFile): void
    {
        $className = $this->generateClassFromFile($schemaFile);
        $object = new $className([]);
        $this->assertNull($object->getValue());
    }

    public static function alwaysFalseAbsentPropertyDataProvider(): array
    {
        return [
            // allOf: false branch makes composition unsatisfiable
            'allOf false branch'        => ['AllOfFalseBranch.json'],
            // anyOf: all false — no branch can ever satisfy
            'anyOf all false'           => ['AnyOfAllFalse.json'],
            // oneOf: all false — no branch can ever satisfy
            'oneOf all false'           => ['OneOfAllFalse.json'],
            // not: true — negation of always-valid schema
            'not true'                  => ['NotTrue.json'],
            // if: false, else: false — always unsatisfiable
            'if false else false'       => ['IfFalseElseFalse.json'],
            // if: true, then: false — always unsatisfiable
            'if true then false'        => ['IfTrueThenFalse.json'],
        ];
    }

    /**
     * Boolean branches participate in the composition and affect validation outcomes.
     *
     * - false branches always fail (counted as a failing element)
     * - true branches always succeed (counted as a passing element)
     *
     * This tests scenarios where one boolean branch coexists with a real schema branch, and
     * verifies the composition exception fires when the overall composition constraint is unmet.
     * Covers allOf (true branch), anyOf/oneOf (false branch), and if/then/else with boolean if.
     */
    #[DataProvider('booleanBranchParticipatesDataProvider')]
    public function testBooleanBranchParticipatesInComposition(
        GeneratorConfiguration $configuration,
        string $schemaFile,
        mixed $value,
        bool $valid,
    ): void {
        if (!$valid) {
            $this->expectValidationError($configuration, 'Invalid value for value');
        }

        $className = $this->generateClassFromFile($schemaFile, $configuration);
        $object = new $className(['value' => $value]);
        $this->assertSame($value, $object->getValue());
    }

    public static function booleanBranchParticipatesDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                // allOf: true branch always passes; real string branch is still enforced because
                // allOf requires ALL branches to pass
                'allOf true+real, valid string'      => ['AllOfTrueBranch.json', 'hello', true],
                'allOf true+real, invalid int'        => ['AllOfTrueBranch.json', 42, false],
                // anyOf: false branch always fails; real string branch is still enforced because
                // anyOf requires at LEAST ONE branch to pass
                'anyOf false+real, valid string'      => ['AnyOfFalseBranchWithReal.json', 'hello', true],
                'anyOf false+real, invalid int'       => ['AnyOfFalseBranchWithReal.json', 42, false],
                // oneOf: false branch always fails; real string branch is still enforced because
                // oneOf requires EXACTLY ONE branch to pass (false branch never counts)
                'oneOf false+real, valid string'      => ['OneOfFalseBranchWithReal.json', 'hello', true],
                'oneOf false+real, invalid int'       => ['OneOfFalseBranchWithReal.json', 42, false],
                // if: false, else: string → else always applies
                'if:false else:string, valid string'  => ['IfFalseElseString.json', 'hello', true],
                'if:false else:string, invalid int'   => ['IfFalseElseString.json', 42, false],
                // if: true, then: string → then always applies
                'if:true then:string, valid string'   => ['IfTrueThenString.json', 'hello', true],
                'if:true then:string, invalid int'    => ['IfTrueThenString.json', 42, false],
            ],
        );
    }

    /**
     * A true branch in anyOf/oneOf participates in the composition count and appears in
     * exception messages when the overall composition constraint is violated.
     *
     * - anyOf: [true, real] always passes because true always satisfies "at least one" — the real
     *   branch becomes irrelevant for pass/fail, but true IS counted as a matched element.
     * - oneOf: [true, real] with a value that ALSO satisfies the real branch causes BOTH branches
     *   to match, violating "exactly one" — the true branch is counted and pushes the match count
     *   to 2, surfacing a OneOfException even for otherwise-valid values.
     */
    #[DataProvider('trueBranchCompositionDataProvider')]
    public function testTrueBranchParticipatesInComposition(
        GeneratorConfiguration $configuration,
        string $schemaFile,
        mixed $value,
        bool $valid,
    ): void {
        if (!$valid) {
            $this->expectValidationError($configuration, 'Invalid value for value');
        }

        $className = $this->generateClassFromFile($schemaFile, $configuration);
        $object = new $className(['value' => $value]);
        $this->assertSame($value, $object->getValue());
    }

    public static function trueBranchCompositionDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                // anyOf: true branch always satisfies "at least one" — even a value that would
                // fail the real branch passes the overall anyOf
                'anyOf true+real, valid string'  => ['AnyOfTrueBranchWithReal.json', 'hello', true],
                'anyOf true+real, invalid int'   => ['AnyOfTrueBranchWithReal.json', 42, true],
                // oneOf: true branch always matches, so any value that also matches the real
                // string branch makes TWO branches succeed → OneOfException (need exactly one)
                'oneOf true+real, valid string'  => ['OneOfTrueBranchWithReal.json', 'hello', false],
                // int does not match the real string branch, so only true matches → exactly one → valid
                'oneOf true+real, invalid int'   => ['OneOfTrueBranchWithReal.json', 42, true],
            ],
        );
    }

    public function testNotFalseAcceptsAnyValue(): void
    {
        $className = $this->generateClassFromFile('NotFalse.json');

        $object = new $className(['value' => 'hello']);
        $this->assertSame('hello', $object->getValue());

        $object2 = new $className(['value' => 42]);
        $this->assertSame(42, $object2->getValue());
    }

    public function testIfStringThenTrueAcceptsStringWithNoConstraint(): void
    {
        $className = $this->generateClassFromFile('IfStringThenTrue.json');

        // string matches if, then: true imposes no constraint
        $object = new $className(['value' => 'hello']);
        $this->assertSame('hello', $object->getValue());

        // int does not match if, no else to apply
        $object2 = new $className(['value' => 42]);
        $this->assertSame(42, $object2->getValue());
    }

    public function testIfStringElseTrueAcceptsNonStringWithNoConstraint(): void
    {
        $className = $this->generateClassFromFile('IfStringElseTrue.json');

        // string matches if, no then to apply
        $object = new $className(['value' => 'hello']);
        $this->assertSame('hello', $object->getValue());

        // int does not match if, else: true imposes no constraint
        $object2 = new $className(['value' => 42]);
        $this->assertSame(42, $object2->getValue());
    }

    /**
     * if: false, else: true — condition never matches so then never applies; else always applies
     * but true imposes no constraint. The whole if/then/else imposes no constraint on the property.
     */
    public function testIfFalseElseTrueImposesNoConstraint(): void
    {
        $className = $this->generateClassFromFile('IfFalseElseTrue.json');

        $object = new $className(['value' => 'hello']);
        $this->assertSame('hello', $object->getValue());

        $object2 = new $className(['value' => 42]);
        $this->assertSame(42, $object2->getValue());

        $object3 = new $className([]);
        $this->assertNull($object3->getValue());
    }

    /**
     * if: false with a then but no else — condition never matches so then never applies.
     * The whole if/then/else imposes no constraint on the property.
     */
    public function testIfFalseWithThenButNoElseImposesNoConstraint(): void
    {
        $className = $this->generateClassFromFile('IfFalseNoElse.json');

        $object = new $className(['value' => 'hello']);
        $this->assertSame('hello', $object->getValue());

        $object2 = new $className(['value' => 42]);
        $this->assertSame(42, $object2->getValue());

        // Absent property is also fine
        $object3 = new $className([]);
        $this->assertNull($object3->getValue());
    }

    /**
     * if: true with an else but no then — condition always matches so else never applies.
     * The whole if/then/else imposes no constraint on the property.
     */
    public function testIfTrueWithElseButNoThenImposesNoConstraint(): void
    {
        $className = $this->generateClassFromFile('IfTrueNoThen.json');

        $object = new $className(['value' => 'hello']);
        $this->assertSame('hello', $object->getValue());

        $object2 = new $className(['value' => 42]);
        $this->assertSame(42, $object2->getValue());

        $object3 = new $className([]);
        $this->assertNull($object3->getValue());
    }

    /**
     * allOf: [true, true] — all branches always succeed; the composition imposes no constraint.
     */
    public function testAllOfOnlyTrueBranchesAcceptsAnything(): void
    {
        $className = $this->generateClassFromFile('AllOfTrueOnly.json');

        $object = new $className(['value' => 'hello']);
        $this->assertSame('hello', $object->getValue());

        $object2 = new $className(['value' => 42]);
        $this->assertSame(42, $object2->getValue());

        $object3 = new $className([]);
        $this->assertNull($object3->getValue());
    }
}
