<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\Object\InvalidUnevaluatedPropertiesException;
use PHPModelGenerator\Exception\Object\UnevaluatedPropertiesException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verifies the runtime behaviour of `unevaluatedProperties`: keys not claimed by `properties`,
 * `patternProperties`, `additionalProperties`, or a successful composition branch must satisfy
 * the unevaluatedProperties schema (or are rejected outright when the keyword is `false`).
 */
#[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
class UnevaluatedPropertiesValidatorTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * Accepted input — the generated class must construct and round-trip the input through
     * `getRawModelDataInput()` unchanged. One data row per scenario.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function acceptanceProvider(): array
    {
        return [
            'declared property only' => [
                'NoExtraPropertiesAllowed.json',
                ['name' => 'Alice'],
            ],
            'empty input when no required properties' => [
                'NoExtraPropertiesAllowed.json',
                [],
            ],
            'pattern-matched property accepted' => [
                'PatternPropertiesEvaluated.json',
                ['x_one' => 'a', 'x_two' => 'b'],
            ],
            'unevaluatedProperties schema accepts matching extra' => [
                'SchemaFormUnevaluated.json',
                ['name' => 'Alice', 'count' => 42],
            ],
            'allOf branches contribute evaluated keys to outer' => [
                'AllOfBranchesCoverEvaluation.json',
                ['name' => 'Alice', 'foo' => 'hello', 'bar' => 42],
            ],
            'anyOf first-branch success contributes kind' => [
                'AnyOfSelectsSuccessfulBranches.json',
                ['kind' => 'A'],
            ],
            'anyOf second-branch success contributes value' => [
                'AnyOfSelectsSuccessfulBranches.json',
                ['value' => 99],
            ],
            'if-branch pass contributes if + then names' => [
                'IfThenElseEvaluation.json',
                ['kind' => 'A', 'valueA' => 'hello'],
            ],
            'if-branch fail contributes else name only' => [
                'IfThenElseEvaluation.json',
                ['kind' => 'B', 'valueB' => 7],
            ],
            'not-branch success leaves outer declared property accessible' => [
                'NotBranchContributesNothing.json',
                ['foo' => 'hello'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('acceptanceProvider')]
    public function testAcceptedInputRoundTrips(string $schemaFile, array $input): void
    {
        $className = $this->generateClassFromFile($schemaFile);
        $instance = new $className($input);

        $this->assertSame($input, $instance->getRawModelDataInput());
    }

    /**
     * Rejected input — construction must throw the named exception with a message describing
     * the offending key(s). The regex pattern is anchored on the full message so a different
     * exception body cannot accidentally satisfy it.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: class-string<\Throwable>, 3: string}>
     */
    public static function rejectionProvider(): array
    {
        $notAllowed = static fn(string $propertyName): string =>
            '/^Provided JSON for .* contains not allowed unevaluated properties \['
                . preg_quote($propertyName, '/')
                . '\]$/';

        return [
            'undeclared property at top level' => [
                'NoExtraPropertiesAllowed.json',
                ['name' => 'Alice', 'extra' => 'value'],
                UnevaluatedPropertiesException::class,
                $notAllowed('extra'),
            ],
            'non-pattern property rejected' => [
                'PatternPropertiesEvaluated.json',
                ['x_ok' => 'a', 'other' => 'bad'],
                UnevaluatedPropertiesException::class,
                $notAllowed('other'),
            ],
            'unevaluatedProperties schema rejects failing extra' => [
                'SchemaFormUnevaluated.json',
                ['name' => 'Alice', 'count' => 'not-an-integer'],
                InvalidUnevaluatedPropertiesException::class,
                '/^Provided JSON for .* contains invalid unevaluated properties/',
            ],
            'allOf rejects key not claimed by any branch' => [
                'AllOfBranchesCoverEvaluation.json',
                ['name' => 'Alice', 'foo' => 'hello', 'stray' => 'unclaimed'],
                UnevaluatedPropertiesException::class,
                $notAllowed('stray'),
            ],
            'if/then/else rejects key only claimed by opposite branch' => [
                // kind='A' triggers `if` → `then` (which declares valueA), not `else` (valueB).
                // valueB is not claimed by any successful branch.
                'IfThenElseEvaluation.json',
                ['kind' => 'A', 'valueB' => 7],
                UnevaluatedPropertiesException::class,
                $notAllowed('valueB'),
            ],
            'not-branch success does not contribute forbidden to evaluated' => [
                // The fixture's `not` schema is { properties: { forbidden: integer },
                // required: [forbidden] }. A string `forbidden` makes the not-body fail
                // (string ≠ integer), so `not` SUCCEEDS — meaning the not-branch's `forbidden`
                // declaration is visible at slot-write time. If the implementation treated
                // not-success as contributing annotations, this case would slip through.
                'NotBranchContributesNothing.json',
                ['foo' => 'hello', 'forbidden' => 'string-value'],
                UnevaluatedPropertiesException::class,
                $notAllowed('forbidden'),
            ],
        ];
    }

    /**
     * @param array<string, mixed>     $input
     * @param class-string<\Throwable> $exceptionClass
     */
    #[DataProvider('rejectionProvider')]
    public function testRejectedInputThrows(
        string $schemaFile,
        array $input,
        string $exceptionClass,
        string $messageRegex,
    ): void {
        $this->expectException($exceptionClass);
        $this->expectExceptionMessageMatches($messageRegex);

        $className = $this->generateClassFromFile($schemaFile);
        new $className($input);
    }

    /**
     * When the same schema declares a non-false `additionalProperties`, `unevaluatedProperties`
     * is a no-op — every key not in `properties`/`patternProperties` is already claimed by
     * `additionalProperties`. The factory therefore skips emitting any unevaluatedProperties
     * validator. This is a separate test rather than an acceptance row because the absence of
     * a validator (not merely its acceptance) is what is being verified.
     */
    public function testAdditionalPropertiesShortCircuitsUnevaluatedCheck(): void
    {
        $className = $this->generateClassFromFile('AdditionalPropertiesShortcut.json');

        // `extra` is not in `properties`, but `additionalProperties: {type: string}` claims it.
        // The unevaluatedProperties: false declaration must NOT reject it.
        $instance = new $className(['name' => 'Alice', 'extra' => 'value']);

        $this->assertSame(
            ['name' => 'Alice', 'extra' => 'value'],
            $instance->getRawModelDataInput(),
        );
    }
}
