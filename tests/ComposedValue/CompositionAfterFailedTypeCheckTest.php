<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A value which fails the type check of its property is reported with the type error only. The compositions of the
 * property are not evaluated for it: every branch inherits the type of the property and would restate the very same
 * type error as a failure of the branch.
 */
class CompositionAfterFailedTypeCheckTest extends AbstractPHPModelGeneratorTestCase
{
    private const array VALID_INPUT = [
        'allOfString' => 'abc',
        'anyOfInteger' => 7,
        'oneOfString' => 'abcd',
        'notString' => 'y',
        'conditionalString' => 'abc',
        'allOfArray' => ['item'],
    ];

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('wrongTypeDataProvider')]
    public function testOnlyTheTypeErrorIsReportedForAValueOfAWrongType(
        array $input,
        string $expectedMessage,
    ): void {
        $configuration = (new GeneratorConfiguration())->setCollectErrors(true);
        $className = $this->generateClassFromFile('CompositionsOnTypedProperties.json', $configuration);

        $this->assertInstantiationFailsWithMessage(
            $className,
            array_merge(self::VALID_INPUT, $input),
            $this->getExpectedExceptionClass($configuration, InvalidTypeException::class),
            $expectedMessage,
        );
    }

    public static function wrongTypeDataProvider(): array
    {
        return [
            'allOf' => [
                ['allOfString' => 5],
                "Invalid type for 'allOfString': requires 'string', got 'integer'",
            ],
            'anyOf' => [
                ['anyOfInteger' => 'text'],
                "Invalid type for 'anyOfInteger': requires 'int', got 'string'",
            ],
            'oneOf' => [
                ['oneOfString' => 5.5],
                "Invalid type for 'oneOfString': requires 'string', got 'double'",
            ],
            'not' => [
                ['notString' => 5],
                "Invalid type for 'notString': requires 'string', got 'integer'",
            ],
            'if then else' => [
                ['conditionalString' => 5],
                "Invalid type for 'conditionalString': requires 'string', got 'integer'",
            ],
            'allOf on an array' => [
                ['allOfArray' => 'text'],
                "Invalid type for 'allOfArray': requires 'array', got 'string'",
            ],
            'several properties' => [
                ['allOfString' => 5, 'anyOfInteger' => 'text', 'conditionalString' => true],
                <<<'MSG'
                Invalid type for 'allOfString': requires 'string', got 'integer'
                Invalid type for 'anyOfInteger': requires 'int', got 'string'
                Invalid type for 'conditionalString': requires 'string', got 'boolean'
                MSG,
            ],
        ];
    }

    /**
     * Only the compositions are skipped for a value of a wrong type. Stopping the validation of the property after the
     * failed type check was rejected: enum and const are violated by the value independent of the type check, their
     * errors are part of the report.
     */
    public function testKeywordsWhichDoNotDependOnTheTypeAreStillReportedForAValueOfAWrongType(): void
    {
        $configuration = (new GeneratorConfiguration())->setCollectErrors(true);
        $className = $this->generateClassFromFile('CompositionsOnTypedProperties.json', $configuration);

        $this->assertInstantiationFailsWithMessage(
            $className,
            array_merge(self::VALID_INPUT, ['enumString' => 5, 'constString' => 5]),
            $this->getExpectedExceptionClass($configuration, InvalidTypeException::class),
            <<<'MSG'
            Invalid type for 'enumString': requires 'string', got 'integer'
            Value for 'enumString' must be one of ["a","b",null], got 5
            Invalid type for 'constString': requires 'string', got 'integer'
            Value for 'constString' must be "x", got 5
            MSG,
        );
    }

    /**
     * The compositions are still evaluated for a value of the right type: the skipped evaluation must not hide an
     * error which is not the type error.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testCompositionsAreEvaluatedForAValueOfTheRightType(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('CompositionsOnTypedProperties.json', $configuration);

        $object = new $className(self::VALID_INPUT);
        $this->assertSame('abc', $object->getAllOfString());

        // The type guard is rendered for collected errors only: with early return the type check throws before the
        // composition is reached and the guard would be dead code
        $generatedCode = implode('', array_map('file_get_contents', $this->getGeneratedFiles()));
        $this->assertSame(
            $configuration->collectErrors(),
            preg_match('/is_string\(\$value\)[^\n]* &&\n\s+\(function \(&\$value\)/', $generatedCode) === 1,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            array_merge(self::VALID_INPUT, ['allOfString' => 'b']),
            $this->getExpectedExceptionClass($configuration, AllOfException::class),
            <<<'MSG'
            Invalid value for 'allOfString' declined by composition constraint
              Requires to match all composition elements but matched 0 elements
              - Composition element #1: Failed
                * Value for 'allOfString' must not be shorter than 2
              - Composition element #2: Failed
                * Value for 'allOfString' does not match pattern '^a'
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            array_merge(self::VALID_INPUT, ['anyOfInteger' => 0]),
            $this->getExpectedExceptionClass($configuration, AnyOfException::class),
            <<<'MSG'
            Invalid value for 'anyOfInteger' declined by composition constraint
              Requires to match at least one composition element
              - Composition element #1: Failed
                * Value for 'anyOfInteger' must not be smaller than 5
              - Composition element #2: Failed
                * Value for 'anyOfInteger' must not be larger than -5
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            array_merge(self::VALID_INPUT, ['oneOfString' => 'ab']),
            $this->getExpectedExceptionClass($configuration, OneOfException::class),
            <<<'MSG'
            Invalid value for 'oneOfString' declined by composition constraint
              Requires to match one composition element but matched 0 elements
              - Composition element #1: Failed
                * Value for 'oneOfString' must not be shorter than 3
              - Composition element #2: Failed
                * Value for 'oneOfString' must not be longer than 1
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            array_merge(self::VALID_INPUT, ['conditionalString' => 'xyz']),
            $this->getExpectedExceptionClass($configuration, ConditionalException::class),
            <<<'MSG'
            Invalid value for 'conditionalString' declined by conditional composition constraint
              - Condition: Valid
              - Conditional branch failed:
                * Value for 'conditionalString' does not match pattern '^a'
            MSG,
        );
    }
}
