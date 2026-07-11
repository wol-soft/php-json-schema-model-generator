<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\Arrays\InvalidUnevaluatedItemsException;
use PHPModelGenerator\Exception\Arrays\UniqueItemsException;
use PHPModelGenerator\Exception\Arrays\UnevaluatedItemsException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verifies the runtime behaviour of `unevaluatedItems`: array indices not claimed by `items`,
 * `additionalItems`, `contains`, or a successful composition branch must satisfy the
 * unevaluatedItems schema (or are rejected outright when the keyword is `false`).
 *
 * Coverage in this test class is limited to schemas with no sibling positive applicator
 * crediting any index, plus the three dead-code shapes that the factory short-circuits with
 * a warning. Composition-driven annotation propagation is exercised separately.
 */
#[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
class UnevaluatedItemsValidatorTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * Accepted input — the generated class must construct and round-trip the input through
     * `meta()->rawInput()` unchanged.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function acceptanceProvider(): array
    {
        return [
            'unevaluatedItems: false with empty array' => [
                'NoOtherConstraintsFalse.json',
                ['tags' => []],
            ],
            'unevaluatedItems: false with array property absent' => [
                'NoOtherConstraintsFalse.json',
                [],
            ],
            'unevaluatedItems schema-form accepts matching values' => [
                'NoOtherConstraintsSchema.json',
                ['tags' => ['alpha', 'beta', 'gamma']],
            ],
            'unevaluatedItems schema-form accepts empty array' => [
                'NoOtherConstraintsSchema.json',
                ['tags' => []],
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
     * Rejected input for the `false` form — construction must throw `UnevaluatedItemsException`
     * with the expected message. Indices are reported with the `#` prefix that the rest of the
     * array-side exception family uses (InvalidItemException, InvalidTupleException, etc.) —
     * the pin includes the prefix so a regression that drops it surfaces here.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function falseFormRejectionProvider(): array
    {
        return [
            // Single-element array — minimal case for the bracket+prefix render.
            'rejects single item' => [
                ['tags' => ['only']],
                'Provided JSON for tags contains not allowed unevaluated items [#0]',
            ],
            // Three-element array — exercises the comma-separated list path so a future change
            // to the joiner shows up here.
            'reports every offending index' => [
                ['tags' => ['a', 'b', 'c']],
                'Provided JSON for tags contains not allowed unevaluated items [#0, #1, #2]',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('falseFormRejectionProvider')]
    public function testFalseFormRejectsUnevaluatedIndicesWithFullMessage(
        array $input,
        string $expectedMessage,
    ): void {
        $className = $this->generateClassFromFile('NoOtherConstraintsFalse.json');

        try {
            new $className($input);
            $this->fail('Expected UnevaluatedItemsException');
        } catch (UnevaluatedItemsException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
            $this->assertSame(
                array_keys($input['tags']),
                $exception->getUnevaluatedItems(),
                'getUnevaluatedItems() must report the same indices the message lists',
            );
            // `tags` is the array property at /properties/tags; its unevaluatedItems keyword
            // sits at /properties/tags/unevaluatedItems, which is the pointer stamped by the
            // factory when the false-form validator is constructed.
            $this->assertSame(
                '/properties/tags/unevaluatedItems',
                $exception->getJsonPointer()->pointer,
            );
        }
    }

    /**
     * Schema-form rejection wraps a per-index aggregate around the inner validation errors.
     * The fixture array deliberately has two failing indices (integer at #1, boolean at #3)
     * so the test exercises:
     *
     *   - Aggregation across multiple failing indices in declaration order — a regression that
     *     dropped any one failure, reordered them, or collapsed them into a single line would
     *     fail the heredoc compare.
     *   - The nested exception list returned by `getInvalidItems()` keys each failure under
     *     its original index and preserves the per-failure `InvalidTypeException` unchanged,
     *     so a consumer that catches the wrapper can still surface the precise reason
     *     (type was integer / boolean, expected string) per index.
     *
     * The expected surface message is built via heredoc so the test source reads line-by-line
     * exactly as the runtime message will.
     */
    public function testSchemaFormRejectionPreservesNestedInvalidTypeExceptionForEachFailingIndex(): void
    {
        $className = $this->generateClassFromFile('NoOtherConstraintsSchema.json');

        try {
            new $className(['tags' => ['ok', 42, 'also-ok', true]]);
            $this->fail('Expected InvalidUnevaluatedItemsException');
        } catch (InvalidUnevaluatedItemsException $exception) {
            $this->assertSame(
                <<<'MSG'
                Invalid unevaluated items in array tags:
                  - invalid unevaluated item #1
                    * Invalid type for unevaluated item. Requires string, got integer
                  - invalid unevaluated item #3
                    * Invalid type for unevaluated item. Requires string, got boolean
                MSG,
                $exception->getMessage(),
            );
            $this->assertSame(
                '/properties/tags/unevaluatedItems',
                $exception->getJsonPointer()->pointer,
            );

            $invalidItems = $exception->getInvalidItems();
            $this->assertSame(
                [1, 3],
                array_keys($invalidItems),
                'only the two non-string indices should fail, keyed by their original index',
            );

            $this->assertCount(1, $invalidItems[1], 'one inner exception per failing index');
            $this->assertCount(1, $invalidItems[3], 'one inner exception per failing index');

            $integerFailure = $invalidItems[1][0];
            $this->assertInstanceOf(InvalidTypeException::class, $integerFailure);
            $this->assertSame(
                'Invalid type for unevaluated item. Requires string, got integer',
                $integerFailure->getMessage(),
            );
            $this->assertSame('string', $integerFailure->getExpectedType());

            $booleanFailure = $invalidItems[3][0];
            $this->assertInstanceOf(InvalidTypeException::class, $booleanFailure);
            $this->assertSame(
                'Invalid type for unevaluated item. Requires string, got boolean',
                $booleanFailure->getMessage(),
            );
            $this->assertSame('string', $booleanFailure->getExpectedType());
        }
    }

    /**
     * The tuple form of `items` evaluates every index it covers: index i is evaluated when
     * i < count(items) and the value at i validated against items[i]. Only indices past the
     * tuple remain for `unevaluatedItems` — per the 2019-09 annotation rules a sibling
     * applicator's claims must be credited even without any composition keyword involved.
     */
    public function testSiblingTupleItemsCreditTheirEvaluatedIndices(): void
    {
        $className = $this->generateClassFromFile('SiblingTupleItems.json');

        // Index 0 is covered by the tuple — nothing is left for unevaluatedItems.
        $accepted = new $className(['tags' => ['covered']]);
        $this->assertSame(['covered'], $accepted->getTags());

        // Index 1 lies past the tuple and no other applicator claims it — only that index
        // may be reported as unevaluated.
        try {
            new $className(['tags' => ['covered', 'surplus']]);
            $this->fail('Expected UnevaluatedItemsException for the index past the tuple');
        } catch (UnevaluatedItemsException $exception) {
            $this->assertSame(
                'Provided JSON for tags contains not allowed unevaluated items [#1]',
                $exception->getMessage(),
            );
            $this->assertSame([1], $exception->getUnevaluatedItems());
            $this->assertSame(
                '/properties/tags/unevaluatedItems',
                $exception->getJsonPointer()->pointer,
            );
        }
    }

    /**
     * A sibling `contains` evaluates exactly the indices whose values satisfy its subschema.
     * Matched indices are credited to the accumulator; every other index remains unevaluated.
     */
    public function testSiblingContainsCreditsOnlyMatchingIndices(): void
    {
        $className = $this->generateClassFromFile('SiblingContains.json');

        // The single element matches the contains subschema and is therefore evaluated.
        $accepted = new $className(['tags' => [5]]);
        $this->assertSame([5], $accepted->getTags());

        // Index 0 matches, index 1 does not — only the non-matching index is unevaluated.
        try {
            new $className(['tags' => [5, 'surplus']]);
            $this->fail('Expected UnevaluatedItemsException for the index contains did not match');
        } catch (UnevaluatedItemsException $exception) {
            $this->assertSame(
                'Provided JSON for tags contains not allowed unevaluated items [#1]',
                $exception->getMessage(),
            );
            $this->assertSame([1], $exception->getUnevaluatedItems());
        }
    }

    /**
     * An explicit `items: true` is an applicator: it validates (trivially) and annotates every
     * index, leaving nothing for `unevaluatedItems`. This mirrors the object side, where an
     * explicit `additionalProperties: true` claims every extra key — in contrast to an omitted
     * keyword, which produces no annotation.
     */
    public function testSiblingItemsTrueCreditsEveryIndex(): void
    {
        $className = $this->generateClassFromFile('SiblingItemsTrue.json');

        $accepted = new $className(['tags' => ['anything', 42]]);
        $this->assertSame(['anything', 42], $accepted->getTags());
    }

    /**
     * A composition branch may carry `unevaluatedItems` even when the array property itself
     * does not: `allOf: [{unevaluatedItems: {schema}}]`. Within the branch every index is
     * unevaluated (the branch declares no other applicator), so the branch's subschema applies
     * to every item and decides the branch outcome.
     *
     * The rejection surfaces through the property-level composition wrapping: the inner
     * unevaluatedItems failure is swallowed by the branch's try/catch and the composition
     * reports the failed branch. In direct-exception mode the composition summary carries no
     * per-element details, so the message is fully static.
     */
    public function testBranchOnlyUnevaluatedItemsValidatesEveryIndex(): void
    {
        $className = $this->generateClassFromFile('InnerUnevaluatedItemsOnlyInBranch.json');

        // Every index satisfies the branch's unevaluatedItems schema — the branch succeeds.
        $accepted = new $className(['tags' => ['alpha', 'beta']]);
        $this->assertSame(['alpha', 'beta'], $accepted->getTags());

        // A non-string item violates the branch's unevaluatedItems schema — the branch and
        // therefore the allOf composition must reject the array.
        $this->expectException(AllOfException::class);
        $this->expectExceptionMessage(
            <<<'MSG'
            Invalid value for tags declined by composition constraint.
              Requires to match all composition elements but matched 0 elements.
            MSG,
        );

        new $className(['tags' => [5]]);
    }

    /**
     * `uniqueItems: true` is orthogonal to `unevaluatedItems`. uniqueItems failure has its own
     * exception identity and message; the unevaluated check does not get to fire for that
     * array because the uniqueItems failure surfaces first.
     */
    public function testUniqueItemsExceptionIdentityIsPreserved(): void
    {
        $this->expectException(UniqueItemsException::class);
        $this->expectExceptionMessage('Items of array tags are not unique');

        $className = $this->generateClassFromFile('UnevaluatedFalseWithUniqueItems.json');
        new $className(['tags' => ['dup', 'dup']]);
    }

    /**
     * Three sibling shapes make `unevaluatedItems` unreachable; each emits a generation-time
     * warning instead of a SchemaException. The warning pin captures the developer's intended
     * class/property identifier so the source of the dead code is obvious in build output.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function deadCodeProvider(): array
    {
        return [
            // items: {schema} claims every index per the JSON Schema spec; the unevaluated
            // bucket is permanently empty, so the false form can never fire.
            'items schema form (false)' => [
                'ItemsSchemaWithUnevaluatedFalse.json',
                "sibling items: {schema} already validates every index",
            ],
            // Same dead-cell shape as above but with the schema form of unevaluatedItems —
            // the keyword still has nothing left to validate.
            'items schema form (schema)' => [
                'ItemsSchemaWithUnevaluatedSchema.json',
                "sibling items: {schema} already validates every index",
            ],
            // items: false reduces the array to empty; no index can be unevaluated.
            'items: false leaves no indices' => [
                'ItemsFalseWithUnevaluatedFalse.json',
                "sibling items: false rejects every index, leaving no unevaluated items",
            ],
            // Tuple items + additionalItems: false rejects everything past the tuple length;
            // the unevaluatedItems keyword cannot contribute.
            'tuple items with additionalItems: false' => [
                'TupleAdditionalFalseWithUnevaluatedFalse.json',
                "sibling additionalItems: false rejects every tail index past the tuple",
            ],
        ];
    }

    #[DataProvider('deadCodeProvider')]
    public function testDeadCodeShapesWarn(string $schemaFile, string $reasonFragment): void
    {
        $this->expectOutputRegex(
            '/Warning: unevaluatedItems on \S+::tags is dead code — ' . preg_quote($reasonFragment, '/') . '/',
        );

        $className = $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setOutputEnabled(true),
        );

        // Each warned shape still compiles into a working class — an empty tags array
        // constructs cleanly, proving the keyword did not break codegen.
        $instance = new $className(['tags' => []]);
        $this->assertSame(['tags' => []], $instance->meta()->rawInput());
    }

    /**
     * Non-bool / non-object values for `unevaluatedProperties` and `unevaluatedItems` must
     * fail loudly at generation time with a SchemaException. The generator never produces
     * broken code for these inputs; CLAUDE.md's "Schema error handling" rule applies.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidTypeProvider(): array
    {
        return [
            'unevaluatedItems with integer value' => [
                'InvalidUnevaluatedItemsType.json',
                'unevaluatedItems',
            ],
            'unevaluatedProperties with integer value' => [
                'InvalidUnevaluatedPropertiesType.json',
                'unevaluatedProperties',
            ],
        ];
    }

    #[DataProvider('invalidTypeProvider')]
    public function testInvalidTypeForKeywordThrowsSchemaException(
        string $schemaFile,
        string $keyword,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/^Invalid ' . preg_quote($keyword, '/') . ' 42 for property \'\S+\' in file /',
        );

        $this->generateClassFromFile($schemaFile);
    }

    /**
     * Composition branches contribute their evaluated indices to a sibling unevaluatedItems
     * accumulator. The fixture has two tuple-form items branches under allOf: branch 1 covers
     * index 0, branch 2 covers indices 0-1. When both succeed (string array), the union
     * covers 0-1 and any tail index is reported as unevaluated.
     */
    public function testAllOfBranchesContributeEvaluatedIndices(): void
    {
        $className = $this->generateClassFromFile('AllOfTupleBranches.json');

        // Both branches succeed; union covers 0-1; no tail → accept.
        $accepted = new $className(['tags' => ['alpha', 'beta']]);
        $this->assertSame(['alpha', 'beta'], $accepted->getTags());

        // Both branches succeed; union covers 0-1; index 2 is unevaluated → reject just index 2.
        try {
            new $className(['tags' => ['alpha', 'beta', 'gamma']]);
            $this->fail('Expected UnevaluatedItemsException for tail index past union');
        } catch (UnevaluatedItemsException $exception) {
            $this->assertSame(
                'Provided JSON for tags contains not allowed unevaluated items [#2]',
                $exception->getMessage(),
            );
            $this->assertSame([2], $exception->getUnevaluatedItems());
            $this->assertSame(
                '/properties/tags/unevaluatedItems',
                $exception->getJsonPointer()->pointer,
            );
        }
    }

    /**
     * Regression guard for the chain-orchestration bug where composition failure assigned
     * `$value = $proposedValue` (null) at IIFE end, causing the downstream
     * `is_array($value)` gate inside `unevaluatedItems` to short-circuit and silently
     * suppress its error in collectErrors mode. With the fix, both errors appear: the
     * composition's AllOfException listing the failing branches and the
     * UnevaluatedItemsException listing every index as unevaluated (composition contributed
     * no claims on failure).
     */
    public function testCompositionFailurePreservesArrayValueSoUnevaluatedItemsCheckStillFires(): void
    {
        $className = $this->generateClassFromFile(
            'AllOfTupleBranches.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        try {
            new $className(['tags' => [1, 2]]);
            $this->fail('Expected ErrorRegistryException combining composition + unevaluated errors');
        } catch (ErrorRegistryException $exception) {
            $this->assertSame(
                <<<'MSG'
                Invalid value for tags declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Invalid tuple item in array tags:
                      - invalid tuple #1
                        * Invalid type for tuple item #0 of array tags. Requires string, got integer
                  - Composition element #2: Failed
                    * Invalid tuple item in array tags:
                      - invalid tuple #1
                        * Invalid type for tuple item #0 of array tags. Requires string, got integer
                      - invalid tuple #2
                        * Invalid type for tuple item #1 of array tags. Requires string, got integer
                Provided JSON for tags contains not allowed unevaluated items [#0, #1]
                MSG,
                $exception->getMessage(),
            );
        }
    }

    /**
     * Two properties on the same class — one array with composition + `unevaluatedItems:
     * false`, one object with composition + `unevaluatedProperties: false` — exercise the
     * structural separation between `_compositionAnnotated` (array side) and
     * `_compositionEvaluations` (object side). Each accumulator reads from its own field, so
     * cross-contamination between the two paths is impossible by construction.
     */
    public function testArrayAndObjectPropertyCompositionsCoexistOnTheSameClass(): void
    {
        $className = $this->generateClassFromFile('ArrayAndObjectPropertyCompositionsCoexist.json');

        $accepted = new $className([
            'tags' => ['only'],
            'meta' => ['kind' => 'X'],
        ]);
        $this->assertSame(['only'], $accepted->getTags());
        $this->assertSame('X', $accepted->getMeta()->getKind());
    }

    public function testArrayPropertyUnevaluatedItemsRejectsTailIndexWhileObjectPropertyAccepts(): void
    {
        $className = $this->generateClassFromFile('ArrayAndObjectPropertyCompositionsCoexist.json');

        $this->expectException(UnevaluatedItemsException::class);
        $this->expectExceptionMessage('Provided JSON for tags contains not allowed unevaluated items [#1]');

        new $className([
            'tags' => ['head', 'tail'],
            'meta' => ['kind' => 'X'],
        ]);
    }

    /**
     * Regression guard for the extracted-method-name collision: two compositions on the
     * same property previously hashed to the same `_validateTags_ComposedProperty_<hash>`
     * method (the hash was derived from the property's JSON alone), so the second
     * registration overwrote the first and both call sites invoked the same body. With
     * the fix mixing the validator's object hash into the method name, each composition
     * keeps its own body and writes to its own slot key.
     */
    public function testMultipleSiblingCompositionsContributeIndependently(): void
    {
        $className = $this->generateClassFromFile('MultipleCompositionsOnProperty.json');

        // allOf branch (tuple-1) + oneOf branch (tuple-2) both succeed on two-string array.
        // Union {0, 1} covers everything → accept.
        $accepted = new $className(['tags' => ['a', 'b']]);
        $this->assertSame(['a', 'b'], $accepted->getTags());

        // Three-element string array: both branches still succeed (tuples allow extras);
        // union is still {0, 1}; index 2 unevaluated.
        try {
            new $className(['tags' => ['a', 'b', 'c']]);
            $this->fail('Expected UnevaluatedItemsException for tail past widest tuple');
        } catch (UnevaluatedItemsException $exception) {
            $this->assertSame(
                'Provided JSON for tags contains not allowed unevaluated items [#2]',
                $exception->getMessage(),
            );
            $this->assertSame([2], $exception->getUnevaluatedItems());
        }
    }

    /**
     * Regression guard for the PropertyProxy clone reset:
     * `CompositionPropertyDecorator::getOrderedValidators()` returns fresh
     * `withProperty(...)` clones on every call. A previous fix attempt set
     * `setTrackBranchMatches(true)` on the clones returned to the post-processor, leaving
     * the validator instances actually emitted into generated code with the flag still
     * false; contains-matched indices were silently dropped. With the fix iterating the
     * wrapped property's source validators via `getWrappedProperty()`, the flag reaches
     * the rendered instance.
     *
     * Three-element array `['a', 5, 'c']`: contains matches index 1 (integer). The branch
     * claims {1}; sibling unevaluatedItems reports {0, 2} as unevaluated — not {0, 1, 2}.
     */
    public function testContainsOnlyBranchClaimsMatchedIndex(): void
    {
        $className = $this->generateClassFromFile('ContainsOnlyBranch.json');

        // All-integer array: contains matches every index; nothing left unevaluated.
        $accepted = new $className(['tags' => [1, 2, 3]]);
        $this->assertSame([1, 2, 3], $accepted->getTags());

        // Mixed array: contains matches index 1; non-matching indices fail unevaluatedItems.
        try {
            new $className(['tags' => ['a', 5, 'c']]);
            $this->fail('Expected UnevaluatedItemsException for non-matching indices');
        } catch (UnevaluatedItemsException $exception) {
            $this->assertSame(
                'Provided JSON for tags contains not allowed unevaluated items [#0, #2]',
                $exception->getMessage(),
            );
            $this->assertSame([0, 2], $exception->getUnevaluatedItems());
        }
    }

    /**
     * Items + contains combined branch — tuple items claims index 0, contains matches its
     * own indices, and the branch's evaluated set is the union of both. Items covers index 0
     * (string), contains matches index 1 (integer). Together they cover the whole two-
     * element array — accept.
     */
    public function testItemsPlusContainsBranchUnionsBothClaimSources(): void
    {
        $className = $this->generateClassFromFile('ItemsPlusContainsBranch.json');

        // Items claims index 0, contains claims index 1 → union covers everything → accept.
        $accepted = new $className(['tags' => ['head', 5]]);
        $this->assertSame(['head', 5], $accepted->getTags());

        // Three-element array: items covers 0, contains covers 1; index 2 is unevaluated.
        try {
            new $className(['tags' => ['head', 5, 'tail']]);
            $this->fail('Expected UnevaluatedItemsException for unclaimed index');
        } catch (UnevaluatedItemsException $exception) {
            $this->assertSame(
                'Provided JSON for tags contains not allowed unevaluated items [#2]',
                $exception->getMessage(),
            );
            $this->assertSame([2], $exception->getUnevaluatedItems());
        }
    }

    /**
     * Regression guard for the recursive activation walk in
     * `UnevaluatedPropertiesPostProcessor::activateArrayComposition()`: a schema like
     * `{type: array, allOf: [{$ref: "#/definitions/recursive"}], unevaluatedItems: false}`
     * where the $ref resolves back to the same schema produces a composition validator
     * whose composed branch's wrapped property carries the same composition validator
     * instance. Without the cycle break, the walk recurses indefinitely; with it, the walk
     * short-circuits on the second visit.
     *
     * The cycle-break guard was verified by hand: removing it from
     * `activateArrayComposition()` and running this fixture causes the process to abort
     * with `Xdebug has detected a possible infinite loop, and aborted your script with a
     * stack depth of '512' frames`, with the offending frames pointing at the
     * `activateArrayComposition` / `activateValidatorsInBranch` pair. With the guard
     * restored, the walk terminates.
     *
     * The test is marked incomplete because end-to-end codegen still hits a *second*
     * infinite recursion in `CompositionTypeHintDecorator::getTypeHint()` on the same
     * fixture — a pre-existing bug in the type-hint computation path that has no cycle
     * protection of its own. Fixing that is outside the unevaluatedItems work; the test
     * will be promoted to a real assertion once the type-hint recursion is closed.
     */
    public function testSelfReferencingArrayCompositionDoesNotRecurseIndefinitely(): void
    {
        $this->markTestIncomplete(
            'Activation-walk cycle break is in place and verified by hand. End-to-end '
            . 'codegen blocked by an unrelated infinite recursion in '
            . 'CompositionTypeHintDecorator::getTypeHint() on self-referencing schemas '
            . '(deferred bug; tracked in the implementation plan).',
        );
    }

    /**
     * Spec propagation: a nested `unevaluatedItems` schema-form validator inside a
     * successful `allOf` branch must contribute its claimed indices to the enclosing
     * `unevaluatedItems` accumulator. The inner validator writes
     * `_evaluatedItemIndices['tags'][$index] = true` after each successful per-index
     * validation; the outer reads that map via `collectUnevaluatedIndices()`.
     *
     * Without the inner write, the outer `false` form would see every index as
     * unevaluated and reject a perfectly valid all-string array; with the write, the
     * outer credits the indices the inner already cleared.
     */
    public function testInnerUnevaluatedItemsInAllOfBranchPropagatesIndicesToOuterAccumulator(): void
    {
        $className = $this->generateClassFromFile('InnerUnevaluatedItemsInBranch.json');

        // Both indices validate as strings against the inner schema; inner writes
        // [0, 1] into _evaluatedItemIndices['tags']; outer's false form sees zero
        // unevaluated indices → accept.
        $accepted = new $className(['tags' => ['alpha', 'beta']]);
        $this->assertSame(['alpha', 'beta'], $accepted->getTags());
    }

    /**
     * Snapshot/restore guard: when the nested `unevaluatedItems` inside an `allOf`
     * branch FAILS (any per-index check yields an invalid item), the inner template
     * must restore `_evaluatedItemIndices` to its pre-IIFE state so no partial writes
     * leak to the outer accumulator. Under `collectErrors` mode the chain continues
     * past the failing composition so the outer `false` form runs against the
     * snapshot-restored state — if the rollback worked, every index appears
     * unevaluated and is reported in the outer error.
     *
     * Without the snapshot/restore, indices 0 and 2 (the successfully-validated
     * string entries) would leak into `_evaluatedItemIndices['tags']` and the outer
     * would mistakenly report only index 1 as unevaluated.
     */
    public function testInnerUnevaluatedItemsFailureRollsBackPartialWritesUnderCollectErrors(): void
    {
        $className = $this->generateClassFromFile(
            'InnerUnevaluatedItemsInBranch.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        try {
            new $className(['tags' => ['alpha', 42, 'gamma']]);
            $this->fail('Expected ErrorRegistryException combining composition + outer unevaluated errors');
        } catch (ErrorRegistryException $exception) {
            $this->assertSame(
                <<<'MSG'
                Invalid value for tags declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Invalid unevaluated items in array tags:
                      - invalid unevaluated item #1
                        * Invalid type for unevaluated item. Requires string, got integer
                Provided JSON for tags contains not allowed unevaluated items [#0, #1, #2]
                MSG,
                $exception->getMessage(),
            );
        }
    }

    /**
     * `contains` with `minContains: 0` credits every index whose value matched the contains
     * schema — even when zero matches would still satisfy the branch. The evaluated set is
     * driven by which indices matched, not by the count. Non-matching indices remain
     * unevaluated and the outer `unevaluatedItems: false` rejects them.
     *
     * Two assertions on the same generated class:
     *   - `[]` accepts because no indices exist to be evaluated; contains-empty is legal
     *     under minContains: 0 and the branch succeeds.
     *   - `[1, 'a', 2, 'b', 3]` — indices 0, 2, 4 match the integer contains schema; indices
     *     1, 3 are non-matching and surface as unevaluated.
     */
    public function testContainsWithMinContainsZeroCreditsMatchedIndicesOnly(): void
    {
        $className = $this->generateClassFromFile('ContainsMinContainsZeroBranch.json');

        // Empty array: contains matches zero indices but minContains: 0 keeps the branch
        // successful. Nothing is unevaluated because there are no indices to evaluate.
        $accepted = new $className(['tags' => []]);
        $this->assertSame([], $accepted->getTags());

        // Mixed array: only integer indices are credited; string indices fall through to
        // unevaluatedItems: false and are reported in declaration order.
        try {
            new $className(['tags' => [1, 'a', 2, 'b', 3]]);
            $this->fail('Expected UnevaluatedItemsException for the two non-matching indices');
        } catch (UnevaluatedItemsException $exception) {
            $this->assertSame(
                'Provided JSON for tags contains not allowed unevaluated items [#1, #3]',
                $exception->getMessage(),
            );
            $this->assertSame([1, 3], $exception->getUnevaluatedItems());
        }
    }

    /**
     * `oneOf` with two branches of different tuple lengths: the branch that succeeds
     * determines how many indices are evaluated. Only one branch may succeed at a time
     * because otherwise `oneOf` fails as a whole. The outer `unevaluatedItems: false` then
     * inspects the surviving branch's evaluated indices and rejects any tail past that
     * length.
     *
     * Three assertions on the same generated class:
     *   - `['a', 'b']` matches only branch 0 (strings, tuple length 2); no tail indices,
     *     accepted.
     *   - `[1, 2, 3]` matches only branch 1 (integers, tuple length 3); no tail indices,
     *     accepted.
     *   - `[1, 2, 3, 4]` — branch 1 succeeds and covers indices 0-2, but index 3 is not
     *     covered by any successful branch. `unevaluatedItems: false` rejects.
     */
    public function testOneOfBranchesOfDifferentTupleLengthsControlEvaluatedSet(): void
    {
        $className = $this->generateClassFromFile('OneOfDifferentTupleLengths.json');

        $acceptedStrings = new $className(['tags' => ['a', 'b']]);
        $this->assertSame(['a', 'b'], $acceptedStrings->getTags());

        $acceptedIntegers = new $className(['tags' => [1, 2, 3]]);
        $this->assertSame([1, 2, 3], $acceptedIntegers->getTags());

        try {
            new $className(['tags' => [1, 2, 3, 4]]);
            $this->fail('Expected UnevaluatedItemsException for index 3 past widest surviving tuple');
        } catch (UnevaluatedItemsException $exception) {
            $this->assertSame(
                'Provided JSON for tags contains not allowed unevaluated items [#3]',
                $exception->getMessage(),
            );
            $this->assertSame([3], $exception->getUnevaluatedItems());
        }
    }
}
