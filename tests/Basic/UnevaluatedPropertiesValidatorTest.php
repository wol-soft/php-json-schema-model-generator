<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Object\AdditionalPropertiesException;
use PHPModelGenerator\Exception\Object\InvalidAdditionalPropertiesException;
use PHPModelGenerator\Exception\Object\InvalidPatternPropertiesException;
use PHPModelGenerator\Exception\Object\InvalidPropertyNamesException;
use PHPModelGenerator\Exception\Object\InvalidUnevaluatedPropertiesException;
use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Exception\Object\RequiredValueException;
use PHPModelGenerator\Exception\Object\UnevaluatedPropertiesException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\UnsupportedSchemaFeatureException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Fixtures\RecordingLogger;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPModelGenerator\Traits\CompositionEvaluationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * Verifies the runtime behaviour of `unevaluatedProperties`: keys not claimed by `properties`,
 * `patternProperties`, `additionalProperties`, or a successful composition branch must satisfy
 * the unevaluatedProperties schema (or are rejected outright when the keyword is `false`).
 */
#[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
class UnevaluatedPropertiesValidatorTest extends AbstractPHPModelGeneratorTestCase
{
    protected const EXTERNAL_JSON_DIRECTORIES = ['../UnevaluatedPropertiesValidatorTest_external'];

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
            "/^Provided JSON for '.*' contains not allowed unevaluated properties \['"
                . preg_quote($propertyName, '/')
                . "'\]$/";

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
            $this->assertSame(
                "Provided JSON for '{$directClassName}' contains not allowed unevaluated properties ['extra']",
                $exception->getMessage(),
            );
            $this->assertSame(['extra'], $exception->getUnevaluatedProperties());
            // Root-level unevaluatedProperties: false — pointer stamped by the factory identifies
            // the exact schema keyword that produced the rejection.
            $this->assertSame('/unevaluatedProperties', $exception->getJsonPointer()->pointer);
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
            $this->assertSame("Missing required value for 'name'", $requiredErrors[0]->getMessage());

