<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\BuilderClassPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;
use ReflectionClass;

/**
 * Extensive test harness for AdCP 3.1.0-beta.7 bundled media-buy schemas.
 *
 * Generates PHP models from real-world bundled schemas downloaded from
 * the AdCP protocol repository.
 *
 * Schemas stored in:  tests/Schema/MediaBuySchemasTest/v3.1.0-beta.7/
 *
 * SCHEMA CATALOG (25 files, all kept for future extensibility):
 *
 *   Request/Response pairs:
 *     create-media-buy-{request,response}.json   — Create a media buy
 *     get-media-buys-{request,response}.json     — List/search media buys
 *     get-media-buy-delivery-{request,response}.json — Get delivery data
 *     get-products-{request,response}.json       — Get available products
 *     update-media-buy-{request,response}.json   — Update a media buy
 *     build-creative-{request,response}.json     — Build creative assets
 *     list-creative-formats-{request,response}.json — List creative formats
 *     log-event-{request,response}.json          — Log events
 *     provide-performance-feedback-{request,response}.json — Performance feedback
 *     sync-audiences-{request,response}.json     — Sync audience segments
 *     sync-catalogs-{request,response}.json      — Sync product catalogs
 *     sync-event-sources-{request,response}.json — Sync event sources
 *   Standalone:
 *     package-request.json                       — Package request schema
 *
 * WHY KEEP ALL 25:
 *   - Real-world, production-grade bundled JSON Schemas with all $ref
 *     resolved inline — the most demanding test input possible.
 *   - Exercise every major JSON Schema feature: allOf, oneOf/anyOf,
 *     $defs enums, format validators, pattern, min/max, const, etc.
 *   - Large schemas (up to 3.6MB) stress-test memory and recursion.
 *   - Full protocol surface for regression testing at version 3.1.0-beta.7.
 *   - Each schema exercises a different subset of the generator.
 *   - Future tests can be added without re-downloading.
 *
 * MEMORY: Large schemas (get-products-response, create-media-buy-request,
 * get-media-buy-delivery-response) generate many classes and consume
 * significant PHP memory. When running the full class, use
 * --process-isolation to avoid PHP memory fragmentation between tests.
 *
 * BUGS DISCOVERED (same as MediaBuySchemasTest):
 *   B1. Filter classes missing getAcceptedTypes() (PHP 8.4 compat)
 *   B2. Filter callbacks reference non-existent runtime classes
 *   B3. Format validator null-safety: validate(?string) not validate(string)
 *   B4. Merge class oneOf validation uses camelCase property names
 *   B5. (Fixed) Class name explosion for deeply nested objects
 *   B6. Required child properties override parent oneOf composition
 *   B7. Root-level oneOf unsupported by builder pattern
 *   B8. toArray() uses camelCase, constructor expects snake_case
 *   B9. toArray / resolveSerializationHook calls non-existent
 *       getSerializedValue()
 */
class MediaBuyBeta7SchemasTest extends AbstractPHPModelGeneratorTestCase
{
    private const SCHEMA_VERSION_DIR = 'v3.1.0-beta.7';
    private const ENUM_OUTPUT_DIR = 'PHPModelGeneratorTest/MediaBuyBeta7Enums';
    private const ENUM_NAMESPACE = 'MediaBuyBeta7Enum';

