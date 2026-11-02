<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use DateTime;
use Exception;
use PHPModelGenerator\Exception\Arrays\ContainsException;
use PHPModelGenerator\Exception\Arrays\InvalidAdditionalTupleItemsException;
use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\Exception\Arrays\InvalidTupleException;
use PHPModelGenerator\Exception\Arrays\InvalidUnevaluatedItemsException;
use PHPModelGenerator\Exception\Arrays\UnevaluatedItemsException;
use PHPModelGenerator\Exception\Arrays\UniqueItemsException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\Object\InvalidAdditionalPropertiesException;
use PHPModelGenerator\Exception\Object\InvalidPatternPropertiesException;
use PHPModelGenerator\Exception\Object\InvalidUnevaluatedPropertiesException;
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
                "Provided JSON for 'tags' contains not allowed unevaluated items [#0]",
            ],
            // Three-element array — exercises the comma-separated list path so a future change
            // to the joiner shows up here.
            'reports every offending index' => [
                ['tags' => ['a', 'b', 'c']],
                "Provided JSON for 'tags' contains not allowed unevaluated items [#0, #1, #2]",
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
                Invalid unevaluated items in array 'tags':
                  - invalid unevaluated item #1
                    * Invalid type for 'unevaluated item': requires 'string', got 'integer'
                  - invalid unevaluated item #3
                    * Invalid type for 'unevaluated item': requires 'string', got 'boolean'
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
                "Invalid type for 'unevaluated item': requires 'string', got 'integer'",
                $integerFailure->getMessage(),
            );
            $this->assertSame('string', $integerFailure->getExpectedType());

            $booleanFailure = $invalidItems[3][0];
            $this->assertInstanceOf(InvalidTypeException::class, $booleanFailure);
            $this->assertSame(
                "Invalid type for 'unevaluated item': requires 'string', got 'boolean'",
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
                "Provided JSON for 'tags' contains not allowed unevaluated items [#1]",
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
                "Provided JSON for 'tags' contains not allowed unevaluated items [#1]",
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
     * reports the failed branch, including the branch's own per-element failure detail.
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
            Invalid value for 'tags' declined by composition constraint
              Requires to match all composition elements but matched 0 elements
            MSG,
        );

        new $className(['tags' => [5]]);
    }

    /**
     * A successful composition branch that carries an explicit all-index applicator evaluates
     * every index of the array, exactly like the same keyword as a sibling would. `items: true`,
     * `items: {}` and `unevaluatedItems: true` all validate trivially and annotate every index;
     * only an omitted keyword produces no annotation. The schema-form `items` row is the
     * control: it already credited every index before the explicit-`true` shapes were handled.
     *
     * @return array<string, array{0: string, 1: list<mixed>}>
     */
    public static function branchAllIndexClaimProvider(): array
    {
        return [
            'branch items: true' => ['{"items": true}', ['a', 5]],
            'branch items: {} (empty schema object)' => ['{"items": {}}', ['a', 5]],
            'branch unevaluatedItems: true' => ['{"unevaluatedItems": true}', ['a', 5]],
            'branch items: {schema} (control)' => ['{"items": {"type": "string"}}', ['a', 'b']],
        ];
    }

    #[DataProvider('branchAllIndexClaimProvider')]
    public function testBranchClaimingEveryIndexCreditsTheOuterAccumulator(string $branchJson, array $tags): void
    {
        $className = $this->generateClassFromFileTemplate('BranchClaimsEveryIndex.json', [$branchJson], null, false);

        $accepted = new $className(['tags' => $tags]);
        $this->assertSame($tags, $accepted->getTags());
    }

    /**
     * Only a branch that actually succeeded credits indices. The first anyOf branch claims every
     * index through `items: true` but fails on `maxItems`, so for a two-element array the claim is
     * discarded; the second branch succeeds without any applicator and credits nothing. Both
     * indices stay unevaluated. A one-element array satisfies the first branch, whose claim then
     * covers the index.
     */
    public function testFailingBranchWithAllIndexClaimCreditsNothing(): void
    {
        $className = $this->generateClassFromFile('AnyOfFailingBranchCreditsNothing.json');

        $accepted = new $className(['tags' => ['only']]);
        $this->assertSame(['only'], $accepted->getTags());

        try {
            new $className(['tags' => ['first', 'second']]);
            $this->fail('Expected UnevaluatedItemsException: the failed branch must not credit its indices');
        } catch (UnevaluatedItemsException $exception) {
            $this->assertSame(
                "Provided JSON for 'tags' contains not allowed unevaluated items [#0, #1]",
                $exception->getMessage(),
            );
            $this->assertSame([0, 1], $exception->getUnevaluatedItems());
        }
    }

    /**
     * An `if` condition that passes keeps its annotations even when it only carries an
     * all-index applicator: `if: {items: true, minItems: 2}` evaluates every index of a
     * two-element array. When the condition fails (one element) no annotation survives and the
     * absent `else` credits nothing.
     */
    public function testPassingIfConditionWithAllIndexClaimCreditsTheOuterAccumulator(): void
    {
        $className = $this->generateClassFromFile('IfBranchItemsTrue.json');

        $accepted = new $className(['tags' => ['a', 'b']]);
        $this->assertSame(['a', 'b'], $accepted->getTags());

        try {
            new $className(['tags' => ['solo']]);
            $this->fail('Expected UnevaluatedItemsException: the failed if condition credits nothing');
        } catch (UnevaluatedItemsException $exception) {
            $this->assertSame(
                "Provided JSON for 'tags' contains not allowed unevaluated items [#0]",
                $exception->getMessage(),
            );
            $this->assertSame([0], $exception->getUnevaluatedItems());
        }
    }

    /**
     * `uniqueItems: true` is orthogonal to `unevaluatedItems`. uniqueItems failure has its own
     * exception identity and message; the unevaluated check does not get to fire for that
     * array because the uniqueItems failure surfaces first.
     */
    public function testUniqueItemsExceptionIdentityIsPreserved(): void
    {
        $this->expectException(UniqueItemsException::class);
        $this->expectExceptionMessage("Items of array 'tags' are not unique");

        $className = $this->generateClassFromFile('UnevaluatedFalseWithUniqueItems.json');
        new $className(['tags' => ['dup', 'dup']]);
    }

    /**
     * Several sibling shapes make `unevaluatedItems` unreachable; each emits a generation-time
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
            // Tuple items + additionalItems: true accepts and evaluates every tail index; with the
            // tuple covering the head, every index is claimed.
            'tuple items with additionalItems: true' => [
                'TupleAdditionalTrueWithUnevaluatedSchema.json',
                "sibling additionalItems: true already claims every tail index past the tuple",
            ],
            // Tuple items + additionalItems: {schema} validates and evaluates every tail index.
            'tuple items with additionalItems: {schema}' => [
                'TupleAdditionalSchemaWithUnevaluatedSchema.json',
                "sibling additionalItems: {schema} already validates every tail index past the tuple",
            ],
        ];
    }

    #[DataProvider('deadCodeProvider')]
    public function testDeadCodeShapesWarn(string $schemaFile, string $reason): void
    {
        $logger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setLogger($logger),
        );

        $this->assertTrue(
            $this->hasLogEntry(
                $logger->getEntries(),
                'warning',
                'unevaluatedItems on {class}::{property} is dead code — {reason}',
                ['property' => 'tags', 'reason' => $reason],
            ),
            'Expected a warning naming the dead unevaluatedItems keyword on tags',
        );

        // Each warned shape still compiles into a working class — an empty tags array
        // constructs cleanly, proving the keyword did not break codegen.
        $instance = new $className(['tags' => []]);
        $this->assertSame(['tags' => []], $instance->meta()->rawInput());
    }

    /**
     * With `additionalItems: {schema}` beside a tuple, the tail is validated (and evaluated) by
     * `additionalItems` alone. The skipped `unevaluatedItems` schema must not influence the
     * outcome: a tail value is accepted or rejected exactly as `additionalItems` decides, never by
     * the unevaluated schema (here a boolean, which no integer tail value could satisfy).
     */
    public function testSkippedUnevaluatedItemsDoesNotInfluenceTheAdditionalItemsOutcome(): void
    {
        $className = $this->generateClassFromFile('TupleAdditionalSchemaWithUnevaluatedSchema.json');

        $accepted = new $className(['tags' => ['head', 1, 2]]);
        $this->assertSame(['head', 1, 2], $accepted->getTags());

        try {
            new $className(['tags' => ['head', true]]);
            $this->fail('Expected additionalItems to reject the non-integer tail value');
        } catch (InvalidAdditionalTupleItemsException $exception) {
            $this->assertSame('tags', $exception->getPropertyName());
        }
    }

    /**
     * Values that are neither a boolean nor a schema object must fail loudly at generation time
     * with a SchemaException naming the offending value and the property or class carrying the keyword;
     * the generator never produces broken code for these inputs. Covers the scalar shapes, an
     * explicit `null` (a present-but-null keyword is invalid, not absent), and a non-empty JSON
     * list (a list is not a schema object and must not reach the schema processor).
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    public static function invalidTypeProvider(): array
    {
        return [
            'unevaluatedItems with integer value' => [
                'InvalidUnevaluatedItemsValue.json',
                'unevaluatedItems',
                '42',
                '42',
                'property',
                'tags',
            ],
            'unevaluatedItems with null value' => [
                'InvalidUnevaluatedItemsValue.json',
                'unevaluatedItems',
                'null',
                'NULL',
                'property',
                'tags',
            ],
            'unevaluatedItems with list value' => [
                'InvalidUnevaluatedItemsValue.json',
                'unevaluatedItems',
                '["a"]',
                "array (  0 => 'a',)",
                'property',
                'tags',
            ],
            'unevaluatedProperties with integer value' => [
                'InvalidUnevaluatedPropertiesValue.json',
                'unevaluatedProperties',
                '42',
                '42',
                'class',
                '\\S+',
            ],
            'unevaluatedProperties with null value' => [
                'InvalidUnevaluatedPropertiesValue.json',
                'unevaluatedProperties',
                'null',
                'NULL',
                'class',
                '\\S+',
            ],
            'unevaluatedProperties with list value' => [
                'InvalidUnevaluatedPropertiesValue.json',
                'unevaluatedProperties',
                '[{"type": "string"}]',
                "array (  0 =>   array (    'type' => 'string',  ),)",
                'class',
                '\\S+',
            ],
        ];
    }

    #[DataProvider('invalidTypeProvider')]
    public function testInvalidTypeForKeywordThrowsSchemaException(
        string $schemaFile,
        string $keyword,
        string $jsonValue,
        string $renderedValue,
        string $subjectKind,
        string $subjectNamePattern,
    ): void {
        // The object-side keyword is processed against the nested class, so its message names the
        // generated class (whose name carries a run-specific suffix); the array-side message names
        // the array property.
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/^' . preg_quote("Invalid $keyword $renderedValue for $subjectKind '", '/')
                . $subjectNamePattern . '\' in file \S+ at line \d+, column \d+$/',
        );

        $this->generateClassFromFileTemplate($schemaFile, [$jsonValue], null, false);
    }

    /**
     * `unevaluatedItems` declared on a non-array-typed property is inapplicable per spec — array
     * applicators impose no constraint on a value that is not an array — and must be silently
     * ignored: no array-index validator is emitted for the property, and the property behaves
     * exactly as if `unevaluatedItems` were absent. Regression guard for
     * `activateArrayPropertyTracking`'s type-mismatch skip actually being a no-op rather than a
     * state-corruption path (e.g. wiring up index-tracking machinery for a scalar property, or
     * suppressing an unrelated sibling keyword). A sibling `unevaluatedProperties: false` on the
     * parent still activates and works normally, proving this property's malformed-for-its-type
     * keyword does not interfere with unrelated validators on the class.
     */
    public function testUnevaluatedItemsOnNonArrayPropertyIsSilentlyIgnored(): void
    {
        $className = $this->generateClassFromFile('UnevaluatedItemsOnNonArrayProperty.json');

        $accepted = new $className(['tags' => 'hello']);
        $this->assertSame('hello', $accepted->getTags());
        $this->assertSame(['tags' => 'hello'], $accepted->meta()->rawInput());

        try {
            new $className(['tags' => 'hello', 'extra' => 1]);
            $this->fail('unevaluatedProperties: false on the parent must still reject unclaimed keys');
        } catch (UnevaluatedPropertiesException $exception) {
            $this->assertSame(
                "Provided JSON for '{$className}' contains not allowed unevaluated properties ['extra']",
                $exception->getMessage(),
            );
        }
    }

    /**
     * `items: null` is not a valid `items` value (the keyword must be a boolean or a schema),
     * but the dead-code classifier must still degrade gracefully rather than misclassifying it
     * as one of the three recognised dead-code shapes (`items: false`, tuple-form with
     * `additionalItems: false`, or `items: {schema}`). `null` claims nothing, so
     * `unevaluatedItems: false` correctly falls through to "not dead code" and rejects every
     * index — proving the classifier's final fallback branch is a safe default, not a silent
     * misclassification that would let a malformed `items` value slip through unvalidated.
     */
    public function testItemsNeitherBooleanTupleNorSchemaFallsThroughToNotDeadCode(): void
    {
        $className = $this->generateClassFromFile('ItemsNeitherBooleanTupleNorSchema.json');

        try {
            new $className(['tags' => ['a', 'b']]);
            $this->fail('Expected UnevaluatedItemsException since items: null claims no indices');
        } catch (UnevaluatedItemsException $exception) {
            $this->assertSame(
                "Provided JSON for 'tags' contains not allowed unevaluated items [#0, #1]",
                $exception->getMessage(),
            );
        }
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
                "Provided JSON for 'tags' contains not allowed unevaluated items [#2]",
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
                Invalid value for 'tags' declined by composition constraint
                  Requires to match all composition elements but matched 0 elements
                  - Composition element #1: Failed
                    * Invalid tuple item in array 'tags':
                      - invalid tuple #1
                        * Invalid type for 'tuple item #0 of array tags': requires 'string', got 'integer'
                  - Composition element #2: Failed
                    * Invalid tuple item in array 'tags':
                      - invalid tuple #1
                        * Invalid type for 'tuple item #0 of array tags': requires 'string', got 'integer'
                      - invalid tuple #2
                        * Invalid type for 'tuple item #1 of array tags': requires 'string', got 'integer'
                Provided JSON for 'tags' contains not allowed unevaluated items [#0, #1]
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
        $this->expectExceptionMessage("Provided JSON for 'tags' contains not allowed unevaluated items [#1]");

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
                "Provided JSON for 'tags' contains not allowed unevaluated items [#2]",
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
                "Provided JSON for 'tags' contains not allowed unevaluated items [#0, #2]",
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
                "Provided JSON for 'tags' contains not allowed unevaluated items [#2]",
                $exception->getMessage(),
            );
            $this->assertSame([2], $exception->getUnevaluatedItems());
        }
    }

    /**
     * A composition (`oneOf`) nested inside a composition branch (`allOf`) on an array-typed
     * property, with `unevaluatedItems` declared only on the innermost branch and nowhere at
     * the outer property level, used to crash construction with `Error: Call to undefined
     * method ...::collectUnevaluatedIndices()` rather than validate or reject cleanly.
     *
     * Root cause: `UnevaluatedPropertiesPostProcessor::compositionValidatorsNeedActivation()`
     * (and its property-level sibling `propertyHasBranchUnevaluatedItems()`) checked a
     * branch's own JSON directly and recursed into a branch's nested `Schema` — but an
     * array-typed branch never gets its own nested `Schema` (unlike an object-typed one,
     * which always routes through `processSchema()`), so a further composition nested
     * directly inside another branch's own JSON (no intervening object type) was invisible to
     * the detection walk. The schema-processing step that renders the innermost branch's own
     * `unevaluatedItems` validator is not gated by that detection at all, so the call to
     * `collectUnevaluatedIndices()` (from `CompositionEvaluationTrait`) rendered into the
     * class regardless — but the trait itself, and the `_evaluatedItemIndices` field, were
     * never added, since the post processor's `process()` short-circuited on the (wrongly)
     * negative detection result before reaching either.
     *
     * Fixed by recursing through the branch's wrapped property's own validators — mirroring
     * how `activateValidatorsInBranch()` already walks this exact structure for the
     * activation step itself — whenever a composed property's own nested `Schema` is null.
     */
    public function testCompositionNestedInsideArrayBranchIsNotSilentlyDropped(): void
    {
        $className = $this->generateClassFromFile('NestedCompositionInsideArrayBranchSilentlyDropped.json');

        $accepted = new $className(['tags' => ['a', 'b']]);
        $this->assertSame(['a', 'b'], $accepted->getTags());

        try {
            new $className(['tags' => [1, 2]]);
            $this->fail('Expected the innermost branch\'s unevaluatedItems: {type: string} to reject integers');
        } catch (AllOfException $exception) {
            $this->assertStringContainsString(
                "Invalid unevaluated items in array 'tags'",
                $exception->getMessage(),
            );
        }
    }

    /**
     * A composition (`allOf`) nested two levels inside another composition (`allOf` inside
     * `allOf`) on an array property, with `unevaluatedItems: false` declared at the *outer*
     * property level (not inside a branch). The innermost branch's own tuple `items` claims
     * indices 0-1; that claim must reach the outer `unevaluatedItems` accumulator through both
     * levels of nesting, not just the first.
     *
     * Root cause (distinct from `testCompositionNestedInsideArrayBranchIsNotSilentlyDropped`
     * above — that one is an activation-detection gap causing a fatal error; this one is a
     * downstream aggregation gap once activation is already correctly triggered):
     * `ComposedItem.phptpl`'s per-branch index-set block only populated
     * `$compositionEvaluatedIndices` from a branch's own *direct* `items`/`additionalItems`/
     * `contains` shape (`CompositionPropertyDecorator::branchIsArrayKind()`). A branch that is
     * itself purely a nested composition (`{allOf: [{items: [...]}]}`, with no array
     * applicator of its own) fails that check, so the block never ran for it — its own nested
     * composition's tracked slot (`_compositionAnnotated['tags_1']`) was computed correctly
     * but never read back into the enclosing branch's own slot (`_compositionAnnotated
     * ['tags_0']`), which the outer `unevaluatedItems: false` reads. Before the fix, all three
     * indices were reported unevaluated instead of just index 2.
     *
     * Fixed by `CompositionPropertyDecorator::getNestedCompositionSlotKeys()`: the indices of
     * any composition validator nested directly inside a branch's own JSON (found by walking
     * the branch's wrapped property's own validators, the same way the activation step already
     * does) get unioned into the branch's own evaluated set.
     */
    public function testCompositionNestedTwoLevelsDeepCreditsOuterAccumulator(): void
    {
        $className = $this->generateClassFromFile('CompositionNestedTwoLevelsDeepCreditsOuterAccumulator.json');

        // Exactly the tuple length: both indices covered by the innermost branch — accept.
        $accepted = new $className(['tags' => ['a', 'b']]);
        $this->assertSame(['a', 'b'], $accepted->getTags());

        // A trailing element past the tuple is not covered by any level of the nesting.
        try {
            new $className(['tags' => ['a', 'b', 'c']]);
            $this->fail('Expected UnevaluatedItemsException for the index past the nested tuple');
        } catch (UnevaluatedItemsException $exception) {
            $this->assertSame(
                "Provided JSON for 'tags' contains not allowed unevaluated items [#2]",
                $exception->getMessage(),
            );
            $this->assertSame([2], $exception->getUnevaluatedItems());
        }
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
                Invalid value for 'tags' declined by composition constraint
                  Requires to match all composition elements but matched 0 elements
                  - Composition element #1: Failed
                    * Invalid unevaluated items in array 'tags':
                      - invalid unevaluated item #1
                        * Invalid type for 'unevaluated item': requires 'string', got 'integer'
                Provided JSON for 'tags' contains not allowed unevaluated items [#0, #1, #2]
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
                "Provided JSON for 'tags' contains not allowed unevaluated items [#1, #3]",
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
                "Provided JSON for 'tags' contains not allowed unevaluated items [#3]",
                $exception->getMessage(),
            );
            $this->assertSame([3], $exception->getUnevaluatedItems());
        }
    }

    /**
     * `if`/`then`/`else` on an array property: whichever of `then`/`else` actually runs
     * determines how many indices are evaluated, exercising `ConditionalComposedItem.phptpl`'s
     * array-side branch. The fixture's `if` checks whether index 0 is the literal `"typed"`:
     * when it is, `then` requires a 2-tuple (`"typed"` then any string) and covers indices 0-1;
     * otherwise `else` requires a 1-tuple (an integer) and covers only index 0. `unevaluatedItems:
     * false` rejects whatever index neither branch of the winning path covers.
     *
     * Four assertions on the same generated class:
     *   - `['typed', 'x']` — `if` passes, `then` covers both indices, accepted;
     *   - `['typed', 'x', 'extra']` — `if` passes, `then` covers 0-1, index 2 unevaluated;
     *   - `[5]` — `if` fails (index 0 is not `"typed"`), `else` covers index 0, accepted;
     *   - `[5, 6]` — `if` fails, `else` covers only index 0, index 1 unevaluated.
     */
    public function testIfThenElseArrayCompositionCreditsTheWinningBranchesIndices(): void
    {
        $className = $this->generateClassFromFile('IfThenElseArrayUnevaluatedItems.json');

        $acceptedThen = new $className(['tags' => ['typed', 'x']]);
        $this->assertSame(['typed', 'x'], $acceptedThen->getTags());

        try {
            new $className(['tags' => ['typed', 'x', 'extra']]);
            $this->fail('Expected UnevaluatedItemsException for the index past the then-branch tuple');
        } catch (UnevaluatedItemsException $exception) {
            $this->assertSame(
                "Provided JSON for 'tags' contains not allowed unevaluated items [#2]",
                $exception->getMessage(),
            );
            $this->assertSame([2], $exception->getUnevaluatedItems());
        }

        $acceptedElse = new $className(['tags' => [5]]);
        $this->assertSame([5], $acceptedElse->getTags());

        try {
            new $className(['tags' => [5, 6]]);
            $this->fail('Expected UnevaluatedItemsException for the index past the else-branch tuple');
        } catch (UnevaluatedItemsException $exception) {
            $this->assertSame(
                "Provided JSON for 'tags' contains not allowed unevaluated items [#1]",
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
     * A transforming filter (here `dateTime`) declared inside `unevaluatedItems`'s subschema
     * behaves like a filter on any other property: the transformed value is persisted (the
     * getter reports `DateTime` instances, not the raw strings), an already-transformed value
     * passed directly is accepted (the type check is widened via
     * `TransformingFilterOutputTypePostProcessor`, which recurses into
     * `UnevaluatedItemsValidator::getValidationProperty()` the same way it already does for
     * `UnevaluatedPropertiesValidator`), and a mixed list of raw and already-transformed values
     * is accepted since each index is validated independently.
     *
     * Serialization applies the filter's outputFormat to every index credited to the
     * unevaluatedItems validator, turning each DateTime back into the raw representation the
     * filter accepts.
     *
     * An invalid date string still fails the filter's own validation.
     */
    public function testTransformingFilterPersistsAndAcceptsAlreadyTransformedValues(): void
    {
        $className = $this->generateClassFromFile(
            'SchemaFormWithTransformingFilter.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        // Valid raw date strings pass the filter and the transformed value is persisted.
        $accepted = new $className(['tags' => ['2020-10-10', '2020-12-12']]);
        $this->assertEquals(
            [new DateTime('2020-10-10'), new DateTime('2020-12-12')],
            $accepted->getTags(),
        );
        $this->assertSame(['2020-10-10', '2020-12-12'], $accepted->meta()->rawInput()['tags']);

        $this->assertSame(['tags' => ['20201010', '20201212']], $accepted->toArray());
        $decoded = json_decode($accepted->toJSON(), true);
        $this->assertSame(['tags' => ['20201010', '20201212']], $decoded);

        // An already-transformed DateTime passed directly is accepted and persisted as-is.
        $alreadyTransformed = new $className(['tags' => [new DateTime('2020-10-10')]]);
        $this->assertEquals([new DateTime('2020-10-10')], $alreadyTransformed->getTags());
        $this->assertEquals([new DateTime('2020-10-10')], $alreadyTransformed->meta()->rawInput()['tags']);

        // A mixed list of raw and already-transformed values is accepted — each index is
        // validated independently.
        $mixed = new $className(['tags' => ['2020-10-10', new DateTime('2020-12-12')]]);
        $this->assertEquals(
            [new DateTime('2020-10-10'), new DateTime('2020-12-12')],
            $mixed->getTags(),
        );

        // The setter must exercise the same validator chain as construction: a raw date string
        // is transformed and persisted, and an already-transformed value is accepted directly.
        $accepted->setTags(['2020-01-01']);
        $this->assertEquals([new DateTime('2020-01-01')], $accepted->getTags());

        $accepted->setTags([new DateTime('2020-02-02')]);
        $this->assertEquals([new DateTime('2020-02-02')], $accepted->getTags());

        // An invalid date string still fails the filter's own validation.
        try {
            new $className(['tags' => ['not-a-date']]);
            $this->fail('Expected an exception for an invalid date string');
        } catch (ErrorRegistryException $exception) {
            $this->assertSame(
                <<<'MSG'
Invalid unevaluated items in array 'tags':
  - invalid unevaluated item #0
    * Invalid value for property 'unevaluated item' denied by filter 'dateTime': Invalid Date Time value "not-a-date"
MSG,
                $exception->getMessage(),
            );
        }
    }

    /**
     * `_evaluatedItemIndices['tags']` records which indices the unevaluatedItems validator
     * credited so composition/serialization can read it back — but it must never leak from one
     * validation pass into the next. Without a reset before the validator chain runs, a
     * `setTags()` call would see stale credits from a previous pass and skip validating (and,
     * with a transforming filter, skip re-filtering) indices whose value has since completely
     * changed. Uses a plain type check with no filter to prove this is a general unevaluatedItems
     * mutability issue, not something specific to the transforming-filter interaction.
     */
    public function testSetterRevalidatesEveryIndexRegardlessOfPriorPassCredits(): void
    {
        $className = $this->generateClassFromFile(
            'NoOtherConstraintsSchema.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['tags' => ['alpha', 'beta']]);
        $this->assertSame(['alpha', 'beta'], $object->getTags());

        // A value that would have been valid at construction time must still be rejected when
        // set later — proving the same index isn't silently exempted from validation because a
        // previous, unrelated array happened to validate successfully at that position.
        try {
            $object->setTags([42]);
            $this->fail('Expected an exception for an integer where unevaluatedItems requires a string');
        } catch (ErrorRegistryException $exception) {
            $this->assertSame(
                <<<'MSG'
    Invalid unevaluated items in array 'tags':
      - invalid unevaluated item #0
        * Invalid type for 'unevaluated item': requires 'string', got 'integer'
    MSG,
                $exception->getMessage(),
            );
        }

        // The rejected setter call must leave the object's state unchanged.
        $this->assertSame(['alpha', 'beta'], $object->getTags());

        // A genuinely valid replacement is still accepted.
        $object->setTags(['gamma']);
        $this->assertSame(['gamma'], $object->getTags());
    }

    /**
     * The credits of an unevaluatedItems validator which is declared inside a branch of a composition must be
     * reset like the credits of an unevaluatedItems validator of the property itself: the first pass credits
     * index 1 to the unevaluatedItems of the branch. Without a reset before the validator chain runs the stale
     * credit makes a later `setTags()` call skip the validation of index 1, a value which is rejected at
     * construction time is accepted by the setter.
     */
    public function testSetterRevalidatesUnevaluatedItemsOfABranchRegardlessOfPriorPassCredits(): void
    {
        $className = $this->generateClassFromFile(
            'UnevaluatedItemsInAllOfBranch.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['tags' => ['a', 1]]);
        $this->assertSame(['a', 1], $object->getTags());

        try {
            $object->setTags(['a', 'not an integer']);
            $this->fail('Expected an exception for a string where unevaluatedItems requires an integer');
        } catch (ErrorRegistryException $exception) {
            $this->assertSame(
                <<<'MSG'
    Invalid value for 'tags' declined by composition constraint
      Requires to match all composition elements but matched 0 elements
      - Composition element #1: Failed
        * Invalid unevaluated items in array 'tags':
          - invalid unevaluated item #1
            * Invalid type for 'unevaluated item': requires 'int', got 'string'
    MSG,
                $exception->getMessage(),
            );
        }

        // The rejected setter call must leave the object's state unchanged.
        $this->assertSame(['a', 1], $object->getTags());

        $object->setTags(['b', 2]);
        $this->assertSame(['b', 2], $object->getTags());
    }

    /**
     * Which indices unevaluatedItems credits is only known at runtime (tracked in
     * `_evaluatedItemIndices`) — unlike a tuple index, it can't be resolved to a static list at
     * generation time. Index 0 is claimed by an `allOf` branch's tuple-form `items` (no filter,
     * passes through unchanged); index 1 is left over for `unevaluatedItems` (filtered). Proves
     * the generated serializer only transforms the indices actually credited to
     * unevaluatedItems, not every index in the array.
     *
     * Uses composition-based crediting (`allOf` branch), not a direct sibling `items` tuple,
     * because direct-sibling tuple crediting is a separately tracked, currently-broken
     * interaction (see testSiblingTupleItemsCreditTheirEvaluatedIndices) unrelated to this test.
     */
    public function testSerializationOfUnevaluatedItemsWithTransformingFilterOnlyAffectsCreditedIndices(): void
    {
        $className = $this->generateClassFromFile(
            'TupleItemsPlusUnevaluatedItemsWithTransformingFilter.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['tags' => ['plain', '2020-10-10']]);

        $this->assertSame(['tags' => ['plain', '20201010']], $object->toArray());

        $decoded = json_decode($object->toJSON(), true);
        $this->assertSame(['tags' => ['plain', '20201010']], $decoded);
    }

    /**
     * A tuple index and unevaluatedItems on the same array property, each with their own
     * transforming filter, must not clobber each other: `SerializationPostProcessor` generates
     * one `_serialize{Property}()` method per property, and independently generating one from
     * the tuple validator and another from the unevaluatedItems validator would silently
     * overwrite whichever ran last (`Schema::addMethod()` is a plain array write, not a merge).
     * Both filters must be reflected in the serialized output: index 0 (tuple, `Ymd`) and
     * index 1 (unevaluatedItems, `Y-m-d`) end up in visibly different formats, proving neither
     * one was silently dropped.
     *
     * Uses direct-sibling `items`/`unevaluatedItems` (not composition) because that is the only
     * shape that reaches `SerializationPostProcessor`'s array-item recursion at all — composition
     * branches are a separate, not-yet-covered case (the branch's own tuple validator lives on
     * the branch's nested property, invisible to this post processor). As a side effect this
     * also exercises the direct-sibling tuple-crediting gap tracked elsewhere
     * (`testSiblingTupleItemsCreditTheirEvaluatedIndices`): `_evaluatedItemIndices` ends up
     * including the tuple-covered index too, since that credit-tracking bug is unrelated to and
     * not fixed by this test. This test still passes despite it, because the combined serializer
     * defensively skips indices already handled by the static tuple branch before running the
     * dynamic unevaluatedItems branch — proving that defense actually works, not just that the
     * two "happen" not to collide.
     */
    public function testSerializationCombinesTupleAndUnevaluatedItemsFiltersWithoutClobbering(): void
    {
        $className = $this->generateClassFromFile(
            'SiblingTupleAndUnevaluatedItemsBothWithTransformingFilter.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['tags' => ['2020-10-10', '2020-12-12']]);

        $this->assertSame(['tags' => ['20201010', '2020-12-12']], $object->toArray());

        $decoded = json_decode($object->toJSON(), true);
        $this->assertSame(['tags' => ['20201010', '2020-12-12']], $decoded);
    }

    /**
     * Array-side counterpart of `UnevaluatedPropertiesValidatorTest::
     * testBranchOnlyUnevaluatedPropertiesThrowsUnsupportedSchemaFeatureException()` - same root
     * cause, confirmed to affect this side too. A branch's own `unevaluatedItems` cannot see
     * indices a *sibling* applicator on the same array property already claims: here, the
     * sibling tuple `items: [{type: string}]` claims index 0, but the `allOf` branch's own
     * `unevaluatedItems: false` has no way to see that claim - it only knows what its own
     * (empty) local applicators evaluated, so `['a']` at index 0 used to be wrongly rejected as
     * unevaluated even though the sibling tuple already validated and claimed it.
     *
     * Rather than silently computing a wrong "unevaluated" set, the generator now rejects the
     * schema itself at generation time with an `UnsupportedSchemaFeatureException`. A real fix
     * was deferred instead of implemented: the branch would need the enclosing property to
     * compute and pass its siblings' claims into the branch instance at construction time, and
     * those claims can be instance-dependent, unlike the object-side's enclosing-declared-*names*
     * case, which is static.
     */
    public function testBranchUnevaluatedItemsThrowsUnsupportedSchemaFeatureException(): void
    {
        $this->expectException(UnsupportedSchemaFeatureException::class);
        $this->expectExceptionMessage(
            "Branch #1 of the composition for 'tags' declares 'unevaluatedItems', which cannot "
                . 'yet see property names or indices declared by the enclosing schema or a '
                . "sibling branch - remove 'unevaluatedItems' from the branch, or restructure the "
                . 'schema so it is declared only at the level that needs it at line 12, column 9',
        );

        $this->generateClassFromFile('BranchUnevaluatedItemsIgnoresSiblingTupleClaim.json', null, true);
    }

    /**
     * @param callable(): mixed $action
     * @param class-string<Exception> $exceptionClass
     */
    private function assertThrowsWithMessage(callable $action, string $exceptionClass, string $expectedMessage): void
    {
        $thrown = null;

        try {
            $action();
        } catch (Exception $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf($exceptionClass, $thrown, "Expected $exceptionClass to be thrown");
        $this->assertSame($expectedMessage, $thrown->getMessage());
    }

    /**
     * `unevaluatedItems` declared on an array which is an item of another array is evaluated per inner array: the
     * indices a tuple or the keyword itself evaluated in one inner array never count as evaluated in another one.
     * The inner arrays of the outer array share the property name, so the evaluation state of an inner array must
     * be isolated from the state of its siblings (and of the outer array) by the validation of the element.
     *
     * Covers the construction and the setter of a mutable model with direct exceptions, including the state of a
     * rejected setter call.
     */
    public function testUnevaluatedItemsOfANestedArrayAreEvaluatedPerInnerArray(): void
    {
        $className = $this->generateClassFromFile(
            'NestedArrayTupleUnevaluatedSchema.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        // Index 0 is evaluated by the tuple, index 1 by unevaluatedItems.
        $object = new $className(['tags' => [[1, 'a']]]);
        $this->assertSame([[1, 'a']], $object->getTags());
        $this->assertSame(['tags' => [[1, 'a']]], $object->meta()->rawInput());

        // Inner arrays of different length, also empty ones.
        $this->assertSame([[1, 'a', 'b'], [2], []], (new $className(['tags' => [[1, 'a', 'b'], [2], []]]))->getTags());

        // The indices 1 and 2 which unevaluatedItems evaluated in the first inner array are not evaluated in the
        // second inner array: the integer at index 1 has to match the unevaluatedItems schema.
        $this->assertThrowsWithMessage(
            static fn() => new $className(['tags' => [[1, 'a', 'b'], [2, 3]]]),
            InvalidItemException::class,
            <<<'MSG'
            Invalid items in array 'tags':
              - invalid item #1
                * Invalid unevaluated items in array 'tags':
                  - invalid unevaluated item #1
                    * Invalid type for 'unevaluated item': requires 'string', got 'integer'
            MSG,
        );

        // A violation of the tuple in the second inner array is reported for the second inner array only.
        $this->assertThrowsWithMessage(
            static fn() => new $className(['tags' => [[1, 'a'], ['x']]]),
            InvalidItemException::class,
            <<<'MSG'
            Invalid items in array 'tags':
              - invalid item #1
                * Invalid tuple item in array 'tags':
                  - invalid tuple #1
                    * Invalid type for 'tuple item #0 of array tags': requires 'int', got 'string'
            MSG,
        );

        // The failing elements are reported by index, valid inner arrays in between are not.
        $this->assertThrowsWithMessage(
            static fn() => new $className(['tags' => [[1, true], [2, 'a'], [3, 4.5]]]),
            InvalidItemException::class,
            <<<'MSG'
            Invalid items in array 'tags':
              - invalid item #0
                * Invalid unevaluated items in array 'tags':
                  - invalid unevaluated item #1
                    * Invalid type for 'unevaluated item': requires 'string', got 'boolean'
              - invalid item #2
                * Invalid unevaluated items in array 'tags':
                  - invalid unevaluated item #1
                    * Invalid type for 'unevaluated item': requires 'string', got 'double'
            MSG,
        );

        // The setter validates the new value from scratch, the state of the previous values doesn't leak.
        $object->setTags([[5, 'x', 'y'], [6]]);
        $this->assertSame([[5, 'x', 'y'], [6]], $object->getTags());

        $this->assertThrowsWithMessage(
            static fn() => $object->setTags([[7, 'x', 'y'], [8, 9]]),
            InvalidItemException::class,
            <<<'MSG'
            Invalid items in array 'tags':
              - invalid item #1
                * Invalid unevaluated items in array 'tags':
                  - invalid unevaluated item #1
                    * Invalid type for 'unevaluated item': requires 'string', got 'integer'
            MSG,
        );

        // The rejected setter call leaves the model unchanged, a following valid call is accepted.
        $this->assertSame([[5, 'x', 'y'], [6]], $object->getTags());
        $object->setTags([[9, 'z'], [10]]);
        $this->assertSame([[9, 'z'], [10]], $object->getTags());
    }

    /**
     * Immutable model with collected errors: all violations of all inner arrays are collected, the errors of one
     * inner array (a violated tuple and a violated unevaluatedItems schema) are reported for that inner array.
     */
    public function testUnevaluatedItemsOfANestedArrayCollectErrorsPerInnerArray(): void
    {
        $className = $this->generateClassFromFile(
            'NestedArrayTupleUnevaluatedSchema.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        $object = new $className(['tags' => [[1, 'a', 'b'], [2, 'c']]]);
        $this->assertSame([[1, 'a', 'b'], [2, 'c']], $object->getTags());

        // The evaluated indices of the first inner array don't count for the second inner array.
        $this->assertThrowsWithMessage(
            static fn() => new $className(['tags' => [[1, 'a', 'b'], [2, 3]]]),
            ErrorRegistryException::class,
            <<<'MSG'
            Invalid items in array 'tags':
              - invalid item #1
                * Invalid unevaluated items in array 'tags':
                  - invalid unevaluated item #1
                    * Invalid type for 'unevaluated item': requires 'string', got 'integer'
            MSG,
        );

        // Both keywords of one inner array fail, the inner array before and after it is valid.
        $this->assertThrowsWithMessage(
            static fn() => new $className(['tags' => [[1, 'a'], ['x', 5], [3, 'b']]]),
            ErrorRegistryException::class,
            <<<'MSG'
            Invalid items in array 'tags':
              - invalid item #1
                * Invalid tuple item in array 'tags':
                  - invalid tuple #1
                    * Invalid type for 'tuple item #0 of array tags': requires 'int', got 'string'
                * Invalid unevaluated items in array 'tags':
                  - invalid unevaluated item #1
                    * Invalid type for 'unevaluated item': requires 'string', got 'integer'
            MSG,
        );
    }

    /**
     * The serialization of a model with nested arrays whose unevaluatedItems declare no filter hands the items
     * through unchanged.
     */
    public function testSerializationOfNestedArraysWithUnevaluatedItems(): void
    {
        $className = $this->generateClassFromFile(
            'NestedArrayTupleUnevaluatedSchema.json',
            (new GeneratorConfiguration())->setSerialization(true),
        );

        $object = new $className(['tags' => [[1, 'a', 'b'], [2], []]]);

        $this->assertSame(['tags' => [[1, 'a', 'b'], [2], []]], $object->toArray());
        $this->assertSame(['tags' => [[1, 'a', 'b'], [2], []]], json_decode($object->toJSON(), true));
    }

    /**
     * `unevaluatedItems: false` of a nested array rejects exactly the indices which the tuple of that inner array
     * doesn't evaluate, per inner array. The exception of the inner array is reachable from the exception of the
     * outer array.
     */
    public function testUnevaluatedItemsFalseOfANestedArrayRejectsTheIndicesOfTheInnerArray(): void
    {
        $className = $this->generateClassFromFile(
            'NestedArrayTupleUnevaluatedFalse.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        $object = new $className(['tags' => [[1], [2], []]]);
        $this->assertSame([[1], [2], []], $object->getTags());

        try {
            new $className(['tags' => [[1], [2, 'a', 'b'], [3, 4]]]);
            $this->fail('Expected InvalidItemException');
        } catch (InvalidItemException $exception) {
            $this->assertSame(
                <<<'MSG'
                Invalid items in array 'tags':
                  - invalid item #1
                    * Provided JSON for 'tags' contains not allowed unevaluated items [#1, #2]
                  - invalid item #2
                    * Provided JSON for 'tags' contains not allowed unevaluated items [#1]
                MSG,
                $exception->getMessage(),
            );

            $invalidItems = $exception->getInvalidItems();
            $this->assertSame([1, 2], array_keys($invalidItems));
            $this->assertInstanceOf(UnevaluatedItemsException::class, $invalidItems[1][0]);
            $this->assertSame([1, 2], $invalidItems[1][0]->getUnevaluatedItems());
            $this->assertSame([1], $invalidItems[2][0]->getUnevaluatedItems());
            $this->assertSame(
                '/properties/tags/items/unevaluatedItems',
                $invalidItems[1][0]->getJsonPointer()->pointer,
            );
        }

        // The setter validates every inner array of the new value.
        $this->assertThrowsWithMessage(
            static fn() => $object->setTags([[1, 2]]),
            InvalidItemException::class,
            <<<'MSG'
            Invalid items in array 'tags':
              - invalid item #0
                * Provided JSON for 'tags' contains not allowed unevaluated items [#1]
            MSG,
        );
        $this->assertSame([[1], [2], []], $object->getTags());
    }

    /**
     * Arrays nested three levels deep with the keyword on the two inner levels (different types so a state shared
     * between the levels or between the elements of a level would be visible).
     */
    public function testUnevaluatedItemsOnEveryLevelOfThreeNestedArrays(): void
    {
        $className = $this->generateClassFromFile(
            'NestedArrayThreeLevels.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        $input = [[['a', true, false], 5, 6], [['b'], 7], [['c', false]]];
        $object = new $className(['tags' => $input]);
        $this->assertSame($input, $object->getTags());

        // The booleans evaluated by unevaluatedItems of the first innermost array don't count for the second one.
        $this->assertThrowsWithMessage(
            static fn() => new $className(['tags' => [[['a', true, false], 5], [['b', 'x']]]]),
            InvalidItemException::class,
            <<<'MSG'
            Invalid items in array 'tags':
              - invalid item #1
                * Invalid tuple item in array 'tags':
                  - invalid tuple #1
                    * Invalid unevaluated items in array 'tuple item #0 of array tags':
                      - invalid unevaluated item #1
                        * Invalid type for 'unevaluated item': requires 'bool', got 'string'
            MSG,
        );

        // The integers evaluated by unevaluatedItems of the middle level are independent of the innermost level.
        $this->assertThrowsWithMessage(
            static fn() => new $className(['tags' => [[['a'], 1, 2], [['b'], 'three']]]),
            InvalidItemException::class,
            <<<'MSG'
            Invalid items in array 'tags':
              - invalid item #1
                * Invalid unevaluated items in array 'tags':
                  - invalid unevaluated item #1
                    * Invalid type for 'unevaluated item': requires 'int', got 'string'
            MSG,
        );

        // The setter runs the same validation.
        $object->setTags([[['d', false]], [['e', true, true], 8]]);
        $this->assertSame([[['d', false]], [['e', true, true], 8]], $object->getTags());
    }

    /**
     * Nested arrays in the other slots of an array: an item at a tuple index is not named like the outer array, the
     * additionalItems, the schema of unevaluatedItems and the schema of contains share the evaluation of the inner
     * arrays the same way.
     */
    public function testUnevaluatedItemsOfArraysInTupleAdditionalUnevaluatedAndContainsSlots(): void
    {
        $className = $this->generateClassFromFile(
            'NestedArrayInTupleAndSchemaSlots.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        $valid = ['tupleSlot' => [[1]], 'unevaluatedSlot' => [], 'containsSlot' => [['a']]];

        $object = new $className([
            'tupleSlot' => [[1, 'a', 'b'], ['x'], ['y']],
            'unevaluatedSlot' => [5, ['a', true, false], ['b', true]],
            'containsSlot' => [1, ['a', 'b'], ['c']],
        ]);
        $this->assertSame([[1, 'a', 'b'], ['x'], ['y']], $object->getTupleSlot());
        $this->assertSame([5, ['a', true, false], ['b', true]], $object->getUnevaluatedSlot());
        $this->assertSame([1, ['a', 'b'], ['c']], $object->getContainsSlot());

        // The tuple slot: the inner array at the tuple index and the inner arrays of the additional items.
        $this->assertThrowsWithMessage(
            static fn() => new $className(['tupleSlot' => [[1, 'a', 2], ['x']]] + $valid),
            InvalidTupleException::class,
            <<<'MSG'
            Invalid tuple item in array 'tupleSlot':
              - invalid tuple #1
                * Invalid unevaluated items in array 'tuple item #0 of array tupleSlot':
                  - invalid unevaluated item #2
                    * Invalid type for 'unevaluated item': requires 'string', got 'integer'
            MSG,
        );
        $this->assertThrowsWithMessage(
            static fn() => new $className(['tupleSlot' => [[1], ['x'], ['y', 'z']]] + $valid),
            InvalidAdditionalTupleItemsException::class,
            <<<'MSG'
            Tuple array 'tupleSlot' contains invalid additional items
              - invalid additional item '2'
                * Provided JSON for 'additional item' contains not allowed unevaluated items [#1]
            MSG,
        );

        // The schema of unevaluatedItems of the outer array is an array with unevaluatedItems itself.
        $this->assertThrowsWithMessage(
            static fn() => new $className(['unevaluatedSlot' => [5, ['a', true], ['b', 'x']]] + $valid),
            InvalidUnevaluatedItemsException::class,
            <<<'MSG'
            Invalid unevaluated items in array 'unevaluatedSlot':
              - invalid unevaluated item #2
                * Invalid unevaluated items in array 'unevaluated item':
                  - invalid unevaluated item #1
                    * Invalid type for 'unevaluated item': requires 'bool', got 'string'
            MSG,
        );

        // The schema of contains: the inner arrays which are not valid don't match. The only inner array has an index
        // which no applicator evaluated, so no item matches.
        $this->assertThrowsWithMessage(
            static fn() => new $className(['containsSlot' => [['a', 1]]] + $valid),
            ContainsException::class,
            "No item in array 'containsSlot' matches the 'contains' constraint",
        );
    }

    /**
     * Arrays held by the values of additionalProperties and patternProperties are validated per array and per key:
     * the schema of the object doesn't have to declare the keyword itself.
     */
    public function testUnevaluatedItemsOfArraysHeldByAdditionalAndPatternProperties(): void
    {
        $className = $this->generateClassFromFile(
            'NestedArrayInObjectValues.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        $input = ['id' => 1, 'first' => [1, 'x', 'y'], 'second' => [2], 'list_one' => ['s'], 'list_two' => ['t']];
        $object = new $className($input);
        $this->assertSame($input, $object->meta()->rawInput());

        // The index evaluated by unevaluatedItems in the value of the first additional property doesn't count for
        // the value of the second additional property.
        $this->assertThrowsWithMessage(
            static fn() => new $className(['first' => [1, 'x', 'y'], 'second' => [2, 3]]),
            InvalidAdditionalPropertiesException::class,
            <<<MSG
            Provided JSON for '{$className}' contains invalid additional properties
              - invalid additional property 'second'
                * Invalid unevaluated items in array 'additional property':
                  - invalid unevaluated item #1
                    * Invalid type for 'unevaluated item': requires 'string', got 'integer'
            MSG,
        );

        $this->assertThrowsWithMessage(
            static fn() => new $className(['list_one' => ['s'], 'list_two' => ['t', 'u']]),
            InvalidPatternPropertiesException::class,
            <<<MSG
            Provided JSON for '{$className}' contains invalid pattern properties
              - invalid property 'list_two' matching pattern '^list_'
                * Provided JSON for 'pattern property' contains not allowed unevaluated items [#1]
            MSG,
        );
    }

    /**
     * The arrays held by the values of unevaluatedProperties are elements of the object like the values of additional
     * and pattern properties: validated per key, each with its own evaluated indices.
     */
    public function testUnevaluatedItemsOfArraysHeldByUnevaluatedProperties(): void
    {
        $className = $this->generateClassFromFile(
            'NestedArrayInUnevaluatedPropertyValues.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        $input = ['id' => 1, 'first' => [1, 'x', 'y'], 'second' => [2]];
        $object = new $className($input);
        $this->assertSame($input, $object->meta()->rawInput());

        $this->assertThrowsWithMessage(
            static fn() => new $className(['first' => [1, 'x', 'y'], 'second' => [2, 3]]),
            InvalidUnevaluatedPropertiesException::class,
            <<<MSG
            Provided JSON for '{$className}' contains invalid unevaluated properties
              - invalid unevaluated property 'second'
                * Invalid unevaluated items in array 'unevaluated property':
                  - invalid unevaluated item #1
                    * Invalid type for 'unevaluated item': requires 'string', got 'integer'
            MSG,
        );
    }

    /**
     * An outer array and the inner array at its tuple index both carry compositions which track the evaluated
     * indices: the compositions of the two arrays are tracked independently (the contains of the inner allOf credits
     * indices of the inner array, the contains of the outer allOf indices of the outer array).
     */
    public function testCompositionsOfOuterAndInnerArrayCreditTheirOwnIndices(): void
    {
        $className = $this->generateClassFromFile(
            'NestedArrayWithOuterAndInnerCompositions.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        // Outer: index 0 by the tuple, index 1 and 2 by contains (5 matches). Inner: 1 by the tuple, 'x' by contains.
        $object = new $className(['tags' => [[1, 'x'], 5, 5]]);
        $this->assertSame([[1, 'x'], 5, 5], $object->getTags());

        // The inner array at index 0 is valid, index 2 of the outer array is not evaluated by anything.
        $this->assertThrowsWithMessage(
            static fn() => new $className(['tags' => [[1, 'x'], 5, 'surplus']]),
            UnevaluatedItemsException::class,
            "Provided JSON for 'tags' contains not allowed unevaluated items [#2]",
        );

        // The 5 credited to the outer array at index 1 is not evaluated for the inner array, which is the tuple item
        // at index 0: index 1 of the inner array is credited by its own contains only.
        $this->assertThrowsWithMessage(
            static fn() => new $className(['tags' => [[1, 'x', 'surplus'], 5]]),
            InvalidTupleException::class,
            <<<'MSG'
            Invalid tuple item in array 'tags':
              - invalid tuple #1
                * Provided JSON for 'tuple item #0 of array tags' contains not allowed unevaluated items [#2]
            MSG,
        );
    }

    /**
     * The composition branches of a nested array credit the indices they evaluate to the unevaluatedItems of the
     * inner array they belong to (a tuple in one branch, contains in the sibling branch).
     */
    public function testCompositionBranchesOfANestedArrayCreditTheIndicesOfTheirInnerArray(): void
    {
        $className = $this->generateClassFromFile(
            'NestedArrayWithCompositionBranches.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        $object = new $className(['tags' => [[1, 'x', 'x'], [2, 'x']]]);
        $this->assertSame([[1, 'x', 'x'], [2, 'x']], $object->getTags());

        // The indices contains matched in the first inner array are not credited to the second inner array.
        $this->assertThrowsWithMessage(
            static fn() => new $className(['tags' => [[1, 'x', 'x'], [2, 'x', 'y']]]),
            InvalidItemException::class,
            <<<'MSG'
            Invalid items in array 'tags':
              - invalid item #1
                * Provided JSON for 'tags' contains not allowed unevaluated items [#2]
            MSG,
        );

        // The first branch (tuple) of the inner array fails: the composition fails before unevaluatedItems runs.
        $this->assertThrowsWithMessage(
            static fn() => $object->setTags([['wrong', 'x']]),
            InvalidItemException::class,
            <<<'MSG'
            Invalid items in array 'tags':
              - invalid item #0
                * Invalid value for 'tags' declined by composition constraint
                  Requires to match all composition elements but matched 1 element
                  - Composition element #1: Failed
                    * Invalid tuple item in array 'tags':
                      - invalid tuple #1
                        * Invalid type for 'tuple item #0 of array tags': requires 'int', got 'string'
                  - Composition element #2: Valid
            MSG,
        );
        $this->assertSame([[1, 'x', 'x'], [2, 'x']], $object->getTags());
    }

    /**
     * A sibling contains of a nested array credits the matched indices to the unevaluatedItems of the inner array
     * only: the matches of one inner array don't count for the next one.
     */
    public function testSiblingContainsOfANestedArrayCreditsOnlyTheMatchesOfTheInnerArray(): void
    {
        $className = $this->generateClassFromFile(
            'NestedArrayWithSiblingContains.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        $object = new $className(['tags' => [['a'], ['b', 'c']]]);
        $this->assertSame([['a'], ['b', 'c']], $object->getTags());

        // The match at index 0 of the first inner array doesn't evaluate index 0 of the second inner array.
        $this->assertThrowsWithMessage(
            static fn() => new $className(['tags' => [['a'], [1, 'b']]]),
            InvalidItemException::class,
            <<<'MSG'
            Invalid items in array 'tags':
              - invalid item #1
                * Provided JSON for 'tags' contains not allowed unevaluated items [#0]
            MSG,
        );

        $this->assertThrowsWithMessage(
            static fn() => $object->setTags([['a', 1]]),
            InvalidItemException::class,
            <<<'MSG'
            Invalid items in array 'tags':
              - invalid item #0
                * Provided JSON for 'tags' contains not allowed unevaluated items [#1]
            MSG,
        );
    }

    /**
     * The elements of an array which are objects are validated by a class of their own: the keyword inside that
     * class is evaluated by the class, the enclosing class has no use for the evaluation tracking.
     */
    public function testUnevaluatedItemsInTheClassOfAnObjectElementDoNotActivateTheEnclosingClass(): void
    {
        $className = $this->generateClassFromFile(
            'NestedArrayInObjectElements.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        $object = new $className(['entries' => [['list' => [1, 'a']], ['list' => [2]]]]);
        $elementClassName = $object->getEntries()[0]::class;

        $this->assertNotContains(CompositionEvaluationTrait::class, class_uses($className));
        $this->assertFalse((new ReflectionClass($className))->hasProperty('_evaluatedItemIndices'));
        $this->assertContains(CompositionEvaluationTrait::class, class_uses($elementClassName));
        $this->assertTrue((new ReflectionClass($elementClassName))->hasProperty('_evaluatedItemIndices'));

        $this->assertThrowsWithMessage(
            static fn() => new $className(['entries' => [['list' => [1, 'a']], ['list' => [2, 3]]]]),
            InvalidItemException::class,
            <<<'MSG'
            Invalid items in array 'entries':
              - invalid item #1
                * Invalid unevaluated items in array 'list':
                  - invalid unevaluated item #1
                    * Invalid type for 'unevaluated item': requires 'string', got 'integer'
            MSG,
        );
    }

    /**
     * The evaluation of an inner array is isolated from the evaluated indices of the outer array in both directions.
     * The schema-form items of the branch validate the elements with the name of the outer array, so:
     *
     *   - the index which contains credited to the outer array must not count as evaluated in the inner array
     *     (the inner array at index 0 has an unevaluated item at its index 0 and fails the branch), and
     *   - the credit of contains must survive the validation of the inner arrays: index 0 is still evaluated for the
     *     unevaluatedItems check of the outer array afterwards, only index 1 (which nothing evaluated) is reported.
     */
    public function testEvaluationOfNestedArraysIsIsolatedFromTheEvaluatedIndicesOfTheOuterArray(): void
    {
        $className = $this->generateClassFromFile('NestedArrayBesideSiblingCredits.json');

        // Index 0 is evaluated by contains. The branch fails (the inner array has an unevaluated item), the anyOf
        // passes by its second branch.
        $object = new $className(['tags' => [[1]]]);
        $this->assertSame([[1]], $object->getTags());

        // Would be accepted if the credit of contains at index 0 counted for the inner array at index 0: the branch
        // would pass and claim every index. Reports only index 1 if the credit of contains survived.
        $this->assertThrowsWithMessage(
            static fn() => new $className(['tags' => [[1], []]]),
            UnevaluatedItemsException::class,
            "Provided JSON for 'tags' contains not allowed unevaluated items [#1]",
        );
    }

    /**
     * A branch which declares the keyword without being able to see the claims of its enclosing schema is rejected
     * for a nested array the same way as for an array property.
     */
    public function testBranchUnevaluatedItemsOfANestedArrayThrowsUnsupportedSchemaFeatureException(): void
    {
        $this->expectException(UnsupportedSchemaFeatureException::class);
        $this->expectExceptionMessage(
            "Branch #1 of the composition for 'tags' declares 'unevaluatedItems', which cannot "
                . 'yet see property names or indices declared by the enclosing schema or a '
                . "sibling branch - remove 'unevaluatedItems' from the branch, or restructure the "
                . 'schema so it is declared only at the level that needs it at line 14, column 11',
        );

        $this->generateClassFromFile('NestedArrayBranchUnevaluatedItemsWithSiblingClaim.json', null, true);
    }

    /**
     * The credits of a previous validation of an array property are not applied to the next one: contains credits
     * the matched indices, a stale credit of index 0 would skip the check of the string at index 0 of the next value
     * if unevaluatedItems: false doesn't reset the credits like the schema-form keyword does.
     */
    public function testSetterDiscardsTheCreditsOfSiblingContainsForUnevaluatedItemsFalse(): void
    {
        $className = $this->generateClassFromFile(
            'SiblingContains.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        $object = new $className(['tags' => [5]]);
        $this->assertSame([5], $object->getTags());

        $this->assertThrowsWithMessage(
            static fn() => $object->setTags(['surplus', 6]),
            UnevaluatedItemsException::class,
            "Provided JSON for 'tags' contains not allowed unevaluated items [#0]",
        );
        $this->assertSame([5], $object->getTags());

        $object->setTags([6, 7]);
        $this->assertSame([6, 7], $object->getTags());
    }

    /**
     * With collected errors every violation is reported by an ErrorRegistryException (the message of the collected
     * violations may differ from the message of the first violation), otherwise the exception of the first violation
     * is thrown.
     *
     * @param callable(): mixed $action
     * @param class-string<Exception> $exceptionClass
     */
    private function assertRejectedWithMessage(
        GeneratorConfiguration $configuration,
        callable $action,
        string $exceptionClass,
        string $expectedMessage,
        ?string $collectedMessage = null,
    ): void {
        $this->assertThrowsWithMessage(
            $action,
            $configuration->collectErrors() ? ErrorRegistryException::class : $exceptionClass,
            $configuration->collectErrors() ? $collectedMessage ?? $expectedMessage : $expectedMessage,
        );
    }

    /**
     * An outer array and the inner array at its tuple index both carry an anyOf. Every successful branch credits its
     * indices (a tuple in one branch, contains in the sibling branch), a failed branch credits nothing. The credits of
     * the outer array survive the validation of the inner array and the credits of the inner array don't count for the
     * outer one.
     *
     * With collected errors a failed composition credits nothing, so the indices of the inner array it would have
     * evaluated are reported as unevaluated in addition to the failure of the composition.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testAnyOfBranchesOfOuterAndInnerArrayCreditTheirOwnIndices(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile(
            'NestedArrayWithOuterAndInnerAnyOf.json',
            (clone $configuration)->setImmutable(false),
        );

        // Both inner branches succeed (tuple: index 0, contains: index 1 and 2), both outer branches succeed.
        $object = new $className(['tags' => [[1, 'x', 'x'], 5, 6]]);
        $this->assertSame([[1, 'x', 'x'], 5, 6], $object->getTags());

        // Only the tuple branch of the inner array and only the first outer branch succeed.
        $object->setTags([[1], 5]);
        $this->assertSame([[1], 5], $object->getTags());

        // The inner array has an index no branch evaluated, the 5 credited to the outer array doesn't count for it.
        $this->assertRejectedWithMessage(
            $configuration,
            static fn() => new $className(['tags' => [[1, 'x', 'surplus'], 5]]),
            InvalidTupleException::class,
            <<<'MSG'
            Invalid tuple item in array 'tags':
              - invalid tuple #1
                * Provided JSON for 'tuple item #0 of array tags' contains not allowed unevaluated items [#2]
            MSG,
        );

        // The tuple branch of the inner array fails, only contains evaluates index 1.
        $this->assertRejectedWithMessage(
            $configuration,
            static fn() => new $className(['tags' => [['a', 'x'], 5]]),
            InvalidTupleException::class,
            <<<'MSG'
            Invalid tuple item in array 'tags':
              - invalid tuple #1
                * Provided JSON for 'tuple item #0 of array tags' contains not allowed unevaluated items [#0]
            MSG,
        );

        // The tuple branch evaluates index 0, the contains branch fails.
        $this->assertRejectedWithMessage(
            $configuration,
            static fn() => $object->setTags([[2, 3], 6]),
            InvalidTupleException::class,
            <<<'MSG'
            Invalid tuple item in array 'tags':
              - invalid tuple #1
                * Provided JSON for 'tuple item #0 of array tags' contains not allowed unevaluated items [#1]
            MSG,
        );

        // The credit of the outer array for index 1 (5) survives the validation of the inner array, index 2 (7) is
        // not evaluated by any outer branch.
        $this->assertRejectedWithMessage(
            $configuration,
            static fn() => $object->setTags([[1], 5, 7]),
            UnevaluatedItemsException::class,
            "Provided JSON for 'tags' contains not allowed unevaluated items [#2]",
        );

        // No branch of the inner array succeeds.
        $this->assertRejectedWithMessage(
            $configuration,
            static fn() => $object->setTags([['a'], 5]),
            InvalidTupleException::class,
            <<<'MSG'
            Invalid tuple item in array 'tags':
              - invalid tuple #1
                * Invalid value for 'tuple item #0 of array tags' declined by composition constraint
                  Requires to match at least one composition element
                  - Composition element #1: Failed
                    * Invalid tuple item in array 'tuple item #0 of array tags':
                      - invalid tuple #1
                        * Invalid type for 'tuple item #0 of array tuple item #0 of array tags': requires 'int', got 'string'
                  - Composition element #2: Failed
                    * No item in array 'tuple item #0 of array tags' matches the 'contains' constraint
            MSG,
            <<<'MSG'
            Invalid tuple item in array 'tags':
              - invalid tuple #1
                * Invalid value for 'tuple item #0 of array tags' declined by composition constraint
                  Requires to match at least one composition element
                  - Composition element #1: Failed
                    * Invalid tuple item in array 'tuple item #0 of array tags':
                      - invalid tuple #1
                        * Invalid type for 'tuple item #0 of array tuple item #0 of array tags': requires 'int', got 'string'
                  - Composition element #2: Failed
                    * No item in array 'tuple item #0 of array tags' matches the 'contains' constraint
                * Provided JSON for 'tuple item #0 of array tags' contains not allowed unevaluated items [#0]
            MSG,
        );

        // The rejected values left the model untouched.
        $this->assertSame([[1], 5], $object->getTags());
    }

    /**
     * An outer array and the inner array at its tuple index both carry a oneOf. Only the single successful branch
     * credits its indices: the branches which match nothing and the branch which matches next to another one (which
     * fails the oneOf) credit nothing.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testOneOfBranchesOfOuterAndInnerArrayCreditOnlyTheIndicesOfTheSuccessfulBranch(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile(
            'NestedArrayWithOuterAndInnerOneOf.json',
            (clone $configuration)->setImmutable(false),
        );

        // Inner: the tuple branch (index 0) is the only one, outer: the first branch (contains 5) is the only one.
        $object = new $className(['tags' => [[1], 5]]);
        $this->assertSame([[1], 5], $object->getTags());

        // Inner: the contains branch evaluates every index, outer: the second branch (contains 6) is the only one.
        $object->setTags([['x', 'x', 'x'], 6, 6]);
        $this->assertSame([['x', 'x', 'x'], 6, 6], $object->getTags());

        $this->assertRejectedWithMessage(
            $configuration,
            static fn() => $object->setTags([[1, 2], 5]),
            InvalidTupleException::class,
            <<<'MSG'
            Invalid tuple item in array 'tags':
              - invalid tuple #1
                * Provided JSON for 'tuple item #0 of array tags' contains not allowed unevaluated items [#1]
            MSG,
        );

        // Only the contains branch matches and evaluates the indices 0 and 1, index 2 is not evaluated.
        $this->assertRejectedWithMessage(
            $configuration,
            static fn() => $object->setTags([['x', 'x', 'y'], 5]),
            InvalidTupleException::class,
            <<<'MSG'
            Invalid tuple item in array 'tags':
              - invalid tuple #1
                * Provided JSON for 'tuple item #0 of array tags' contains not allowed unevaluated items [#2]
            MSG,
        );

        // Only the contains branch matches, the tuple branch which evaluated index 0 failed (maxItems).
        $this->assertRejectedWithMessage(
            $configuration,
            static fn() => $object->setTags([[1, 'x', 'x'], 6, 6]),
            InvalidTupleException::class,
            <<<'MSG'
            Invalid tuple item in array 'tags':
              - invalid tuple #1
                * Provided JSON for 'tuple item #0 of array tags' contains not allowed unevaluated items [#0]
            MSG,
        );

        // The second outer branch matches (index 3 is evaluated), the first outer branch fails (maxItems): the 5 which
        // the first branch would have credited is not evaluated.
        $this->assertRejectedWithMessage(
            $configuration,
            static fn() => $object->setTags([[1], 5, 5, 6]),
            UnevaluatedItemsException::class,
            "Provided JSON for 'tags' contains not allowed unevaluated items [#1, #2]",
        );

        // No branch of the inner array matches.
        $this->assertRejectedWithMessage(
            $configuration,
            static fn() => $object->setTags([['x', 'x'], 5]),
            InvalidTupleException::class,
            <<<'MSG'
            Invalid tuple item in array 'tags':
              - invalid tuple #1
                * Invalid value for 'tuple item #0 of array tags' declined by composition constraint
                  Requires to match one composition element but matched 0 elements
                  - Composition element #1: Failed
                    * Invalid tuple item in array 'tuple item #0 of array tags':
                      - invalid tuple #1
                        * Invalid type for 'tuple item #0 of array tuple item #0 of array tags': requires 'int', got 'string'
                  - Composition element #2: Failed
                    * Array 'tuple item #0 of array tags' must not contain less than 3 items
            MSG,
            <<<'MSG'
            Invalid tuple item in array 'tags':
              - invalid tuple #1
                * Invalid value for 'tuple item #0 of array tags' declined by composition constraint
                  Requires to match one composition element but matched 0 elements
                  - Composition element #1: Failed
                    * Invalid tuple item in array 'tuple item #0 of array tags':
                      - invalid tuple #1
                        * Invalid type for 'tuple item #0 of array tuple item #0 of array tags': requires 'int', got 'string'
                  - Composition element #2: Failed
                    * Array 'tuple item #0 of array tags' must not contain less than 3 items
                * Provided JSON for 'tuple item #0 of array tags' contains not allowed unevaluated items [#0, #1]
            MSG,
        );

        // The rejected values left the model untouched.
        $this->assertSame([['x', 'x', 'x'], 6, 6], $object->getTags());
    }

    /**
     * An outer array and the inner array at its tuple index both carry an if/then/else. The condition credits its
     * indices when it succeeds, the then branch when the condition held and the else branch when it didn't. A
     * conditional which fails as a whole credits nothing, not even the indices of the condition.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testConditionalOfOuterAndInnerArrayCreditsTheIndicesOfTheActiveBranches(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile(
            'NestedArrayWithOuterAndInnerConditional.json',
            (clone $configuration)->setImmutable(false),
        );

        // Inner: the condition (tuple) credits index 0, then (contains) credits index 1 and 2.
        // Outer: the condition (contains 5) credits index 1, then (contains 6) credits index 2.
        $object = new $className(['tags' => [[1, 'x', 'x'], 5, 6]]);
        $this->assertSame([[1, 'x', 'x'], 5, 6], $object->getTags());

        // Inner: the condition fails, else (tuple) credits index 0. Outer: else (contains 7) credits index 1.
        $object->setTags([['a'], 7]);
        $this->assertSame([['a'], 7], $object->getTags());

        $this->assertRejectedWithMessage(
            $configuration,
            static fn() => $object->setTags([[1, 'x', 'y'], 5, 6]),
            InvalidTupleException::class,
            <<<'MSG'
            Invalid tuple item in array 'tags':
              - invalid tuple #1
                * Provided JSON for 'tuple item #0 of array tags' contains not allowed unevaluated items [#2]
            MSG,
        );

        // The condition fails, else credits index 0 only.
        $this->assertRejectedWithMessage(
            $configuration,
            static fn() => $object->setTags([['a', 'b'], 7]),
            InvalidTupleException::class,
            <<<'MSG'
            Invalid tuple item in array 'tags':
              - invalid tuple #1
                * Provided JSON for 'tuple item #0 of array tags' contains not allowed unevaluated items [#1]
            MSG,
        );

        // The outer else branch credits index 1 (7), index 2 (8) is not evaluated.
        $this->assertRejectedWithMessage(
            $configuration,
            static fn() => $object->setTags([[1, 'x'], 7, 8]),
            UnevaluatedItemsException::class,
            "Provided JSON for 'tags' contains not allowed unevaluated items [#2]",
        );

        // The outer condition and then branch credit the indices 1 and 2, index 3 is not evaluated.
        $this->assertRejectedWithMessage(
            $configuration,
            static fn() => $object->setTags([[1, 'x'], 5, 6, 9]),
            UnevaluatedItemsException::class,
            "Provided JSON for 'tags' contains not allowed unevaluated items [#3]",
        );

        // The condition of the inner array holds, the then branch fails.
        $this->assertRejectedWithMessage(
            $configuration,
            static fn() => $object->setTags([[1, 2], 7]),
            InvalidTupleException::class,
            <<<'MSG'
            Invalid tuple item in array 'tags':
              - invalid tuple #1
                * Invalid value for 'tuple item #0 of array tags' declined by conditional composition constraint
                  - Condition: Valid
                  - Conditional branch failed:
                    * No item in array 'tuple item #0 of array tags' matches the 'contains' constraint
            MSG,
            <<<'MSG'
            Invalid tuple item in array 'tags':
              - invalid tuple #1
                * Invalid value for 'tuple item #0 of array tags' declined by conditional composition constraint
                  - Condition: Valid
                  - Conditional branch failed:
                    * No item in array 'tuple item #0 of array tags' matches the 'contains' constraint
                * Provided JSON for 'tuple item #0 of array tags' contains not allowed unevaluated items [#0, #1]
            MSG,
        );

        // The outer condition holds, the outer then branch fails: the index credited by the condition (1) is reported.
        $this->assertRejectedWithMessage(
            $configuration,
            static fn() => $object->setTags([[1, 'x'], 5, 7]),
            ConditionalException::class,
            <<<'MSG'
            Invalid value for 'tags' declined by conditional composition constraint
              - Condition: Valid
              - Conditional branch failed:
                * No item in array 'tags' matches the 'contains' constraint
            MSG,
            <<<'MSG'
            Invalid value for 'tags' declined by conditional composition constraint
              - Condition: Valid
              - Conditional branch failed:
                * No item in array 'tags' matches the 'contains' constraint
            Provided JSON for 'tags' contains not allowed unevaluated items [#1, #2]
            MSG,
        );

        // The rejected values left the model untouched.
        $this->assertSame([['a'], 7], $object->getTags());
    }
}