            $this->assertCount(1, $unevaluatedErrors, 'expected one UnevaluatedPropertiesException');
            $this->assertSame(
                "Provided JSON for '{$collectClassName}' contains not allowed unevaluated properties ['extra']",
                $unevaluatedErrors[0]->getMessage(),
            );
            $this->assertSame('/unevaluatedProperties', $unevaluatedErrors[0]->getJsonPointer()->pointer);
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
            $this->assertSame(
                "Provided JSON for '{$className}' contains not allowed unevaluated properties ['extra']",
                $exception->getMessage(),
            );
            $this->assertSame(['extra'], $exception->getUnevaluatedProperties());
            $this->assertSame('/unevaluatedProperties', $exception->getJsonPointer()->pointer);
        }
    }

    /**
     * A bare boolean `true` composition element is spec-legal inside `allOf` — it always
     * succeeds and contributes no declared properties of its own. Regression guard for a
     * null-nested-schema dereference: the always-true branch is represented by
     * `createAlwaysTrueBranchProperty()` and has no nested `Schema`, so the accumulator must
     * skip it rather than crash trying to read declared property names off it. The sibling
     * real branch (`{properties: {kind}}`) still contributes `kind` normally.
     *
     * Two scenarios on the same generated class:
     *   - `{name, kind}` accepted — the real branch's `kind` is evaluated, `name` is a
     *     declared outer property;
     *   - `{name, kind, extra}` rejected — the always-true branch contributes nothing to the
     *     evaluated set, so `extra` remains unevaluated.
     */
    public function testAlwaysTrueCompositionBranchContributesNoEvaluatedKeys(): void
    {
        $className = $this->generateClassFromFile('AlwaysTrueBranchContributesNoEvaluatedKeys.json');

        $accepted = new $className(['name' => 'Alice', 'kind' => 'X']);
        $this->assertSame(['name' => 'Alice', 'kind' => 'X'], $accepted->meta()->rawInput());

        try {
            new $className(['name' => 'Alice', 'kind' => 'X', 'extra' => 1]);
            $this->fail('An always-true branch must not rescue extras from unevaluatedProperties: false');
        } catch (UnevaluatedPropertiesException $exception) {
            $this->assertSame(
                "Provided JSON for '{$className}' contains not allowed unevaluated properties ['extra']",
                $exception->getMessage(),
            );
            $this->assertSame(['extra'], $exception->getUnevaluatedProperties());
            $this->assertSame('/unevaluatedProperties', $exception->getJsonPointer()->pointer);
        }
    }

    /**
     * A composition branch that declares its own `unevaluatedProperties: {schema}` records
     * the keys it evaluates against that schema. Those records must propagate to an
     * outer `unevaluatedProperties: false` so the outer treats them as already evaluated.
     *
     * Two scenarios on the same generated class (collect-errors mode so the registry
     * surfaces both the inner failure cause and the outer-level orphans in one message):
     *   - `{foo: "hi", bar: 5}` exercises the success path. The branch's `properties`
     *     declares `foo`. The branch's `unevaluatedProperties: {integer}` validates `bar`
     *     (5 is integer). The outer `unevaluatedProperties: false` sees both keys evaluated
     *     through the branch's contribution and accepts.
     *   - `{foo: "hi", bar: "not-int"}` exercises the inner-rejection path. The branch's
     *     `unevaluatedProperties: {integer}` rejects `bar` ("not-int" fails the integer
     *     check); the allOf composition therefore matches zero elements; and the outer's
     *     `unevaluatedProperties: false` then sees both `foo` and `bar` as orphans because
     *     no successful branch claimed them. All three pieces appear in the registry's
     *     aggregated message.
     */
    public function testNestedUnevaluatedInBranchPropagatesClaimsToOuter(): void
    {
        $className = $this->generateClassFromFile(
            'NestedUnevaluatedInBranchPropagatesClaims.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        $accepted = new $className(['foo' => 'hi', 'bar' => 5]);
        $this->assertSame(['foo' => 'hi', 'bar' => 5], $accepted->meta()->rawInput());

        // The branch is rendered as a nested class alongside the outer; its uniqid-suffixed
        // name is resolved so the assertion below can spell the full message.
        $nestedClassName = $this->resolveNestedClassName($className);

        try {
            new $className(['foo' => 'hi', 'bar' => 'not-int']);
            $this->fail('Inner unevaluatedProperties: {integer} must reject the non-integer extra');
        } catch (ErrorRegistryException $registry) {
            // The registry surfaces the inner cause AND a separate outer-level orphan report
            // for [foo, bar]. Both lines are correct under JSON Schema 2019-09's applicator
            // rules: a composition branch that fails as a whole contributes no annotations,
            // so even foo — whose individual value passed the branch's properties.foo
            // validator — is treated as unevaluated at the outer level because the branch's
            // claim never propagated. The two messages are complementary: the composition
            // block reports why the branch failed, and the trailing line reports what the
            // outer's unevaluatedProperties: false saw once it ran with no successful
            // branches.
            $this->assertSame(
                <<<MSG
                Invalid value for '{$className}' declined by composition constraint
                  Requires to match all composition elements but matched 0 elements
                  - Composition element #1: Failed
                    * Provided JSON for '{$nestedClassName}' contains invalid unevaluated properties
                      - invalid unevaluated property 'bar'
                        * Invalid type for 'unevaluated property': requires 'int', got 'string'
                Provided JSON for '{$className}' contains not allowed unevaluated properties ['foo', 'bar']
                MSG,
                $registry->getMessage(),
            );
        }
    }

    /**
     * A conditional whose `then` branch fails as a whole contributes no annotations — including
     * the claims of its passing `if` condition — exactly like a failed `allOf`. With a stale `if`
     * claim, `marker` would be credited to the outer accumulator and silently vanish from the
     * orphan report that error collection prints next to the conditional failure.
     *
     * Three inputs on the same generated class (collect-errors mode):
     *   - `{marker: true, other: 1}`: `if` passes, `then` fails on the missing `x`; both `marker`
     *     (claimed only by the failed conditional) and `other` are orphans;
     *   - `{other: 1}`: `if` fails and `else` fails on the missing `y`; only `other` is an orphan;
     *   - `{marker: true, x: 1}`: the conditional succeeds and credits `marker` and `x`.
     */
    public function testFailedConditionalDropsTheClaimsOfItsPassingIfCondition(): void
    {
        $className = $this->generateClassFromFile(
            'ConditionalFailureDropsIfClaims.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        $accepted = new $className(['marker' => true, 'x' => 1]);
        $this->assertSame(['marker' => true, 'x' => 1], $accepted->meta()->rawInput());

        try {
            new $className(['marker' => true, 'other' => 1]);
            $this->fail('Expected the failed then branch to reject the input');
        } catch (ErrorRegistryException $registry) {
            $this->assertSame(
                <<<MSG
                Invalid value for '{$className}' declined by conditional composition constraint
                  - Condition: Valid
                  - Conditional branch failed:
                    * Missing required value for 'x'
                    * Invalid type for 'x': requires 'int', got 'NULL'
                Provided JSON for '{$className}' contains not allowed unevaluated properties ['marker', 'other']
                MSG,
                $registry->getMessage(),
            );
        }

        try {
            new $className(['other' => 1]);
            $this->fail('Expected the failed else branch to reject the input');
        } catch (ErrorRegistryException $registry) {
            $this->assertSame(
                <<<MSG
                Invalid value for '{$className}' declined by conditional composition constraint
                  - Condition: Failed
                    * Missing required value for 'marker'
                    * Value for 'marker' must be true, got null
                  - Conditional branch failed:
                    * Missing required value for 'y'
                    * Invalid type for 'y': requires 'int', got 'NULL'
                Provided JSON for '{$className}' contains not allowed unevaluated properties ['other']
                MSG,
                $registry->getMessage(),
            );
        }
    }

    /**
     * The `_getEvaluatedProperties()` method generated on a branch class merges in the branch's
     * `_patternProperties` and `_evaluatedPropertyKeys` fields. Whether the class declares each
     * field is known when the method is rendered, so the merge steps are emitted only for fields
     * that exist - no runtime `property_exists()` probing in the generated code. Four branch
     * shapes on three generated classes:
     *   - a branch with neither field merges nothing beyond its declared names;
     *   - a branch with `patternProperties` only merges the pattern matches;
     *   - a branch with `unevaluatedProperties: {schema}` only merges the evaluated keys;
     *   - a branch with both merges both.
     */
    public function testEvaluatedPropertiesMethodMergesOnlyTheFieldsTheBranchClassDeclares(): void
    {
        $plainClassName = $this->generateClassFromFile('BranchesWithoutEvaluationFields.json');
        [$declaredOnlySource, $patternOnlySource] = array_map(
            fn(string $nestedClassName): string => $this->getEvaluatedPropertiesMethodSource($nestedClassName),
            $this->resolveNestedClassName($plainClassName, 2),
        );

        $this->assertStringNotContainsString('property_exists', $declaredOnlySource);
        $this->assertStringNotContainsString('_patternProperties', $declaredOnlySource);
        $this->assertStringNotContainsString('_evaluatedPropertyKeys', $declaredOnlySource);

        $this->assertStringNotContainsString('property_exists', $patternOnlySource);
        $this->assertStringContainsString('$this->_patternProperties', $patternOnlySource);
        $this->assertStringNotContainsString('_evaluatedPropertyKeys', $patternOnlySource);

        $evaluatedKeysClassName = $this->generateClassFromFile('NestedUnevaluatedInBranchPropagatesClaims.json');
        $evaluatedKeysSource = $this->getEvaluatedPropertiesMethodSource(
            $this->resolveNestedClassName($evaluatedKeysClassName),
        );

        $this->assertStringNotContainsString('property_exists', $evaluatedKeysSource);
        $this->assertStringNotContainsString('_patternProperties', $evaluatedKeysSource);
        $this->assertStringContainsString('$this->_evaluatedPropertyKeys', $evaluatedKeysSource);

        $bothClassName = $this->generateClassFromFile('BranchWithPatternAndUnevaluatedSchema.json');
        $bothSource = $this->getEvaluatedPropertiesMethodSource($this->resolveNestedClassName($bothClassName));

        $this->assertStringNotContainsString('property_exists', $bothSource);
        $this->assertStringContainsString('$this->_patternProperties', $bothSource);
        $this->assertStringContainsString('$this->_evaluatedPropertyKeys', $bothSource);

        // The unconditional merge must stay correct at runtime: a key matched by the branch's
        // pattern and one evaluated by its unevaluatedProperties schema both reach the outer.
        $accepted = new $bothClassName(['foo' => 'hi', 'x-a' => 'v', 'extra' => 5]);
        $this->assertSame(['foo' => 'hi', 'x-a' => 'v', 'extra' => 5], $accepted->meta()->rawInput());
    }

    /**
     * @return string the source of `_getEvaluatedProperties()` as rendered into the nested class
     */
    private function getEvaluatedPropertiesMethodSource(string $nestedClassName): string
    {
        $source = file_get_contents(
            (new ReflectionClass($this->lastGeneratedNamespacePrefix . $nestedClassName))->getFileName(),
        );

        $this->assertSame(
            1,
            preg_match(
                '/function _getEvaluatedProperties\(\): array.*?return array_keys\(\$evaluated\);/s',
                $source,
                $matches,
            ),
            "Nested class $nestedClassName must declare _getEvaluatedProperties()",
        );

        return $matches[0];
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
            $this->assertSame(
                "Provided JSON for '{$className}' contains not allowed unevaluated properties ['extra']",
                $exception->getMessage(),
            );
            $this->assertSame(['extra'], $exception->getUnevaluatedProperties());
        }
    }

    /**
     * A branch-level `unevaluatedProperties: true` is an applicator like any other: within the
     * branch every key is unevaluated, `true` validates all of them, and per the 2019-09
     * annotation rules the branch thereby claims every key on the instance. The outer
     * `unevaluatedProperties: false` therefore accepts any input — including keys no other
     * sibling covers. An explicit `true` must not be collapsed into the absent-keyword case,
     * which produces no annotation.
     */
    public function testBranchLevelUnevaluatedTrueClaimsEveryKeyForTheOuterAccumulator(): void
    {
        $className = $this->generateClassFromFile('BranchUnevaluatedTrueClaims.json');

        $accepted = new $className(['foo' => 'x', 'bar' => 5]);
        $this->assertSame('x', $accepted->getFoo());
        $this->assertSame(['foo' => 'x', 'bar' => 5], $accepted->meta()->rawInput());
    }

    /**
     * A composition branch's own `unevaluatedProperties` cannot see property names the
     * *enclosing* schema (or a sibling branch) declares — `allOf` does not introduce a new
     * evaluation-context boundary, so per spec a branch-level `unevaluatedProperties` should be
     * evaluated against the *same* annotation set an outer-level one would be, but nothing in
     * the current architecture plumbs the enclosing schema's declared names (or a sibling
     * branch's claims) through to a branch's own, separately-generated class - only the reverse
     * direction (branch claims propagating up) is implemented.
     *
     * Rather than silently computing a wrong "unevaluated" set (this exact shape used to reject
     * `{name: "Alice"}`, even though `name` is declared and validated by the enclosing schema's
     * own `properties`), the generator now rejects the schema itself at generation time with a
     * distinct exception. A real fix was deferred instead of implemented: a sound fix exists for
     * `allOf` alone, but the equivalent for `anyOf`/`oneOf` can have no unique consistent answer
     * at all when two sibling branches each gate their own `unevaluatedProperties` on the
     * other's claims.
     */
    public function testBranchOnlyUnevaluatedPropertiesThrowsUnsupportedSchemaFeatureException(): void
    {
        $this->expectException(UnsupportedSchemaFeatureException::class);
        $this->expectExceptionMessage(
            "Branch #1 of the composition for 'BranchOnlyUnevaluatedPropertiesIgnoresOuterDeclarations' "
                . "declares 'unevaluatedProperties', which cannot yet see property names or indices "
                . 'declared by the enclosing schema or a sibling branch - remove '
                . "'unevaluatedProperties' from the branch, or restructure the schema so it is "
                . 'declared only at the level that needs it at line 10, column 5',
        );

        $this->generateClassFromFile('BranchOnlyUnevaluatedPropertiesIgnoresOuterDeclarations.json', null, true);
    }

    /**
     * The unsupported-down-propagation guard above is driven by
     * `AbstractComposedPropertyValidator::getComposedProperties()`, which every composition
     * keyword's validator populates uniformly - confirms the guard actually reaches `anyOf`,
     * `oneOf`, and `if`/`then`/`else` too, not just the `allOf` shape the bug was originally
     * found with. Also confirms the *sibling-branch* half of the detection (as opposed to the
     * enclosing-schema-declares-something half every other row here exercises): the enclosing
     * schema itself declares nothing in `SiblingBranchUnsupportedDownPropagation.json` - only a
     * *sibling* `allOf` branch declares `properties`.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unsupportedDownPropagationDataProvider(): array
    {
        return [
            'anyOf branch' => [
                'AnyOfBranchUnsupportedDownPropagation.json',
                "Branch #1 of the composition for 'AnyOfBranchUnsupportedDownPropagation' declares "
                    . "'unevaluatedProperties', which cannot yet see property names or indices "
                    . 'declared by the enclosing schema or a sibling branch - remove '
                    . "'unevaluatedProperties' from the branch, or restructure the schema so it is "
                    . 'declared only at the level that needs it at line 10, column 5',
            ],
            'oneOf branch' => [
                'OneOfBranchUnsupportedDownPropagation.json',
                "Branch #1 of the composition for 'OneOfBranchUnsupportedDownPropagation' declares "
                    . "'unevaluatedProperties', which cannot yet see property names or indices "
                    . 'declared by the enclosing schema or a sibling branch - remove '
                    . "'unevaluatedProperties' from the branch, or restructure the schema so it is "
                    . 'declared only at the level that needs it at line 10, column 5',
            ],
            'if/then branch' => [
                'IfThenBranchUnsupportedDownPropagation.json',
                "Branch #2 of the composition for 'IfThenBranchUnsupportedDownPropagation' declares "
                    . "'unevaluatedProperties', which cannot yet see property names or indices "
                    . 'declared by the enclosing schema or a sibling branch - remove '
                    . "'unevaluatedProperties' from the branch, or restructure the schema so it is "
                    . 'declared only at the level that needs it at line 19, column 11',
            ],
            'sibling branch declares the claim, not the enclosing schema' => [
                'SiblingBranchUnsupportedDownPropagation.json',
                "Branch #1 of the composition for 'SiblingBranchUnsupportedDownPropagation' declares "
                    . "'unevaluatedProperties', which cannot yet see property names or indices "
                    . 'declared by the enclosing schema or a sibling branch - remove '
                    . "'unevaluatedProperties' from the branch, or restructure the schema so it is "
                    . 'declared only at the level that needs it at line 5, column 5',
            ],
        ];
    }

    #[DataProvider('unsupportedDownPropagationDataProvider')]
    public function testUnsupportedDownPropagationThrowsAcrossCompositionKeywords(
        string $schemaFile,
        string $expectedMessage,
    ): void {
        $this->expectException(UnsupportedSchemaFeatureException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->generateClassFromFile($schemaFile, null, true);
    }

    /**
     * `not` is deliberately exempt from the unsupported-down-propagation guard: it already
     * blocks annotations from crossing its boundary in both directions by design (see
     * `not.rst`) - nothing about its own outcome depends on outside annotations, so there is
     * nothing for the guard to protect against here, unlike every other composition keyword.
     */
    public function testNotBranchIsExemptFromUnsupportedDownPropagationGuard(): void
    {
        $className = $this->generateClassFromFile('NotBranchExemptFromUnsupportedDownPropagation.json');

        $this->assertSame(['name' => 'Alice'], (new $className(['name' => 'Alice']))->meta()->rawInput());
    }

    /**
     * Keys matched by a successful branch's `patternProperties` (with passing values) are
     * evaluated by that branch and must be credited to the outer accumulator — also when the
     * branch renders as a nested class rather than being merged or inlined. Two assertions on
     * the same generated class:
     *   - `{x-a: "v"}` matches the branch pattern with a passing value → credited → accepted.
     *   - `{other: 1}` matches no pattern and no other applicator → rejected by the outer
     *     unevaluated check.
     */
    public function testBranchLevelPatternPropertiesMatchesCreditTheOuterAccumulator(): void
    {
        $className = $this->generateClassFromFile('BranchPatternPropertiesClaim.json');

        $accepted = new $className(['x-a' => 'v']);
        $this->assertSame(['x-a' => 'v'], $accepted->meta()->rawInput());

        try {
            new $className(['other' => 1]);
            $this->fail('Expected UnevaluatedPropertiesException for a key no branch pattern matches');
        } catch (UnevaluatedPropertiesException $exception) {
            $this->assertSame(
                "Provided JSON for '{$className}' contains not allowed unevaluated properties ['other']",
                $exception->getMessage(),
            );
            $this->assertSame(['other'], $exception->getUnevaluatedProperties());
        }
    }

    /**
     * A property subschema declaring object applicators (`properties`, `unevaluatedProperties`)
     * without an explicit `type: object` still applies those keywords when the instance value
     * is an object — JSON Schema applicators are not gated on a type declaration. The keyword
     * must not be silently dropped for untyped subschemas: the subschema behaves exactly as if
     * `type: object` were declared, so the rejection surfaces through the nested-object
     * wrapping. The nested class name is matched by regex because the class does not carry a
     * predictable name (the class-name generator appends a uniqid).
     */
    public function testUnevaluatedPropertiesAppliesWithoutExplicitObjectTypeDeclaration(): void
    {
        $className = $this->generateClassFromFile('UntypedNestedSchemaWithUnevaluated.json');

        // Object value: only the declared key is present — nothing is unevaluated inside `child`.
        $accepted = new $className(['child' => ['known' => 'a']]);
        $this->assertSame(['child' => ['known' => 'a']], $accepted->meta()->rawInput());

        // Non-object value: an untyped schema imposes no type constraint, so a scalar passes through
        // untouched even though `child` declares object applicators.
        $this->assertSame('a string', (new $className(['child' => 'a string']))->getChild());

        // A schema carrying only object-constraining keywords (no sibling scalar-type
        // applicator) is object-describing (see docs/source/combinedSchemas/impliedObjects.rst):
        // a non-object value passes through unconditionally, so the getter stays untyped `mixed`
        // rather than naming the nested class.
        $this->assertSame('mixed', $this->getReturnTypeAnnotation($className, 'getChild'));

        // `extra` is claimed by nothing inside `child` and must be rejected by unevaluatedProperties.
        try {
            new $className(['child' => ['known' => 'a', 'extra' => 1]]);
            $this->fail('Unevaluated key inside an untyped object subschema must be rejected');
        } catch (NestedObjectException $exception) {
            $this->assertMatchesRegularExpression(
                <<<'REGEX'
                /^Invalid nested object for property 'child':
                  - Provided JSON for '.+' contains not allowed unevaluated properties \['extra'\]$/
                REGEX,
                $exception->getMessage(),
            );
        }
    }

    /**
     * The `unevaluatedProperties` keyword may be expressed as an inline `$ref` — the runtime
     * shape is a schema, so the ref-resolved schema must be applied to every extra key. Two
     * assertions on the same generated class:
     *   - `{name: "Alice", count: 42}` accepts because 42 satisfies the resolved integer
     *     schema.
     *   - `{name: "Alice", count: "not-int"}` rejects because the value fails the resolved
     *     integer schema.
     */
    public function testUnevaluatedPropertiesReferencedViaRef(): void
    {
        $className = $this->generateClassFromFile('UnevaluatedIsRef.json');

        $accepted = new $className(['name' => 'Alice', 'count' => 42]);
        $this->assertSame(['name' => 'Alice', 'count' => 42], $accepted->meta()->rawInput());

        try {
            new $className(['name' => 'Alice', 'count' => 'not-int']);
            $this->fail('$ref-resolved unevaluatedProperties schema must reject non-integer extra');
        } catch (InvalidUnevaluatedPropertiesException $exception) {
            $this->assertSame(
                <<<MSG
                Provided JSON for '{$className}' contains invalid unevaluated properties
                  - invalid unevaluated property 'count'
                    * Invalid type for 'unevaluated property': requires 'int', got 'string'
                MSG,
                $exception->getMessage(),
            );
            $this->assertSame('/unevaluatedProperties', $exception->getJsonPointer()->pointer);
        }
    }

    /**
     * A `$ref` composition branch (same-file `$defs`, or an external file - see
     * EXTERNAL_JSON_DIRECTORIES above) resolves to the referenced schema at generation time, and
     * its `properties` must contribute to the outer `unevaluatedProperties: false` accumulator.
     * One data provider, not two methods: only `$ref` resolution differs between the rows (its
     * own concern, covered in ReferencePropertyTest/RefSiblingsTest), not the behaviour under
     * test here.
     *
     * Note: `ExternalRefBranch.json`'s target renders as an ordinary, fully-validating nested
     * class, not an `ExternalSchema` placeholder (a `$ref` outside the provider's base directory
     * doesn't by itself produce one - see testExternalSchemaPlaceholderBranchCreditsNothingLikeAVacuousBranch()
     * for that case, which needs a target file with no `type`/`properties`/composition of its
     * own).
     */
    #[DataProvider('refResolvedBranchDataProvider')]
    public function testRefResolvedBranchContributesAnnotations(
        string $schemaFile,
        array $acceptedInput,
        string $strayKey,
    ): void {
        $className = $this->generateClassFromFile($schemaFile);

        $accepted = new $className($acceptedInput);
        $this->assertSame($acceptedInput, $accepted->meta()->rawInput());

        try {
            new $className(['name' => 'Alice', $strayKey => 1]);
            $this->fail('An undeclared key must be rejected by unevaluatedProperties: false');
        } catch (UnevaluatedPropertiesException $exception) {
            $this->assertSame(
                "Provided JSON for '{$className}' contains not allowed unevaluated properties ['{$strayKey}']",
                $exception->getMessage(),
            );
            $this->assertSame([$strayKey], $exception->getUnevaluatedProperties());
        }
    }

    public static function refResolvedBranchDataProvider(): array
    {
        return [
            'same-file $defs reference' => [
                'RefResolvedBranchContributesAnnotations.json',
                ['name' => 'Alice', 'foo' => 'hi', 'bar' => 5],
                'stray',
            ],
            'external-file reference crossing a directory boundary' => [
                'ExternalRefBranch.json',
                ['name' => 'Alice', 'external' => 'hi'],
                'whatever',
            ],
        ];
    }

    /**
     * A `$ref` to an entire external file declaring no `type`/`properties`/composition (only
     * `definitions`) renders as an `ExternalSchema` placeholder - a branch that's vacuously
     * satisfied (like `{}`) but declares none of `properties`/`patternProperties`/
     * `additionalProperties`, so per the spec-mandated rule (combinedSchemas/allOf.rst's
     * "Property and item evaluation propagation") it credits nothing to `unevaluatedProperties`,
     * same as a literal `{}` or `true` branch. `whatever` is therefore correctly rejected.
     *
     * Also asserts `warnIfVacuousBranch()` still flags this branch even though it inherits the
     * outer `type: object` - the vacuousness check must look past an inherited type to the
     * branch's own authored content.
     */
    public function testExternalSchemaPlaceholderBranchCreditsNothingLikeAVacuousBranch(): void
    {
        $logger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'ExternalSchemaPlaceholderBranch.json',
            (new GeneratorConfiguration())->setLogger($logger)->setCollectErrors(false),
        );

        $this->assertTrue(
            $this->hasLogEntry(
                $logger->getEntries(),
                'warning',
                "Composition branch #{index} for '{property}' carries no validation keyword and"
                    . ' matches any value',
                ['index' => 1],
            ),
            'The placeholder branch declares no validation keyword and matches any value - it '
            . 'should be flagged as vacuous even though it inherits the outer type.',
        );

        $accepted = new $className(['name' => 'Alice']);
        $this->assertSame(['name' => 'Alice'], $accepted->meta()->rawInput());

        try {
            new $className(['name' => 'Alice', 'whatever' => 'x']);
            $this->fail('An undeclared key must be rejected by unevaluatedProperties: false');
        } catch (UnevaluatedPropertiesException $exception) {
            $this->assertSame(
                "Provided JSON for '{$className}' contains not allowed unevaluated properties ['whatever']",
                $exception->getMessage(),
            );
            $this->assertSame(['whatever'], $exception->getUnevaluatedProperties());
        }
    }

    /**
     * Non-regression companion: a branch that explicitly declares its own `type` (even one
     * identical to the parent's) must stay silent - confirms the fix above distinguishes an
     * inherited type from an author-declared one in both directions.
     */
    public function testAuthorDeclaredTypeBranchStaysSilent(): void
    {
        $logger = new RecordingLogger();

        $this->generateClassFromFile(
            'AuthorDeclaredTypeBranchStaysSilent.json',
            (new GeneratorConfiguration())->setLogger($logger),
        );

        $this->assertFalse(
            $this->hasLogEntry(
                $logger->getEntries(),
                'warning',
                "Composition branch #{index} for '{property}' carries no validation keyword and"
                    . ' matches any value',
            ),
            'A branch with its own explicit type declaration must not be flagged as vacuous.',
        );
    }

    /**
     * A schema that references itself through `$defs` must not cause the activation walk to
     * recurse indefinitely — the walk's `seenSchemas` map is required (not defensive) for
     * termination. This is the object-side analogue of the array-side self-reference test.
     *
     * Three scenarios on the same generated class:
     *   - `{root: {name: "n", child: {name: "n2"}}}` accepts because the recursive node's
     *     `unevaluatedProperties: false` sees only the declared `name` / `child` keys at
     *     every level.
     *   - A stray key one level down (`root.stray`) surfaces as a `NestedObjectException`
     *     wrapping the inner `UnevaluatedPropertiesException`. Unwrapping through
     *     `getNestedException()` confirms the inner exception's identity and its
     *     `getUnevaluatedProperties()` return value.
     *   - A stray key two levels down (`root.child.stray`) proves the same enforcement
     *     applies to the recursive `child` slot — meaning the `$ref`-resolved schema
     *     produces a working `unevaluatedProperties` validator at every nesting depth,
     *     not only at the outer entry point.
     */
    public function testRecursiveSelfReferenceTerminates(): void
    {
        $className = $this->generateClassFromFile('RecursiveSelfReference.json');

        $accepted = new $className([
            'root' => [
                'name' => 'outer',
                'child' => ['name' => 'inner'],
            ],
        ]);
        $this->assertSame(
            [
                'root' => [
                    'name' => 'outer',
                    'child' => ['name' => 'inner'],
                ],
            ],
            $accepted->meta()->rawInput(),
        );

        // The recursive Node class is generated once and shared by both `root` and `child`
        // (SchemaDefinitionDictionary caches by pointer). Resolve its class name for the
        // exception-message assertions below.
        $nodeClassName = $this->resolveNestedClassName($className);

        try {
            new $className([
                'root' => [
                    'name' => 'outer',
                    'stray' => 1,
                ],
            ]);
            $this->fail('unevaluatedProperties: false on the recursive node must reject stray');
        } catch (NestedObjectException $exception) {
            $innerException = $exception->getNestedException();
            $this->assertInstanceOf(UnevaluatedPropertiesException::class, $innerException);
            $this->assertSame(['stray'], $innerException->getUnevaluatedProperties());

            $this->assertSame(
                <<<MSG
                Invalid nested object for property 'root':
                  - Provided JSON for '{$nodeClassName}' contains not allowed unevaluated properties ['stray']
                MSG,
                $exception->getMessage(),
            );
        }

        try {
            new $className([
                'root' => [
                    'name' => 'outer',
                    'child' => [
                        'name' => 'inner',
                        'stray' => 1,
                    ],
                ],
            ]);
            $this->fail(
                'unevaluatedProperties: false must still enforce at the recursively referenced '
                . 'child level',
            );
        } catch (NestedObjectException $exception) {
            // Two-level unwrap: the outer NestedObjectException wraps a
            // NestedObjectException for `child`, which in turn wraps the
            // UnevaluatedPropertiesException that flagged `stray`.
            $childException = $exception->getNestedException();
            $this->assertInstanceOf(NestedObjectException::class, $childException);

            $innerException = $childException->getNestedException();
            $this->assertInstanceOf(UnevaluatedPropertiesException::class, $innerException);
            $this->assertSame(['stray'], $innerException->getUnevaluatedProperties());

            $this->assertSame(
                <<<MSG
                Invalid nested object for property 'root':
                  - Invalid nested object for property 'child':
                      - Provided JSON for '{$nodeClassName}' contains not allowed unevaluated properties ['stray']
                MSG,
                $exception->getMessage(),
            );
        }
    }

    /**
     * A keyword inside a nested class is enforced by that class alone: neither the schema, the
     * branch of its `allOf` nor the class of the intermediate `inner` property reads it, so none
     * of them activates the evaluation tracking (CompositionEvaluationTrait). Activating every
     * enclosing class of a keyword was rejected as generated code no unevaluated keyword reads.
     */
    public function testKeywordInADeeplyNestedClassIsEnforcedWithoutActivatingTheEnclosingClasses(): void
    {
        $className = $this->generateClassFromFile(
            'KeywordInDeeplyNestedClass.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        $object = new $className(['inner' => ['deep' => ['name' => 'x']]]);
        $innerClassName = $object->getInner()::class;
        $deepClassName = $object->getInner()->getDeep()::class;

        $this->assertNotContains(CompositionEvaluationTrait::class, class_uses($className));
        $this->assertNotContains(CompositionEvaluationTrait::class, class_uses($innerClassName));
        $this->assertContains(CompositionEvaluationTrait::class, class_uses($deepClassName));

        try {
            new $className(['inner' => ['deep' => ['name' => 'x', 'extra' => 1]]]);
            $this->fail('Expected the nested class to reject the unevaluated key');
        } catch (ErrorRegistryException $registry) {
            $this->assertSame(
                <<<MSG
                Invalid value for '{$className}' declined by composition constraint
                  Requires to match all composition elements but matched 0 elements
                  - Composition element #1: Failed
                    * Invalid nested object for property 'inner':
                      - Invalid nested object for property 'deep':
                          - Provided JSON for '{$deepClassName}' contains not allowed unevaluated properties ['extra']
                Invalid nested object for property 'inner':
                  - Invalid nested object for property 'deep':
                      - Provided JSON for '{$deepClassName}' contains not allowed unevaluated properties ['extra']
                MSG,
                $registry->getMessage(),
            );
        }
    }

    /**
     * When `unevaluatedProperties` appears inside a `patternProperties` value-subschema, the
     * inner subschema activates its own tracking during its own generated class's
     * post-processing pass — the parent's activation walk does not need to recurse into
     * pattern-value subschemas. `RenderQueue::execute()` runs
     * `UnevaluatedPropertiesPostProcessor::process()` on every schema separately, and the
     * inner class's `needsActivation()` returns true on the very first check because the
     * inner JSON directly declares `unevaluatedProperties`.
     *
     *   - `{p_alpha: {known: "hi"}}` accepts — the nested class's declared property covers
     *     the sole key.
     *   - `{p_alpha: {known: "hi", stray: 1}}` rejects — the nested class enforces its own
     *     `unevaluatedProperties: false`, surfacing at the parent as
     *     `InvalidPatternPropertiesException` wrapping the inner
     *     `UnevaluatedPropertiesException`.
     */
    public function testPatternPropertiesValueSubschemaEnforcesOwnUnevaluatedProperties(): void
    {
        $className = $this->generateClassFromFile('PatternValueSubschemaUnevaluated.json');

        $accepted = new $className(['p_alpha' => ['known' => 'hi']]);
        $this->assertSame(['p_alpha' => ['known' => 'hi']], $accepted->meta()->rawInput());

        $nestedClassName = $this->resolveNestedClassName($className);

        $this->expectException(InvalidPatternPropertiesException::class);
        $this->expectExceptionMessage(
            <<<MSG
            Provided JSON for '{$className}' contains invalid pattern properties
              - invalid property 'p_alpha' matching pattern '^p_'
                * Provided JSON for '{$nestedClassName}' contains not allowed unevaluated properties ['stray']
            MSG,
        );
        new $className(['p_alpha' => ['known' => 'hi', 'stray' => 1]]);
    }

    /**
     * When `unevaluatedProperties` appears inside an `additionalProperties` value-subschema,
     * the inner subschema activates its own tracking during its own generated class's
     * post-processing pass — mirroring the pattern-value case above. The parent has no
     * `unevaluatedProperties` of its own; only the inner subschema does, and the inner class
     * self-activates because its own JSON declares the keyword.
     *
     *   - `{id: 1, dyn: {known: "hi"}}` accepts.
     *   - `{id: 1, dyn: {known: "hi", stray: 1}}` rejects — the nested class throws
     *     `UnevaluatedPropertiesException`, surfacing at the parent as
     *     `InvalidAdditionalPropertiesException`.
     */
    public function testAdditionalPropertiesValueSubschemaEnforcesOwnUnevaluatedProperties(): void
    {
        $className = $this->generateClassFromFile('AdditionalValueSubschemaUnevaluated.json');

        $accepted = new $className(['id' => 1, 'dyn' => ['known' => 'hi']]);
        $this->assertSame(
            ['id' => 1, 'dyn' => ['known' => 'hi']],
            $accepted->meta()->rawInput(),
        );

        $nestedClassName = $this->resolveNestedClassName($className);

        $this->expectException(InvalidAdditionalPropertiesException::class);
        $this->expectExceptionMessage(
            <<<MSG
            Provided JSON for '{$className}' contains invalid additional properties
              - invalid additional property 'dyn'
                * Provided JSON for '{$nestedClassName}' contains not allowed unevaluated properties ['stray']
            MSG,
        );
        new $className(['id' => 1, 'dyn' => ['known' => 'hi', 'stray' => 1]]);
    }

    /**
     * Sibling `additionalProperties` (or the effective `false` produced by the
     * `denyAdditionalProperties()` config flag) short-circuits the unevaluated bucket. Each row
     * pins the exact factory warning so a message drift in the factory surfaces here rather than
     * as a silent behaviour change to consumers who grep build output for these hints.
     *
     * @return array<string, array{0: string, 1: string, 2: GeneratorConfiguration}>
     */
    public static function deadCodeProvider(): array
    {
        $baseConfig = static fn(): GeneratorConfiguration =>
            (new GeneratorConfiguration())->setLogger(new RecordingLogger());

        return [
            // additionalProperties: true accepts every extra unchecked; without a matching
            // annotation contribution the unevaluated accumulator would still reject them,
            // which would defeat the intent of `additionalProperties: true`.
            'additionalProperties: true suppresses unevaluated' => [
                'AdditionalTrueDeadCode.json',
                'sibling additionalProperties: true accepts every extra without crediting the unevaluated accumulator',
                $baseConfig(),
            ],
            // additionalProperties: {schema} validates and claims every extra; unevaluated has
            // nothing left to reach.
            'additionalProperties: {schema} claims every extra' => [
                'AdditionalSchemaDeadCode.json',
                'sibling additionalProperties: {schema} already validates every extra key',
                $baseConfig(),
            ],
            // additionalProperties: false rejects every extra at the base-validator phase,
            // long before the post-composition unevaluated validator would run.
            'additionalProperties: false rejects every extra first' => [
                'AdditionalFalseDeadCode.json',
                'sibling additionalProperties: false rejects every extra before the unevaluated phase runs',
                $baseConfig(),
            ],
            // Same as the previous row but with the schema form of unevaluatedProperties.
            'additionalProperties: false leaves unevaluated {schema} unreachable' => [
                'AdditionalFalseWithUnevaluatedSchema.json',
                'sibling additionalProperties: false rejects every extra before the unevaluated phase runs',
                $baseConfig(),
            ],
            // denyAdditionalProperties() flips a missing additionalProperties to false at
            // configuration time — the same dead-cell shape as the explicit false row but
            // reached through the generator config instead of the JSON schema.
            'denyAdditionalProperties() flag mimics false and warns' => [
                'DenyAdditionalDeadCode.json',
                'denyAdditionalProperties() flips missing additionalProperties to false,'
                    . ' rejecting every extra before the unevaluated phase runs',
                $baseConfig()->setDenyAdditionalProperties(true),
            ],
        ];
    }

    /**
     * Each dead-cell shape emits the factory warning through the configured logger and skips
     * emitting the unevaluated validator. Where an assertion on the resulting class is
     * meaningful (extras still land where their governing keyword expects them), the test
     * exercises the runtime path after checking the warning entry.
     */
    #[DataProvider('deadCodeProvider')]
    public function testDeadCellShapesEmitWarningAndSkipValidator(
        string $schemaFile,
        string $reason,
        GeneratorConfiguration $config,
    ): void {
        $className = $this->generateClassFromFile($schemaFile, $config);

        $this->assertTrue(
            $this->hasLogEntry(
                $config->getLogger()->getEntries(),
                'warning',
                'unevaluatedProperties on {class} is dead code — {reason}',
                ['reason' => $reason],
            ),
            'Expected a warning naming the dead unevaluatedProperties keyword',
        );

        // Constructing with just the declared property must always succeed — the suppressed
        // unevaluated validator can never contribute a false negative here.
        $instance = new $className(['name' => 'Alice']);
        $this->assertSame(['name' => 'Alice'], $instance->meta()->rawInput());
    }

    /**
     * `additionalProperties: false` combined with `unevaluatedProperties: {schema}` (or `false`)
     * must reject extras with `AdditionalPropertiesException`, never `UnevaluatedPropertiesException`,
     * because the unevaluated validator is not emitted. Extra property `count: 42` would satisfy
     * the unevaluated integer schema, so if the unevaluated validator were still running the
     * construction would succeed. The rejection therefore proves the factory suppressed the
     * validator as intended.
     */
    public function testAdditionalFalseRejectsExtrasEvenWhenUnevaluatedSchemaWouldAccept(): void
    {
        $className = $this->generateClassFromFile('AdditionalFalseWithUnevaluatedSchema.json');

        $this->expectException(AdditionalPropertiesException::class);
        $this->expectExceptionMessage(
            "Provided JSON for '{$className}' contains not allowed additional properties ['count']",
        );

        new $className(['name' => 'Alice', 'count' => 42]);
    }

    /**
     * `unevaluatedProperties: {schema}` whose inner schema is contradictory (allOf of two
     * incompatible non-null types) must surface the same `SchemaException` the rest of the
     * codebase throws for contradictory allOf types. The contradictory-type detection is
     * shared machinery; this test only pins that the SchemaException identity survives when
     * the offending subschema is nested under `unevaluatedProperties`.
     */
    public function testContradictoryInnerSchemaThrowsSchemaExceptionPointingAtFile(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Property 'unevaluated property' is defined with conflicting types in allOf"
                . ' composition branches \\(file \\S+\\)\\. allOf requires all constraints to'
                . ' hold simultaneously, making this schema unsatisfiable\\.'
                . ' at line \\d+, column \\d+$/',
        );

        $this->generateClassFromFile('ContradictoryUnevaluatedSchema.json');
    }

    /**
     * `propertyNames` and `unevaluatedProperties` are orthogonal but propertyNames runs first
     * because it is a base-phase validator whereas unevaluatedProperties is a post-composition
     * validator. A key that violates the propertyNames regex must surface
     * `InvalidPropertyNamesException` in direct-exception mode — even if the offending key's
     * value would also fail the unevaluated schema, unevaluated never runs. Under error
     * collection both fires do land in the registry because the base phase does not abort;
     * assert both classes are present and the combined message pins the ordering.
     *
     * @return array<string, array{0: GeneratorConfiguration, 1: string, 2: string}>
     *   [config, expected exception class, expected message with %s placeholder for class name]
     */
    public static function propertyNamesRejectionProvider(): array
    {
        return [
            // Direct-exception mode: propertyNames throws first and the constructor never
            // reaches the post-composition phase where unevaluated lives. The
            // InvalidPropertyNamesException wraps the inner PatternException. The inner one
            // names 'property name' (the sub-property name the propertyNames validator
            // assigns) rather than the offending key; the outer names the offending key
            // ('FOO') on its enclosing line.
            'direct exception surfaces propertyNames only' => [
                (new GeneratorConfiguration())->setCollectErrors(false),
                InvalidPropertyNamesException::class,
                <<<'MSG'
                Provided JSON for '{className}' contains properties with invalid names
                  - invalid property 'FOO'
                    * Value for 'property name' does not match pattern '^[a-z]+$'
                MSG,
            ],
            // Error-collection mode: ErrorRegistryException joins each collected error with a
            // single "\n" (no blank line). The propertyNames block lands first (base phase);
            // the unevaluated-schema block lands second (post-composition phase). The value
            // 'bar' fails the unevaluated schema's `type: integer` check, so the inner
            // InvalidUnevaluatedPropertiesException reports the type mismatch — not the
            // false-form "not allowed unevaluated properties" phrasing.
            'collected errors capture both failures in phase order' => [
                (new GeneratorConfiguration())->setCollectErrors(true),
                ErrorRegistryException::class,
                <<<'MSG'
                Provided JSON for '{className}' contains properties with invalid names
                  - invalid property 'FOO'
                    * Value for 'property name' does not match pattern '^[a-z]+$'
                Provided JSON for '{className}' contains invalid unevaluated properties
                  - invalid unevaluated property 'FOO'
                    * Invalid type for 'unevaluated property': requires 'int', got 'string'
                MSG,
            ],
        ];
    }

    #[DataProvider('propertyNamesRejectionProvider')]
    public function testPropertyNamesRejectionPrecedesUnevaluated(
        GeneratorConfiguration $config,
        string $expectedExceptionClass,
        string $expectedMessageTemplate,
    ): void {
        $className = $this->generateClassFromFile('PropertyNamesFailsFirst.json', $config);

        $this->expectException($expectedExceptionClass);
        $this->expectExceptionMessage(str_replace('{className}', $className, $expectedMessageTemplate));

        new $className(['FOO' => 'bar']);
    }

    /**
     * `dependentSchemas` is a Draft 2019-09 applicator whose dependent subschemas contribute
     * annotations to the unevaluated accumulator: when the trigger key is present on the
     * instance and the dependent subschema validates, its `properties`/`patternProperties`/
     * `additionalProperties` claims flow into the enclosing accumulator. The fixture
     * declares `dependentSchemas: {kind: {properties: {extra: {type: integer}}}}` alongside
     * `unevaluatedProperties: false`.
     *
     * Two shapes exercise the applicator once implemented:
     *   1. `{kind, extra}` — `extra` is covered by the dependent subschema's `properties`
     *      declaration, so the accumulator credits it and construction succeeds.
     *   2. `{kind, stray}` — `stray` is NOT covered by the dependent subschema, so the
     *      accumulator does not credit it and `unevaluatedProperties: false` rejects.
     *
     * Assertions are written for the post-implementation shape; the test is currently
     * skipped because dependentSchemas is not yet recognised by the schema processor. When
     * the applicator lands, removing the markTestSkipped line makes both assertions live.
     */
    public function testDependentSchemasContributionCreditsDependentPropertiesToAccumulator(): void
    {
        $this->markTestSkipped(
            'dependentSchemas applicator not yet implemented in the schema processor; the'
                . ' fixture and assertions describe the intended post-implementation shape.',
        );

        // @phpstan-ignore-next-line dead code — unreachable until the skip is removed
        $className = $this->generateClassFromFile('DependentSchemasWithUnevaluated.json');

        // Success path: `extra` is covered by the dependent subschema — accepted.
        $instance = new $className(['kind' => 'X', 'extra' => 5]);
        $this->assertSame(['kind' => 'X', 'extra' => 5], $instance->meta()->rawInput());

        // Failure path: `stray` is not covered anywhere — `unevaluatedProperties: false`
        // rejects. The pin proves that dependentSchemas only credits what its own subschema
        // actually declares.
        $this->expectException(UnevaluatedPropertiesException::class);
        $this->expectExceptionMessage(
            "Provided JSON for '{$className}' contains not allowed unevaluated properties ['stray']",
        );
        new $className(['kind' => 'X', 'stray' => 5]);
    }

    /**
     * A composition nested inside another composition's branch renders as its own class, and the
     * enclosing accumulator learns what that class evaluated through its
     * `_getEvaluatedProperties()` method. When the nested composition is *disjunctive*
     * (anyOf/oneOf/if-then-else), only the branches that actually succeeded may contribute — a
     * name declared solely by a branch that failed is still unevaluated and must be rejected.
     *
     * The equivalent composition written directly on the outer schema already behaves correctly
     * (see `AnyOfSelectsSuccessfulBranches.json` / `IfThenElseEvaluation.json`), so these rows pin
     * the nested case specifically.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>, 3: string}>
     *   [fixture, accepted input, rejected input, name expected in the rejection message]
     */
    public static function nestedDisjunctiveCompositionProvider(): array
    {
        return [
            // anyOf branch 1 succeeds ('a' is a string); branch 2 fails ('b' is not an integer).
            // Only 'a' is evaluated, so 'b' must be reported as unevaluated.
            'anyOf nested inside an allOf branch' => [
                'NestedAnyOfInAllOfBranch.json',
                ['base' => 'x', 'a' => 'y'],
                ['base' => 'x', 'a' => 'y', 'b' => 'not-an-integer'],
                'b',
            ],
            // kind='a' selects `then` (declaring aOnly); `else` (declaring bOnly) never runs, so
            // bOnly contributes nothing to the evaluated set.
            'if/then/else nested inside an allOf branch' => [
                'NestedIfThenElseInAllOfBranch.json',
                ['kind' => 'a', 'aOnly' => 'y'],
                ['kind' => 'a', 'bOnly' => 'y'],
                'bOnly',
            ],
            // The idiomatic real-world shape: a single allOf branch referencing a discriminated
            // oneOf. kind='click' selects the first variant, so 'code' — declared only by the
            // second — is unevaluated.
            'allOf branch referencing a discriminated oneOf' => [
                'RefToDiscriminatorBranch.json',
                ['id' => 'i', 'kind' => 'click', 'x' => 1],
                ['id' => 'i', 'kind' => 'click', 'x' => 1, 'code' => 'z'],
                'code',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $acceptedInput
     * @param array<string, mixed> $rejectedInput
     */
    #[DataProvider('nestedDisjunctiveCompositionProvider')]
    public function testNestedDisjunctiveCompositionCreditsOnlySuccessfulBranches(
        string $schemaFile,
        array $acceptedInput,
        array $rejectedInput,
        string $unevaluatedName,
    ): void {
        $className = $this->generateClassFromFile($schemaFile);

        $instance = new $className($acceptedInput);
        $this->assertSame($acceptedInput, $instance->meta()->rawInput());

        $this->expectException(UnevaluatedPropertiesException::class);
        $this->expectExceptionMessage(
            "Provided JSON for '{$className}' contains not allowed unevaluated properties ['{$unevaluatedName}']",
        );
        new $className($rejectedInput);
    }

    /**
     * The mirror of the disjunctive case: a key-claiming keyword declared inside a composition
     * that is itself nested inside a branch still claims its keys, so the enclosing accumulator
     * must credit them. Declaring the same keyword one level up (a direct branch of the outer
     * schema) already works — see `BranchLevelAdditionalProperties.json` and
     * `BranchPatternPropertiesClaim.json` — so these rows pin the extra level of nesting.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function nestedKeyClaimingKeywordProvider(): array
    {
        return [
            // additionalProperties: true claims every key the branch did not declare, so `extra`
            // is evaluated and unevaluatedProperties: false must not fire.
            'additionalProperties two levels down claims every key' => [
                'NestedAdditionalPropertiesTwoLevels.json',
                ['base' => 'x', 'extra' => 'y'],
            ],
            // The pattern matches `x_1` and its value validates, so the key is evaluated.
            'patternProperties two levels down claims matching keys' => [
                'NestedPatternPropertiesTwoLevels.json',
                ['base' => 'x', 'x_1' => 'y'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('nestedKeyClaimingKeywordProvider')]
    public function testNestedKeyClaimingKeywordCreditsTheOuterAccumulator(
        string $schemaFile,
        array $input,
    ): void {
        $className = $this->generateClassFromFile($schemaFile);

        $instance = new $className($input);
        $this->assertSame($input, $instance->meta()->rawInput());
    }

    /**
     * From Draft 2019-09 on, `$ref` is a positive applicator that applies alongside its siblings
     * rather than replacing them. The referenced schema's evaluated set therefore contributes to
     * the enclosing schema's accumulator exactly as an inline branch of the same shape would —
     * which is what `docs/source/generic/references.rst` promises.
     *
     * `a` comes from the referenced schema and `b` from the sibling `properties`; both are
     * evaluated. `c` is claimed by neither and must be rejected.
     */
    public function testSiblingRefContributesEvaluatedProperties(): void
    {
        $className = $this->generateClassFromFile('SiblingRefUnevaluated.json');

        $instance = new $className(['a' => 'x', 'b' => 'y']);
        $this->assertSame(['a' => 'x', 'b' => 'y'], $instance->meta()->rawInput());

        $this->expectException(UnevaluatedPropertiesException::class);
        $this->expectExceptionMessage(
            "Provided JSON for '{$className}' contains not allowed unevaluated properties ['c']",
        );
        new $className(['a' => 'x', 'c' => 'y']);
    }

    /**
     * The same sibling-`$ref` merge on a nested property rather than at the schema root. The
     * merged class is assembled by a different code path than the root-level case, so it gets its
     * own coverage.
     */
    public function testSiblingRefOnNestedPropertyContributesEvaluatedProperties(): void
    {
        $className = $this->generateClassFromFile('PropertyLevelSiblingRefUnevaluated.json');

        $instance = new $className(['inner' => ['a' => 'x', 'b' => 'y']]);
        $this->assertSame(['inner' => ['a' => 'x', 'b' => 'y']], $instance->meta()->rawInput());

        try {
            new $className(['inner' => ['a' => 'x', 'c' => 'y']]);
            $this->fail('An undeclared key inside the merged nested object must be rejected');
        } catch (NestedObjectException $exception) {
            $nested = $exception->getNestedException();
            $this->assertInstanceOf(UnevaluatedPropertiesException::class, $nested);
            $this->assertSame(['c'], $nested->getUnevaluatedProperties());
        }
    }

    /**
     * The schema form of `dependencies` is a positive applicator: while the trigger key is present
     * on the instance, the dependent subschema applies at the same instance location and the names
     * it evaluates flow into the enclosing accumulator.
     *
     * Three shapes:
     *   1. `{kind, extra}` — the trigger key is present, so the dependent subschema's `extra`
     *      declaration applies and the accumulator credits it.
     *   2. `{kind, stray}` — the trigger key is present but `stray` is declared nowhere, so it
     *      stays unevaluated. Proves the dependency only credits what its subschema declares.
     *   3. `{extra}` — the trigger key is absent, so the dependent subschema never applies and
     *      `extra` is unevaluated. Proves the crediting is gated on the trigger key.
     *
     * `dependentSchemas`, the Draft 2019-09 spelling of the same applicator, is a separate keyword
     * the schema processor does not recognise at all — see
     * testDependentSchemasContributionCreditsDependentPropertiesToAccumulator.
     */
    public function testSchemaDependencyContributionCreditsDependentPropertiesToAccumulator(): void
    {
        $className = $this->generateClassFromFile('DependenciesWithUnevaluated.json');

        $instance = new $className(['kind' => 'X', 'extra' => 5]);
        $this->assertSame(['kind' => 'X', 'extra' => 5], $instance->meta()->rawInput());

        try {
            new $className(['kind' => 'X', 'stray' => 5]);
            $this->fail('A key the dependent subschema does not declare must stay unevaluated');
        } catch (UnevaluatedPropertiesException $exception) {
            $this->assertSame(
                "Provided JSON for '{$className}' contains not allowed unevaluated properties ['stray']",
                $exception->getMessage(),
            );
        }

        $this->expectException(UnevaluatedPropertiesException::class);
        $this->expectExceptionMessage(
            "Provided JSON for '{$className}' contains not allowed unevaluated properties ['extra']",
        );
        new $className(['extra' => 5]);
    }

    /**
     * A dependent subschema contributes the names it declares directly. Names it only reaches
     * through a composition or a `$ref` are not credited: the dependency validator runs during the
     * trigger property's validation pass, after the phase where unevaluatedProperties evaluates,
     * so there is no dependency result to read back — and a static harvest would credit branches
     * that never ran.
     *
     * The shortfall over-rejects rather than over-accepts, so it is reported at generation time
     * instead of being silent. This test pins both halves: the warning naming the keyword, and the
     * rejection it predicts — so the day the contribution is implemented, this test fails and says
     * exactly what to update.
     */
    public function testDependencyReachingThroughCompositionWarnsAndDoesNotCredit(): void
    {
        $logger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'DependencyReachingThroughComposition.json',
            (new GeneratorConfiguration())->setLogger($logger)->setCollectErrors(false),
        );

        $this->assertTrue(
            $this->hasLogEntry(
                $logger->getEntries(),
                'warning',
                "The dependent schema of '{property}' on {class} reaches properties through"
                    . ' {keywords}, which unevaluatedProperties cannot credit — those keys are'
                    . ' reported as unevaluated even when the dependent schema evaluates them',
                ['property' => 'kind', 'keywords' => 'allOf'],
            ),
            'The uncredited dependent contribution must be reported at generation time.',
        );

        // Spec-wise `{kind, extra}` is valid — the allOf branch inside the dependent subschema
        // evaluates `extra`. The generator cannot see that, which is what the warning announces.
        $this->expectException(UnevaluatedPropertiesException::class);
        $this->expectExceptionMessage(
            "Provided JSON for '{$className}' contains not allowed unevaluated properties ['extra']",
        );
        new $className(['kind' => 'X', 'extra' => 5]);
    }

    /**
     * `warnIfVacuousBranch()` decides whether a branch carries any validation keyword by asking
     * the Draft which types register the keyword. `$ref` is resolved by a producer rather than a
     * type modifier, so the lookup does not see it — and from Draft 2019-09 on, where `$ref` no
     * longer swallows its siblings, a `$ref`-only branch's own JSON carries nothing else either.
     * A branch that references a schema full of constraints is the opposite of vacuous and must
     * not be reported as matching any value.
     *
     * Uses the fixture whose `allOf` branch is exactly `{"$ref": "#/$defs/extension"}` — the most
     * common composition idiom in real schemas.
     */
    public function testRefOnlyCompositionBranchIsNotReportedAsVacuous(): void
    {
        $logger = new RecordingLogger();

        $this->generateClassFromFile(
            'RefResolvedBranchContributesAnnotations.json',
            (new GeneratorConfiguration())->setLogger($logger)->setCollectErrors(false),
        );

        $this->assertFalse(
            $this->hasLogEntry(
                $logger->getEntries(),
                'warning',
                "Composition branch #{index} for '{property}' carries no validation keyword and"
                    . ' matches any value',
            ),
            'A branch consisting only of a $ref carries the referenced schema\'s constraints and '
            . 'must not be flagged as vacuous.',
        );
    }
}
