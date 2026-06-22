<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\Arrays\InvalidUnevaluatedItemsException;
use PHPModelGenerator\Exception\Arrays\UniqueItemsException;
use PHPModelGenerator\Exception\Arrays\UnevaluatedItemsException;
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
     * `uniqueItems: true` is orthogonal to `unevaluatedItems`. uniqueItems failure has its own
     * exception identity and message; the unevaluated check does not get to fire for that
     * array because the uniqueItems failure surfaces first.
     */
    public function testUniqueItemsExceptionIdentityIsPreserved(): void
    {
        $this->expectException(UniqueItemsException::class);

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
}
