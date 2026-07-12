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
            $this->assertSame(
                "Provided JSON for {$directClassName} contains not allowed unevaluated properties [extra]",
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
            $this->assertSame('Missing required value for name', $requiredErrors[0]->getMessage());

            $this->assertCount(1, $unevaluatedErrors, 'expected one UnevaluatedPropertiesException');
            $this->assertSame(
                "Provided JSON for {$collectClassName} contains not allowed unevaluated properties [extra]",
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
                "Provided JSON for {$className} contains not allowed unevaluated properties [extra]",
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
                Invalid value for {$className} declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Provided JSON for {$nestedClassName} contains invalid unevaluated properties.
                      - invalid unevaluated property 'bar'
                        * Invalid type for unevaluated property. Requires int, got string
                Provided JSON for {$className} contains not allowed unevaluated properties [foo, bar]
                MSG,
                $registry->getMessage(),
            );
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
            $this->assertSame(
                "Provided JSON for {$className} contains not allowed unevaluated properties [extra]",
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
                "Provided JSON for {$className} contains not allowed unevaluated properties [other]",
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

        // Only the declared key is present — nothing is unevaluated inside `child`.
        $accepted = new $className(['child' => ['known' => 'a']]);
        $this->assertSame(['child' => ['known' => 'a']], $accepted->meta()->rawInput());

        // `extra` is claimed by nothing inside `child` and must be rejected.
        $this->expectException(NestedObjectException::class);
        $this->expectExceptionMessageMatches(
            <<<'REGEX'
            /^Invalid nested object for property child:
              - Provided JSON for .+ contains not allowed unevaluated properties \[extra\]$/
            REGEX,
        );

        new $className(['child' => ['known' => 'a', 'extra' => 1]]);
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
                Provided JSON for {$className} contains invalid unevaluated properties.
                  - invalid unevaluated property 'count'
                    * Invalid type for unevaluated property. Requires int, got string
                MSG,
                $exception->getMessage(),
            );
            $this->assertSame('/unevaluatedProperties', $exception->getJsonPointer()->pointer);
        }
    }

    /**
     * A composition branch expressed via `$ref` resolves to the referenced schema at generation
     * time. Its `properties` declarations must contribute to the outer accumulator so an outer
     * `unevaluatedProperties: false` sees the ref-resolved keys as evaluated. Two assertions
     * on the same generated class:
     *   - `{name: "Alice", foo: "hi", bar: 5}` accepts — the ref-resolved allOf branch claims
     *     `foo` and `bar`; the local `properties` claims `name`; no unevaluated keys remain.
     *   - `{name: "Alice", stray: 1}` rejects — `stray` is not declared anywhere.
     */
    public function testRefResolvedBranchContributesAnnotations(): void
    {
        $className = $this->generateClassFromFile('RefResolvedBranchContributesAnnotations.json');

        $accepted = new $className(['name' => 'Alice', 'foo' => 'hi', 'bar' => 5]);
        $this->assertSame(
            ['name' => 'Alice', 'foo' => 'hi', 'bar' => 5],
            $accepted->meta()->rawInput(),
        );

        try {
            new $className(['name' => 'Alice', 'stray' => 1]);
            $this->fail('An undeclared key must be rejected by unevaluatedProperties: false');
        } catch (UnevaluatedPropertiesException $exception) {
            $this->assertSame(
                "Provided JSON for {$className} contains not allowed unevaluated properties [stray]",
                $exception->getMessage(),
            );
            $this->assertSame(['stray'], $exception->getUnevaluatedProperties());
        }
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
                Invalid nested object for property root:
                  - Provided JSON for {$nodeClassName} contains not allowed unevaluated properties [stray]
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
                Invalid nested object for property root:
                  - Invalid nested object for property child:
                      - Provided JSON for {$nodeClassName} contains not allowed unevaluated properties [stray]
                MSG,
                $exception->getMessage(),
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
            Provided JSON for {$className} contains invalid pattern properties.
              - invalid property 'p_alpha' matching pattern '^p_'
                * Provided JSON for {$nestedClassName} contains not allowed unevaluated properties [stray]
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
            Provided JSON for {$className} contains invalid additional properties.
              - invalid additional property 'dyn'
                * Provided JSON for {$nestedClassName} contains not allowed unevaluated properties [stray]
            MSG,
        );
        new $className(['id' => 1, 'dyn' => ['known' => 'hi', 'stray' => 1]]);
    }

    /**
     * A `$ref` that resolves to a schema in a file outside the provider's base directory is
     * represented at generation time as an `ExternalSchema` placeholder — a class the
     * generator does not emit validation for. When such a placeholder is used as a composition
     * branch, the design records that the branch's evaluated set is treated as covering every
     * instance key, because we trust the external schema to enforce its own contract.
     *
     * End-to-end codegen is blocked today by the composition processor's insistence that every
     * composed branch surface a nested schema. An `ExternalSchema` placeholder branch has
     * `getNestedSchema() === null`, so SchemaProcessor throws before the unevaluated wiring
     * ever runs. The same pre-existing limitation blocks a related test on the array-side
     * self-referencing fixture. Fixing it lives in the composition processor's
     * `shouldSkip()` / nested-schema expectation, which is out of scope for this topic.
     *
     * The fixture is kept in the test directory to document the intended behaviour and to
     * accelerate the promotion of this case to a real assertion once the composition
     * processor's nested-schema expectation is relaxed.
     */
    public function testExternalRefBranchTreatsAllKeysAsEvaluated(): void
    {
        $this->markTestIncomplete(
            'End-to-end codegen blocked by the composition processor\'s requirement that '
            . 'every composed branch surface a nested schema. An ExternalSchema placeholder '
            . 'branch has no nested schema, so generation fails before the unevaluated '
            . 'accumulator sees the branch. The fix belongs to the composition processor '
            . '(same pre-existing bug that blocks the array-side self-referencing test).',
        );
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
            (new GeneratorConfiguration())->setOutputEnabled(true);

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
     * Each dead-cell shape emits the factory warning under the `echo` channel and skips
     * emitting the unevaluated validator. Where an assertion on the resulting class is
     * meaningful (extras still land where their governing keyword expects them), the test
     * exercises the runtime path after checking the warning text.
     */
    #[DataProvider('deadCodeProvider')]
    public function testDeadCellShapesEmitWarningAndSkipValidator(
        string $schemaFile,
        string $reasonFragment,
        GeneratorConfiguration $config,
    ): void {
        $this->expectOutputRegex(
            '/Warning: unevaluatedProperties on \S+ is dead code — ' . preg_quote($reasonFragment, '/') . '/',
        );

        $className = $this->generateClassFromFile($schemaFile, $config);

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
            "Provided JSON for {$className} contains not allowed additional properties [count]",
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
                . ' hold simultaneously, making this schema unsatisfiable\\.$/',
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
                Provided JSON for {className} contains properties with invalid names.
                  - invalid property 'FOO'
                    * Value for property name doesn't match pattern ^[a-z]+$
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
                Provided JSON for {className} contains properties with invalid names.
                  - invalid property 'FOO'
                    * Value for property name doesn't match pattern ^[a-z]+$
                Provided JSON for {className} contains invalid unevaluated properties.
                  - invalid unevaluated property 'FOO'
                    * Invalid type for unevaluated property. Requires int, got string
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
            "Provided JSON for {$className} contains not allowed unevaluated properties [stray]",
        );
        new $className(['kind' => 'X', 'stray' => 5]);
    }
}
