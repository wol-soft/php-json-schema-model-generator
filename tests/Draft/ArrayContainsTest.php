<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Draft;

use Exception;
use PHPModelGenerator\Draft\Draft_07;
use PHPModelGenerator\Draft\Draft_2019_09;
use PHPModelGenerator\Exception\Arrays\ContainsException;
use PHPModelGenerator\Exception\Arrays\MaxContainsException;
use PHPModelGenerator\Exception\Arrays\MinContainsException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the minContains / maxContains array constraints introduced in Draft 2019-09.
 *
 * Draft 07 supports the plain "contains" keyword (at least one match required) but ignores
 * minContains / maxContains. Draft 2019-09 and 2020-12 honour those constraints and use
 * $containsMatches to track the number of matching items across all three validators.
 */
class ArrayContainsTest extends AbstractPHPModelGeneratorTestCase
{
    // --- minContains ---

    /**
     * One generated class per configuration covers: null (optional), valid arrays with ≥ 2
     * matching items, zero-match failure (ContainsException + MinContainsException in collect
     * mode), and one-match failure (MinContainsException).
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('validationMethodDataProvider')]
    public function testMinContains(GeneratorConfiguration $config): void
    {
        $className = $this->generateClassFromFile('ContainsWithMinContains.json', $config);

        // null is valid (optional property)
        $this->assertNull((new $className(['property' => null]))->getProperty());

        // arrays with ≥ 2 matching strings pass
        foreach ([['hello', 'world', 1], ['a', 'b', 'c'], ['a', 'b', 1, true, null]] as $valid) {
            $this->assertSame($valid, (new $className(['property' => $valid]))->getProperty());
        }

        // 0 matches: ContainsException fires, plus MinContainsException in collect mode
        try {
            new $className(['property' => [1, 2, 3]]);
            $this->fail('Expected an exception for array with no matching items');
        } catch (Exception $exception) {
            $this->assertStringContainsString(
                'No item in array property matches contains constraint',
                $exception->getMessage(),
            );
            if ($config->collectErrors()) {
                $this->assertInstanceOf(ErrorRegistryException::class, $exception);
                $errors = $exception->getErrors();
                $this->assertCount(2, $errors);
                $this->assertInstanceOf(ContainsException::class, $errors[0]);
                $this->assertInstanceOf(MinContainsException::class, $errors[1]);
                $this->assertSame(0, $errors[1]->getMatches());
            }
        }

        // 1 match < minContains=2 → MinContainsException; also verify exception metadata
        try {
            new $className(['property' => ['hello', 1, 2]]);
            $this->fail('Expected MinContainsException for array with one matching item');
        } catch (Exception $exception) {
            $this->assertStringContainsString(
                'Array property must not contain less than 2 items matching the contains constraint,'
                    . ' 1 matching items provided',
                $exception->getMessage(),
            );
            $minContainsException = $config->collectErrors() ? $exception->getErrors()[0] : $exception;
            $this->assertInstanceOf(MinContainsException::class, $minContainsException);
            $this->assertSame(2, $minContainsException->getMinContains());
            $this->assertSame(1, $minContainsException->getMatches());
        }
    }

    // --- maxContains ---

    /**
     * One generated class covers: null, valid arrays (1–3 matching strings), zero-match failure
     * (ContainsException — maxContains-only still requires ≥ 1 match by default), and
     * too-many-matches failure (MaxContainsException).
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('validationMethodDataProvider')]
    public function testMaxContains(GeneratorConfiguration $config): void
    {
        $className = $this->generateClassFromFile('ContainsWithMaxContains.json', $config);

        // null is valid (optional property)
        $this->assertNull((new $className(['property' => null]))->getProperty());

        // arrays with 1–3 matching strings pass (≤ maxContains=3)
        foreach ([['a', 1, 2], ['a', 'b', 1], ['a', 'b', 'c'], ['a', 'b', 'c', 1, 2]] as $valid) {
            $this->assertSame($valid, (new $className(['property' => $valid]))->getProperty());
        }

        // 0 matches: maxContains-only schema still requires ≥ 1 match (default minContains = 1)
        try {
            new $className(['property' => [1, 2, 3]]);
            $this->fail('Expected ContainsException for array with no matching items');
        } catch (Exception $exception) {
            $this->assertStringContainsString(
                'No item in array property matches contains constraint',
                $exception->getMessage(),
            );
            if ($config->collectErrors()) {
                $this->assertInstanceOf(ErrorRegistryException::class, $exception);
                $this->assertCount(1, $exception->getErrors());
                $this->assertInstanceOf(ContainsException::class, $exception->getErrors()[0]);
            }
        }

        // 4 matches > maxContains=3 → MaxContainsException; also verify exception metadata
        try {
            new $className(['property' => ['a', 'b', 'c', 'd']]);
            $this->fail('Expected MaxContainsException for array with four matching items');
        } catch (Exception $exception) {
            $this->assertStringContainsString(
                'Array property must not contain more than 3 items matching the contains constraint,'
                    . ' 4 matching items provided',
                $exception->getMessage(),
            );
            $maxContainsException = $config->collectErrors() ? $exception->getErrors()[0] : $exception;
            $this->assertInstanceOf(MaxContainsException::class, $maxContainsException);
            $this->assertSame(3, $maxContainsException->getMaxContains());
            $this->assertSame(4, $maxContainsException->getMatches());
        }
    }

    // --- minContains + maxContains combined ---

    /**
     * One generated class covers: null, valid in-range arrays, zero-match failure
     * (ContainsException + MinContainsException in collect mode), one-match too-few failure
     * (MinContainsException), and five-match too-many failure (MaxContainsException).
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('validationMethodDataProvider')]
    public function testMinMaxContains(GeneratorConfiguration $config): void
    {
        $className = $this->generateClassFromFile('ContainsWithMinMaxContains.json', $config);

        // null is valid (optional property)
        $this->assertNull((new $className(['property' => null]))->getProperty());

        // arrays with 2–4 matching strings pass (within minContains=2 … maxContains=4)
        foreach ([['a', 'b', 1], ['a', 'b', 'c', 1], ['a', 'b', 'c', 'd']] as $valid) {
            $this->assertSame($valid, (new $className(['property' => $valid]))->getProperty());
        }

        // 0 matches: ContainsException + MinContainsException in collect mode
        try {
            new $className(['property' => [1, 2, 3]]);
            $this->fail('Expected an exception for array with no matching items');
        } catch (Exception $exception) {
            $this->assertStringContainsString(
                'No item in array property matches contains constraint',
                $exception->getMessage(),
            );
            if ($config->collectErrors()) {
                $this->assertInstanceOf(ErrorRegistryException::class, $exception);
                $this->assertCount(2, $exception->getErrors());
                $this->assertInstanceOf(ContainsException::class, $exception->getErrors()[0]);
                $this->assertInstanceOf(MinContainsException::class, $exception->getErrors()[1]);
            }
        }

        // 1 match < minContains=2 → MinContainsException
        try {
            new $className(['property' => ['a', 1, 2]]);
            $this->fail('Expected MinContainsException for array with one matching item');
        } catch (Exception $exception) {
            $this->assertStringContainsString(
                'Array property must not contain less than 2 items matching the contains constraint',
                $exception->getMessage(),
            );
        }

        // 5 matches > maxContains=4 → MaxContainsException (terminal assertion)
        $this->expectValidationError(
            $config,
            'Array property must not contain more than 4 items matching the contains constraint,'
                . ' 5 matching items provided',
        );
        new $className(['property' => ['a', 'b', 'c', 'd', 'e']]);
    }

    // --- minContains = 0 (contains constraint becomes optional) ---

    /**
     * When minContains = 0, the contains schema has no effect: any array (including empty
     * and arrays with no matching items) must pass.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testMinContainsZeroAllowsAnyArray(): void
    {
        $className = $this->generateClassFromFile('ContainsWithMinContainsZero.json');

        // null is valid (optional property)
        $this->assertNull((new $className(['property' => null]))->getProperty());

        // any array passes regardless of how many items match the contains schema
        foreach ([[], [1, 2, true, null], ['a', 'b', 1], ['a', 'b', 'c']] as $valid) {
            $this->assertSame($valid, (new $className(['property' => $valid]))->getProperty());
        }
    }

    // --- Draft 07 silently ignores minContains / maxContains ---

    public function testDraft07IgnoresMinContainsAndAppliesBasicContainsOnly(): void
    {
        $configuration = (new GeneratorConfiguration())
            ->setCollectErrors(false)
            ->setDraft(new Draft_07());

        $className = $this->generateClassFromFile('ContainsWithMinContains.json', $configuration);

        // null is valid (optional property)
        $this->assertNull((new $className(['property' => null]))->getProperty());

        // Draft 07 only requires ≥ 1 match — 1 string is enough even though minContains=2 in schema
        $object = new $className(['property' => ['hello', 1, 2]]);
        $this->assertSame(['hello', 1, 2], $object->getProperty());

        // Draft 07 still rejects arrays with no match at all
        $this->expectException(ContainsException::class);
        new $className(['property' => [1, 2, 3]]);
    }

    // --- Schema-level validation: invalid minContains / maxContains values ---

    #[DataProvider('invalidMinContainsValueDataProvider')]
    public function testInvalidMinContainsValueThrowsSchemaException(
        mixed $minContains,
        string $expectedValueInMessage,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/Invalid minContains $expectedValueInMessage for property 'list'/",
        );

        $this->generateClass(
            json_encode([
                'type' => 'object',
                'properties' => [
                    'list' => [
                        'type' => 'array',
                        'contains' => ['type' => 'string'],
                        'minContains' => $minContains,
                    ],
                ],
            ]),
            (new GeneratorConfiguration())->setDraft(new Draft_2019_09()),
        );
    }

    public static function invalidMinContainsValueDataProvider(): array
    {
        return [
            'negative integer' => [-1, '-1'],
            'float'            => [1.5, '1\.5'],
            'string'           => ['2', "'2'"],
            'boolean'          => [true, 'true'],
        ];
    }

    #[DataProvider('invalidMaxContainsValueDataProvider')]
    public function testInvalidMaxContainsValueThrowsSchemaException(
        mixed $maxContains,
        string $expectedValueInMessage,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/Invalid maxContains $expectedValueInMessage for property 'list'/",
        );

        $this->generateClass(
            json_encode([
                'type' => 'object',
                'properties' => [
                    'list' => [
                        'type' => 'array',
                        'contains' => ['type' => 'string'],
                        'maxContains' => $maxContains,
                    ],
                ],
            ]),
            (new GeneratorConfiguration())->setDraft(new Draft_2019_09()),
        );
    }

    public static function invalidMaxContainsValueDataProvider(): array
    {
        return [
            'zero'             => [0, '0'],
            'negative integer' => [-1, '-1'],
            'float'            => [2.5, '2\.5'],
            'string'           => ['3', "'3'"],
            'boolean'          => [false, 'false'],
        ];
    }

    public function testMinContainsGreaterThanMaxContainsThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/minContains \(3\) must not be larger than maxContains \(2\)/');

        $this->generateClass(
            json_encode([
                'type' => 'object',
                'properties' => [
                    'list' => [
                        'type' => 'array',
                        'contains' => ['type' => 'string'],
                        'minContains' => 3,
                        'maxContains' => 2,
                    ],
                ],
            ]),
            (new GeneratorConfiguration())->setDraft(new Draft_2019_09()),
        );
    }
}
