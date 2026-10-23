<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Fixtures\RecordingLogger;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

#[ApplicableDrafts]
class BooleanCompositionSchemaTest extends AbstractPHPModelGeneratorTestCase
{
    public static function falseConditionalBranchDataProvider(): array
    {
        $propertyThenMessage = <<<ERROR
            Invalid value for 'value' declined by conditional composition constraint
              - Condition: Valid
              - Conditional branch failed:
                * Value for 'value' is not allowed
            ERROR;
        $propertyElseMessage = <<<ERROR
            Invalid value for 'value' declined by conditional composition constraint
              - Condition: Failed
                * Invalid type for 'value': requires 'string', got 'integer'
              - Conditional branch failed:
                * Value for 'value' is not allowed
            ERROR;
        $objectThenMessage = <<<ERROR
            Invalid value for '{class}' declined by conditional composition constraint
              - Condition: Valid
              - Conditional branch failed:
                * Value for '{class}' is not allowed
            ERROR;
        $objectElseMessage = <<<ERROR
            Invalid value for '{class}' declined by conditional composition constraint
              - Condition: Failed
                * Missing required value for 'kind'
              - Conditional branch failed:
                * Value for '{class}' is not allowed
            ERROR;

        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                // a string enters the then branch, any other value is valid
                'property then: false' => [
                    'IfStringThenFalse.json',
                    [],
                    [
                        [['value' => 'hello'], $propertyThenMessage],
                        [['value' => 42], null],
                        [[], null],
                    ],
                ],
                // a non-string enters the else branch, a string is valid
                'property else: false' => [
                    'IfStringElseFalse.json',
                    [],
                    [
                        [['value' => 'hello'], null],
                        [['value' => 42], $propertyElseMessage],
                        [[], null],
                    ],
                ],
                // the condition requires kind: kind is denied
                'object then: false' => [
                    'ObjectLevelComposition.json',
                    ['"if": {"required": ["kind"]}, "then": false'],
                    [
                        [[], null],
                        [['note' => 'n'], null],
                        [['kind' => 'k'], $objectThenMessage],
                    ],
                ],
                // the condition requires kind: kind is required
                'object else: false' => [
                    'ObjectLevelComposition.json',
                    ['"if": {"required": ["kind"]}, "else": false'],
                    [
                        [['kind' => 'k'], null],
                        [[], $objectElseMessage],
                        [['note' => 'n'], $objectElseMessage],
                    ],
                ],
            ],
        );
    }

    /**
     * A false then/else branch with a real if condition only rejects the values entering the branch, the values
     * the branch doesn't apply to are valid.
     *
     * Rejecting such a schema at generation time as unsatisfiable was wrong: e.g. if: {required: [kind]}, then: false
     * is the common way to deny a key under a condition.
     *
     * The complete message is asserted for direct exceptions. With collected errors the ConditionalException of the
     * production library renders a collected condition / branch error without the bullet ("Condition: Failed" is
     * directly followed by the error message), only the rejection is asserted there.
     */
    #[DataProvider('falseConditionalBranchDataProvider')]
    public function testFalseConditionalBranchRejectsOnlyTheValuesEnteringTheBranch(
        GeneratorConfiguration $configuration,
        string $schemaFile,
        array $templateValues,
        array $cases,
    ): void {
        $className = $this->generateClassFromFileTemplate($schemaFile, $templateValues, $configuration, false);
        $shortClassName = (new ReflectionClass($className))->getShortName();

        foreach ($cases as [$data, $expectedMessage]) {
            $exception = $this->catchException(static fn() => new $className($data));

            if ($expectedMessage === null) {
                $this->assertNull($exception, 'Valid data rejected: ' . json_encode($data));
                continue;
            }

            $this->assertNotNull($exception, 'Invalid data accepted: ' . json_encode($data));

            if ($configuration->collectErrors()) {
                $this->assertInstanceOf(ErrorRegistryException::class, $exception);
                $this->assertCount(1, $exception->getErrors());
                $this->assertInstanceOf(ConditionalException::class, $exception->getErrors()[0]);
                continue;
            }

            $this->assertInstanceOf(ConditionalException::class, $exception);
            $this->assertSame(str_replace('{class}', $shortClassName, $expectedMessage), $exception->getMessage());
        }
    }

    public static function falseBranchOfObjectLevelCompositionDataProvider(): array
    {
        $requiredNoteFailed = <<<ERROR
              - Composition element #1: Failed
                * Missing required value for 'note'
            ERROR;
        $falseBranchFailed = <<<ERROR
              - Composition element #2: Failed
                * Value for '{class}' is not allowed
            ERROR;

        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'oneOf false' => [
                    '"oneOf": [{"required": ["note"]}, false]',
                    true,
                    <<<ERROR
                    Invalid value for '{class}' declined by composition constraint
                      Requires to match one composition element but matched 0 elements
                    $requiredNoteFailed
                    $falseBranchFailed
                    ERROR,
                ],
                'oneOf not: true' => [
                    '"oneOf": [{"required": ["note"]}, {"not": true}]',
                    true,
                    <<<ERROR
                    Invalid value for '{class}' declined by composition constraint
                      Requires to match one composition element but matched 0 elements
                    $requiredNoteFailed
                      - Composition element #2: Failed
                        * Invalid value for '{nested}' declined by composition constraint
                          Requires to match none composition element but matched 1 element
                          - Composition element #1: Valid
                    ERROR,
                ],
                'oneOf nested anyOf false' => [
                    '"oneOf": [{"required": ["note"]}, {"anyOf": [false]}]',
                    true,
                    <<<ERROR
                    Invalid value for '{class}' declined by composition constraint
                      Requires to match one composition element but matched 0 elements
                    $requiredNoteFailed
                      - Composition element #2: Failed
                        * Invalid value for '{nested}' declined by composition constraint
                          Requires to match at least one composition element
                          - Composition element #1: Failed
                            * Value for '{nested}' is not allowed
                    ERROR,
                ],
                'anyOf false' => [
                    '"anyOf": [{"required": ["note"]}, false]',
                    true,
                    <<<ERROR
                    Invalid value for '{class}' declined by composition constraint
                      Requires to match at least one composition element
                    $requiredNoteFailed
                    $falseBranchFailed
                    ERROR,
                ],
                'allOf false' => [
                    '"allOf": [false]',
                    false,
                    <<<ERROR
                    Invalid value for '{class}' declined by composition constraint
                      Requires to match all composition elements but matched 0 elements
                      - Composition element #1: Failed
                        * Value for '{class}' is not allowed
                    ERROR,
                ],
                'not: true' => [
                    '"not": true',
                    false,
                    <<<ERROR
                    Invalid value for '{class}' declined by composition constraint
                      Requires to match none composition element but matched 1 element
                      - Composition element #1: Valid
                    ERROR,
                ],
            ],
        );
    }

    /**
     * A false branch (also a branch only containing not: true or a composition of false branches) of a composition
     * of the schema itself never matches: the validated object is always present. Only the other branch may match.
     *
     * Checking the presence of a key named like the class (like the false branch of a property checks the presence of
     * the property) was rejected: the false branch matched any object.
     */
    #[DataProvider('falseBranchOfObjectLevelCompositionDataProvider')]
    public function testFalseBranchOfObjectLevelCompositionNeverMatches(
        GeneratorConfiguration $configuration,
        string $composition,
        bool $noteMatchesOtherBranch,
        string $expectedMessage,
    ): void {
        $className = $this->generateClassFromFileTemplate(
            'ObjectLevelComposition.json',
            [$composition],
            $configuration,
            false,
        );

        // the nested branch class names contain a hash
        $expectedMessagePattern = '/^' . str_replace(
            [preg_quote('{class}', '/'), preg_quote('{nested}', '/')],
            [preg_quote((new ReflectionClass($className))->getShortName(), '/'), '\w+'],
            preg_quote($expectedMessage, '/'),
        ) . '$/';

        $invalidData = [[], ['kind' => 'k'], ...($noteMatchesOtherBranch ? [] : [['note' => 'n']])];

        foreach ($invalidData as $data) {
            $exception = $this->catchException(static fn() => new $className($data));

            $this->assertNotNull($exception, 'Invalid data accepted: ' . json_encode($data));
            $this->assertInstanceOf(
                $configuration->collectErrors() ? ErrorRegistryException::class : ValidationException::class,
                $exception,
            );
            $this->assertMatchesRegularExpression($expectedMessagePattern, $exception->getMessage());
        }

        if ($noteMatchesOtherBranch) {
            $object = new $className(['note' => 'n']);
            $this->assertSame('n', $object->getNote());
        }
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
        $this->expectValidationError($configuration, "Invalid value for 'value'");

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
                // then: false, else: false — whichever branch applies fails; ConditionalException is thrown
                'then:false else:false, str' => ['IfStringThenFalseElseFalse.json', 'hello'],
                'then:false else:false, int' => ['IfStringThenFalseElseFalse.json', 42],
            ],
        );
    }

    #[DataProvider('alwaysFalseAbsentPropertyDataProvider')]
    public function testAlwaysFalseCompositionAllowsAbsentProperty(string $schemaFile, string $expectedReason): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setLogger($recordingLogger),
        );

        $this->assertTrue(
            $this->hasLogEntry(
                $recordingLogger->getEntries(),
                'warning',
                "Always-unsatisfiable schema for property '{property}': {reason}",
                ['property' => 'value', 'reason' => $expectedReason],
            ),
            'Expected an always-unsatisfiable warning for the value property.',
        );

        $object = new $className([]);
        $this->assertNull($object->getValue());
    }

    public static function alwaysFalseAbsentPropertyDataProvider(): array
    {
        return [
            // allOf: false branch makes composition unsatisfiable
            'allOf false branch'  => [
                'AllOfFalseBranch.json',
                'allOf contains a false branch which can never be satisfied',
            ],
            // anyOf: all false — no branch can ever satisfy
            'anyOf all false'     => [
                'AnyOfAllFalse.json',
                'all anyOf branches are false; no value can satisfy the schema',
            ],
            // oneOf: all false — no branch can ever satisfy
            'oneOf all false'     => [
                'OneOfAllFalse.json',
                'all oneOf branches are false; no value can satisfy the schema',
            ],
            // not: true — negation of always-valid schema
            'not true'            => [
                'NotTrue.json',
                'not: true negates the always-valid schema; no value is accepted',
            ],
            // if: false, else: false — always unsatisfiable
            'if false else false' => [
                'IfFalseElseFalse.json',
                'if: false with else: false means the composition is always unsatisfiable',
            ],
            // if: true, then: false — always unsatisfiable
            'if true then false'  => [
                'IfTrueThenFalse.json',
                'if: true with then: false means the composition is always unsatisfiable',
            ],
            // then: false, else: false — always unsatisfiable, whichever branch applies
            'then false else false' => [
                'IfStringThenFalseElseFalse.json',
                'then: false with else: false means the composition is always unsatisfiable',
            ],
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
            $this->expectValidationError($configuration, "Invalid value for 'value'");
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
            $this->expectValidationError($configuration, "Invalid value for 'value'");
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
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'IfFalseNoElse.json',
            (new GeneratorConfiguration())->setLogger($recordingLogger),
        );

        $this->assertTrue(
            $this->hasLogEntry(
                $recordingLogger->getEntries(),
                'warning',
                "if: false for property '{property}' — then branch will never apply (condition never"
                    . " matches); no constraint generated.",
                ['property' => 'value'],
            ),
            'Expected an "if: false" warning for the value property.',
        );

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
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'IfTrueNoThen.json',
            (new GeneratorConfiguration())->setLogger($recordingLogger),
        );

        $this->assertTrue(
            $this->hasLogEntry(
                $recordingLogger->getEntries(),
                'warning',
                "if: true for property '{property}' — else branch will never apply (condition always"
                    . " matches); no constraint generated.",
                ['property' => 'value'],
            ),
            'Expected an "if: true" warning for the value property.',
        );

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