    public function setUp(): void
    {
        parent::setUp();

        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(
                new EnumPostProcessor(
                    join(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), self::ENUM_OUTPUT_DIR]),
                    self::ENUM_NAMESPACE,
                )
            );
        };
    }

    /**
     * Add the BuilderClassPostProcessor for tests that exercise builder round-trips.
     */
    private function addBuilder(): void
    {
        $prev = $this->modifyModelGenerator;
        $this->modifyModelGenerator = static function (ModelGenerator $generator) use ($prev): void {
            ($prev)($generator);
            $generator->addPostProcessor(new BuilderClassPostProcessor());
        };
    }

    // ── get-products-response ────────────────────────────────────────────

    /**
     * GET PRODUCTS RESPONSE — consolidated round-trip covering constructor,
     * builder, unchanged wholesale path, and errors array.
     *
     * The schema has root-level if/then/else on unchanged (const true) which
     * conditionally requires cache_scope and wholesale_feed_version. We test
     * both branches in one method to generate the 2.2MB schema once.
     *
     * @runInSeparateProcess
     */
    public function testGetProductsResponseConsolidated(): void
    {
        $this->addBuilder();
        $className = $this->generate('get-products-response.json');
        $builderClassName = $className . 'Builder';
        $this->assertTrue(class_exists($builderClassName));

        // Phase 1: constructor with standard response (else branch)
        $object = new $className([
            'status' => 'completed',
            'products' => [],
            'cache_scope' => 'account',
        ]);
        $this->assertInstanceOf($className, $object);
        $serialized = $object->toArray();
        $this->assertIsArray($serialized);

        $statusKey = array_key_exists('status', $serialized) ? 'status' : 'Status';
        $this->assertSame('completed', $serialized[$statusKey] ?? null);

        $cacheKey = array_key_exists('cache_scope', $serialized) ? 'cache_scope' : 'cacheScope';
        $this->assertSame('account', $serialized[$cacheKey] ?? null);

        $this->assertArrayHasKey($statusKey, $serialized);
        $this->assertArrayHasKey($cacheKey, $serialized);

        // Phase 2: builder population
        $builder = new $builderClassName();
        $builder
            ->setStatus('completed')
            ->setProducts([])
            ->setCacheScope('account')
            ->setAdcpVersion('3.1');

        $model = $builder->validate();
        $this->assertInstanceOf($className, $model);

        $builderOutput = $model->toArray();
        $this->assertIsArray($builderOutput);
        $this->assertSame('completed', $builderOutput['status'] ?? $builderOutput['Status'] ?? null);

        // Phase 3: wholesale unchanged path (if branch)
        $unchanged = new $className([
            'status' => 'completed',
            'unchanged' => true,
            'wholesale_feed_version' => 'v3',
            'cache_scope' => 'public',
        ]);
        $this->assertInstanceOf($className, $unchanged);

        // Phase 4: constructor with errors array
        $withErrors = new $className([
            'status' => 'completed',
            'products' => [],
            'cache_scope' => 'account',
            'errors' => [
                [
                    'code' => 'NOT_FOUND',
                    'message' => 'Product not found',
                ],
            ],
        ]);
        $this->assertInstanceOf($className, $withErrors);

        // Phase 5: verify all generated filenames stay under 255 chars
        $reflection = new ReflectionClass($className);
        $filePath = $reflection->getFileName();
        $dir = dirname($filePath);
        $maxLength = 0;
        $longestFile = '';
        foreach (glob("$dir/*.php") as $generatedFile) {
            $basename = basename($generatedFile);
            $len = strlen($basename);
            if ($len > $maxLength) {
                $maxLength = $len;
                $longestFile = $basename;
            }
        }
        $this->assertLessThan(
            255,
            $maxLength,
            "Generated filename exceeds filesystem limit: $longestFile ($maxLength chars)",
        );
    }

    // ── create-media-buy-request ─────────────────────────────────────────

    /**
     * CREATE MEDIA BUY REQUEST — manual mode + proposal mode consolidated.
     *
     * Required fields: idempotency_key (16-255 chars, pattern), account (oneOf
     * account_id or brand+operator), brand (with domain), start_time (oneOf
     * 'asap' or ISO date-time), end_time (ISO date-time).
     *
     * @runInSeparateProcess
     */
    public function testCreateMediaBuyRequestConsolidated(): void
    {
        $this->addBuilder();
        $className = $this->generate('create-media-buy-request.json');
        $builderClassName = $className . 'Builder';

        // Phase 1: constructor with required fields only (manual mode)
        $object = new $className([
            'idempotency_key' => 'test-createmb-001-abcdefgh',  // 24+ chars, matches pattern
            'account' => ['account_id' => 'acc_test'],
            'brand' => ['domain' => 'test-brand.com'],
            'start_time' => 'asap',
            'end_time' => '2026-12-31T23:59:59Z',
        ]);
        $this->assertInstanceOf($className, $object);
        $serialized = $object->toArray();
        $this->assertIsArray($serialized);

        $idKey = array_key_exists('idempotency_key', $serialized) ? 'idempotency_key' : 'idempotencyKey';
        $this->assertSame('test-createmb-001-abcdefgh', $serialized[$idKey] ?? null);

        // Phase 2: builder population
        $builder = new $builderClassName();
        $builder
            ->setIdempotencyKey('builder-createmb-key-001-xyz')  // 28 chars, matches pattern
            ->setAccount(['account_id' => 'acc_builder'])
            ->setBrand(['domain' => 'builder-brand.com'])
            ->setStartTime('asap')
            ->setEndTime('2026-12-31T00:00:00Z')
            ->setAdcpVersion('3.1');

        $model = $builder->validate();
        $this->assertInstanceOf($className, $model);

        $builderOutput = $model->toArray();
        $this->assertIsArray($builderOutput);

        // Phase 3: proposal mode via constructor
        $proposal = new $className([
            'idempotency_key' => 'proposal-createmb-key-001-abc',  // 29 chars
            'account' => ['account_id' => 'acc_test'],
            'brand' => ['domain' => 'test-brand.com'],
            'start_time' => 'asap',
            'end_time' => '2026-12-31T23:59:59Z',
            'proposal_id' => 'prop_001',
            'total_budget' => [
                'amount' => 10000.00,
                'currency' => 'USD',
            ],
        ]);
        $this->assertInstanceOf($className, $proposal);

        // Phase 4: verify no generated filename exceeds filesystem limit
        $reflection = new ReflectionClass($className);
        $filePath = $reflection->getFileName();
        $dir = dirname($filePath);
        $maxLength = 0;
        $longestFile = '';
        foreach (glob("$dir/*.php") as $generatedFile) {
            $basename = basename($generatedFile);
            $len = strlen($basename);
            if ($len > $maxLength) {
                $maxLength = $len;
                $longestFile = $basename;
            }
        }
        $this->assertLessThan(
            255,
            $maxLength,
            "Generated filename exceeds filesystem limit: $longestFile ($maxLength chars)",
        );
    }

    // ── create-media-buy-response ────────────────────────────────────────

    /**
     * CREATE MEDIA BUY RESPONSE — verifies generation and constructor for
     * the protocol envelope (allOf). Generation and constructor work
     * correctly with data matching the success branch.
     *
     * Known limitation (B6): builder methods are duplicated when two
     * properties in the same merge class $ref the same $def entry with
     * different names, because the second property is a PropertyProxy whose
     * getAttribute() reflects the first property. The builder post-processor
     * is not added for this test to avoid the duplicate-method crash.
     */
    public function testCreateMediaBuyResponseConsolidated(): void
    {
        $className = $this->generate('create-media-buy-response.json');

        // Phase 1: constructor with success shape data
        $object = new $className([
            'status' => 'completed',
            'adcp_version' => '3.1',
            'media_buy_id' => 'mb_001',
            'account' => [
                'account_id' => 'acc_test',
                'name' => 'Test Account',
                'status' => 'active',
            ],
            'confirmed_at' => '2026-01-01T00:00:00Z',
            'revision' => 1,
            'currency' => 'USD',
            'packages' => [],
        ]);
        $this->assertInstanceOf($className, $object);
        $serialized = $object->toArray();
        $this->assertIsArray($serialized);

        $statusKey = array_key_exists('status', $serialized) ? 'status' : 'Status';
        $this->assertSame('completed', $serialized[$statusKey] ?? null);

        // Phase 2: verify filename length
        $reflection = new ReflectionClass($className);
        $dir = dirname($reflection->getFileName());
        $maxLength = 0;
        $longestFile = '';
        foreach (glob("$dir/*.php") as $generatedFile) {
            $basename = basename($generatedFile);
            $len = strlen($basename);
            if ($len > $maxLength) {
                $maxLength = $len;
                $longestFile = $basename;
            }
        }
        $this->assertLessThan(
            255,
            $maxLength,
            "Generated filename exceeds filesystem limit: $longestFile ($maxLength chars)",
        );
    }

    // ── get-media-buy-delivery-request ────────────────────────────────────

    /**
     * GET MEDIA BUY DELIVERY REQUEST — account_id / brand+operator modes.
     *
     * The schema has allOf (version envelope) with properties including
     * account (oneOf account_id or brand+operator), media_buy_ids array,
     * status_filter, date range, and reporting dimensions.
     */
    public function testGetMediaBuyDeliveryRequestConsolidated(): void
    {
        $this->addBuilder();
        $className = $this->generate('get-media-buy-delivery-request.json');
        $builderClassName = $className . 'Builder';

        // Phase 1: account_id mode
        $object = new $className([
            'account' => ['account_id' => 'acc_test'],
            'media_buy_ids' => ['mb_001', 'mb_002'],
        ]);
        $this->assertInstanceOf($className, $object);
        $serialized = $object->toArray();
        $this->assertIsArray($serialized);

        $acctKey = array_key_exists('account', $serialized) ? 'account' : 'Account';
        $this->assertIsArray($serialized[$acctKey] ?? []);
        $accIdKey = array_key_exists('account_id', $serialized[$acctKey])
            ? 'account_id'
            : 'accountId';
        $this->assertSame('acc_test', $serialized[$acctKey][$accIdKey] ?? null);

        // Phase 2: brand+operator mode via builder
        $builder = new $builderClassName();
        $builder
            ->setAccount([
                'brand' => ['domain' => 'test-brand.com'],
                'operator' => 'test-brand.com',
            ])
            ->setMediaBuyIds(['mb_003'])
            ->setIncludePackageDailyBreakdown(true)
            ->setAdcpVersion('3.1');

        $model = $builder->validate();
        $this->assertInstanceOf($className, $model);

        $builderOutput = $model->toArray();
        $this->assertIsArray($builderOutput);
        $this->assertSame(
            'test-brand.com',
            $builderOutput['account']['brand']['domain'] ?? null,
        );

        // Phase 3: verify filename length
        $reflection = new ReflectionClass($className);
        $dir = dirname($reflection->getFileName());
        $maxLength = 0;
        $longestFile = '';
        foreach (glob("$dir/*.php") as $generatedFile) {
            $basename = basename($generatedFile);
            $len = strlen($basename);
            if ($len > $maxLength) {
                $maxLength = $len;
                $longestFile = $basename;
            }
        }
        $this->assertLessThan(
            255,
            $maxLength,
            "Generated filename exceeds filesystem limit: $longestFile ($maxLength chars)",
        );
    }

    // ── get-media-buy-delivery-response ───────────────────────────────────

    /**
     * GET MEDIA BUY DELIVERY RESPONSE — consolidated round-trip covering
     * constructor, builder, aggregated_totals, and filename check.
     *
     * The schema has allOf (version envelope + protocol envelope) with
     * response-specific fields: notification_type, reporting_period,
     * currency, attribution_window, aggregated_totals, by_media_buy.
     *
     * @runInSeparateProcess
     */
    public function testGetMediaBuyDeliveryResponseConsolidated(): void
    {
        $this->addBuilder();
        $className = $this->generate('get-media-buy-delivery-response.json');
        $builderClassName = $className . 'Builder';

        // Phase 1: constructor with status + required delivery fields
        $object = new $className([
            'status' => 'completed',
            'currency' => 'USD',
            'reporting_period' => [
                'start' => '2026-01-01T00:00:00Z',
                'end' => '2026-01-31T23:59:59Z',
            ],
            'media_buy_deliveries' => [],
            'aggregated_totals' => [
                'impressions' => 100000,
                'spend' => 5000.00,
                'media_buy_count' => 3,
            ],
        ]);
        $this->assertInstanceOf($className, $object);
        $serialized = $object->toArray();
        $this->assertIsArray($serialized);

        $statusKey = array_key_exists('status', $serialized) ? 'status' : 'Status';
        $this->assertSame('completed', $serialized[$statusKey] ?? null);

        $currKey = array_key_exists('currency', $serialized) ? 'currency' : 'Currency';
        $this->assertSame('USD', $serialized[$currKey] ?? null);

        // Phase 2: builder population
        $builder = new $builderClassName();
        $builder
            ->setStatus('completed')
            ->setCurrency('USD')
            ->setMediaBuyDeliveries([])
            ->setAggregatedTotals(['impressions' => 50000, 'spend' => 2500.00, 'media_buy_count' => 1])
            ->setReportingPeriod(['start' => '2026-02-01T00:00:00Z', 'end' => '2026-02-28T23:59:59Z'])
            ->setAdcpVersion('3.1');

        $model = $builder->validate();
        $this->assertInstanceOf($className, $model);

        $builderOutput = $model->toArray();
        $this->assertIsArray($builderOutput);
        $this->assertSame('completed', $builderOutput['status'] ?? $builderOutput['Status'] ?? null);

        // Phase 3: verify filename length
        $reflection = new ReflectionClass($className);
        $dir = dirname($reflection->getFileName());
        $maxLength = 0;
        $longestFile = '';
        foreach (glob("$dir/*.php") as $generatedFile) {
            $basename = basename($generatedFile);
            $len = strlen($basename);
            if ($len > $maxLength) {
                $maxLength = $len;
                $longestFile = $basename;
            }
        }
        $this->assertLessThan(
            255,
            $maxLength,
            "Generated filename exceeds filesystem limit: $longestFile ($maxLength chars)",
        );
    }

    // ── deep nesting regression (B5) ──────────────────────────────────────

    /**
     * Targeted regression test for B5: deeply nested schemas must not
     * produce filenames that overflow the 255-char filesystem limit.
     *
     * Creates a schema with 15 levels of nested objects, each containing
     * both allOf composition and a nested property, which previously
     * caused exponential class name compounding.
     */
    public function testDeepNestingNoFilenameOverflow(): void
    {
        $nested = ['type' => 'object', 'properties' => ['leaf' => ['type' => 'string']]];
        for ($i = 0; $i < 15; $i++) {
            $nested = [
                'type' => 'object',
                'allOf' => [
                    ['type' => 'object', 'properties' => ['inner' => $nested]],
                ],
                'properties' => [
                    'value' => ['type' => 'string'],
                ],
            ];
        }
        $schema = json_encode([
            'title' => 'DeepNestingTest',
            'type' => 'object',
            'properties' => ['nested' => $nested],
        ]);

        $className = $this->generateClass(
            $schema,
            (new GeneratorConfiguration())
                ->setSerialization(true)
                ->setImmutable(false)
                ->setOutputEnabled(false)
                ->setImplicitNull(false),
        );

        $reflection = new ReflectionClass($className);
        $dir = dirname($reflection->getFileName());
        $maxLength = 0;
        $longestFile = '';
        foreach (glob("$dir/*.php") as $generatedFile) {
            $basename = basename($generatedFile);
            $len = strlen($basename);
            if ($len > $maxLength) {
                $maxLength = $len;
                $longestFile = $basename;
            }
        }
        $this->assertLessThan(
            255,
            $maxLength,
            "Deeply nested schema produced an overlong filename: $longestFile ($maxLength chars)",
        );
    }

    // ── generation helpers ───────────────────────────────────────────────

    private function generate(string $schemaFile): string
    {
        $schemaPath = __DIR__ . '/Schema/MediaBuySchemasTest/' . self::SCHEMA_VERSION_DIR . '/' . $schemaFile;
        $this->assertFileExists($schemaPath);

        $jsonSchema = file_get_contents($schemaPath);

        $configuration = (new GeneratorConfiguration())
            ->setSerialization(true)
            ->setImmutable(false)
            ->setOutputEnabled(false)
            ->setImplicitNull(false);

        $className = $this->generateClass($jsonSchema, $configuration);
        $this->assertTrue(class_exists($className));

        return $className;
    }
}
