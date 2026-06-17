<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Object\InvalidUnevaluatedPropertiesException;
use PHPModelGenerator\Exception\Object\RequiredValueException;
use PHPModelGenerator\Exception\Object\UnevaluatedPropertiesException;
use PHPModelGenerator\Model\GeneratorConfiguration;
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
     * `meta()->rawInput()` unchanged. One data row per scenario.
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

        $this->assertSame($input, $instance->meta()->rawInput());
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
            $instance->meta()->rawInput(),
        );
    }

    /**
     * Required and unevaluated both fire when a required property is missing AND an undeclared
     * extra is present. Two passes on the same generated schema:
     *
     *   - Direct-exception mode: the constructor runs _executeBaseValidators() →
     *     _executePostCompositionValidators() → per-property processing in that order. The
     *     unevaluated check lives in the post-composition phase and surfaces first because the
     *     required check is part of per-property processing, which runs last. Documents the
     *     actual surfacing order so a future refactor that moves required earlier surfaces as
     *     a deliberate change instead of a silent regression.
     *
     *   - Collect-errors mode: both errors accumulate in the registry. Asserts each
     *     exception's class identity and its full message so the registry's contents are
     *     pinned to the spec-relevant identifiers.
     */
    public function testRequiredAndUnevaluatedBothFireWhenBothAreViolated(): void
    {
        // Direct-exception mode.
        $directClassName = $this->generateClassFromFile('RequiredPropertyFailsFirst.json');

        try {
            new $directClassName(['extra' => 'value']);
            $this->fail('Expected the unevaluated check to surface in direct-exception mode');
        } catch (UnevaluatedPropertiesException $exception) {
            $this->assertMatchesRegularExpression(
                '/^Provided JSON for \S+ contains not allowed unevaluated properties \[extra\]$/',
                $exception->getMessage(),
            );
            $this->assertSame(['extra'], $exception->getUnevaluatedProperties());
        }

        // Collect-errors mode — both errors must land in the registry.
        $collectClassName = $this->generateClassFromFile(
            'RequiredPropertyFailsFirst.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        try {
            new $collectClassName(['extra' => 'value']);
            $this->fail('Expected the error registry to throw in collect-errors mode');
        } catch (ErrorRegistryException $registry) {
            $errors = $registry->getErrors();

            $requiredErrors = array_values(array_filter(
                $errors,
                static fn(\Throwable $error): bool => $error instanceof RequiredValueException,
            ));
            $unevaluatedErrors = array_values(array_filter(
                $errors,
                static fn(\Throwable $error): bool => $error instanceof UnevaluatedPropertiesException,
            ));

            $this->assertCount(1, $requiredErrors, 'expected one RequiredValueException');
            $this->assertSame('Missing required value for name', $requiredErrors[0]->getMessage());

            $this->assertCount(1, $unevaluatedErrors, 'expected one UnevaluatedPropertiesException');
            $this->assertMatchesRegularExpression(
                '/^Provided JSON for \S+ contains not allowed unevaluated properties \[extra\]$/',
                $unevaluatedErrors[0]->getMessage(),
            );
        }
    }

    /**
     * The JSON Schema spec defines `default` as annotation-only: the keyword does not modify
     * the instance during validation. Generators that materialise the default into a PHP field
     * after validation must keep validation operating on the JSON instance view — otherwise a
     * declared-but-absent property with a default would erroneously appear as evaluated to an
     * enclosing `unevaluatedProperties` keyword, and worse, leak into `meta()->rawInput()` as
     * a key the caller never supplied.
     *
     * Two assertions on one generated class: construction with the property absent succeeds and
     * `meta()->rawInput()` reflects only what the caller passed in; the property getter
     * returns the default value so the PHP field side of the contract is also intact.
     */
    public function testDefaultValueIsNotPartOfEvaluatedSet(): void
    {
        $className = $this->generateClassFromFile('DefaultsNotEvaluated.json');
        $instance = new $className(['name' => 'Alice']);

        // The instance view used by validation never includes `timeout`, so
        // unevaluatedProperties: false accepts the construction.
        $this->assertSame(['name' => 'Alice'], $instance->meta()->rawInput());

        // The PHP property side still surfaces the materialised default — the spec only forbids
        // the default from entering the JSON instance view, not the language-level field.
        $this->assertSame(30, $instance->getTimeout());
    }

    /**
     * Empty `allOf: []` is spec-legal — the schema's existing
     * `AbstractCompositionValidatorFactory::warnIfEmpty()` mechanism emits a warning at code-
     * generation time when output is enabled, but the schema still compiles and runs. With no
     * composition branches to contribute evaluated keys, the outer unevaluatedProperties:
     * false sees only the local `properties` declarations.
     *
     * Two scenarios on the same generated class:
     *   - declared key accepted on its own — assertion on `meta()->rawInput()`;
     *   - any extra key rejected because the empty composition cannot claim it for the
     *     accumulator — assertions on the exception message and the offending key list.
     */
    public function testEmptyAllOfStillEnforcesUnevaluatedAtOuterLevel(): void
    {
        $className = $this->generateClassFromFile('EmptyAllOf.json');

        $accepted = new $className(['name' => 'Alice']);
        $this->assertSame(['name' => 'Alice'], $accepted->meta()->rawInput());

        try {
            new $className(['name' => 'Alice', 'extra' => 'value']);
            $this->fail('Empty allOf must not rescue extras from unevaluatedProperties: false');
        } catch (UnevaluatedPropertiesException $exception) {
            $this->assertMatchesRegularExpression(
                '/^Provided JSON for \S+ contains not allowed unevaluated properties \[extra\]$/',
                $exception->getMessage(),
            );
            $this->assertSame(['extra'], $exception->getUnevaluatedProperties());
        }
    }

    /**
     * A composition branch that declares its own `additionalProperties: {schema}` contributes
     * the keys it validated against that schema to the outer accumulator. Two assertions on the
     * same generated class:
     *   - `{kind: "X", extra: 1}` exercises the success path: branch 0's additionalProperties
     *     validates `extra` against `type: integer` and credits `extra` to its evaluated set;
     *     the outer unevaluatedProperties: false sees `extra` as evaluated and accepts.
     *   - `{kind: "X", extra: "bar"}` exercises the failure path: branch 0's additionalProperties
     *     rejects `extra`'s value, so branch 0 records `success: false` and contributes no
     *     evaluated keys. Branch 1 still succeeds (no constraint on extras at all means it makes
     *     no claim either) so anyOf passes — but the outer accumulator no longer credits `extra`
     *     and unevaluatedProperties: false rejects.
     *
     * The failure surface here is the outer unevaluated check, not the branch-internal
     * additionalProperties — that internal failure is silently swallowed by the branch's
     * try/catch, which is exactly the behaviour the per-key validity signal preserves.
     */
    public function testBranchLevelAdditionalPropertiesFeedsEvaluatedSetThroughComposition(): void
    {
        $className = $this->generateClassFromFile('BranchLevelAdditionalProperties.json');

        $accepted = new $className(['kind' => 'X', 'extra' => 1]);
        $this->assertSame(['kind' => 'X', 'extra' => 1], $accepted->meta()->rawInput());

        try {
            new $className(['kind' => 'X', 'extra' => 'bar']);
            $this->fail(
                'Branch-level additionalProperties value failure must orphan the key so the '
                . 'outer unevaluated check fires',
            );
        } catch (UnevaluatedPropertiesException $exception) {
            $this->assertMatchesRegularExpression(
                '/^Provided JSON for \S+ contains not allowed unevaluated properties \[extra\]$/',
                $exception->getMessage(),
            );
            $this->assertSame(['extra'], $exception->getUnevaluatedProperties());
        }
    }
}
