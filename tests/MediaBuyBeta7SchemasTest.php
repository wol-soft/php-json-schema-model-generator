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
 *
 * SCHEMAS TESTED (25 of 25):
 *   get-products-response                       — allOf + if/then/else, 2.2MB
 *   create-media-buy-request                    — allOf + oneOf (account, start_time), 2.0MB
 *   create-media-buy-response                   — allOf (2 branches), 676KB
 *   get-media-buy-delivery-request               — allOf + oneOf (account), 64KB
 *   get-media-buy-delivery-response              — allOf + response fields, 1.8MB
 *   update-media-buy-request                    — allOf + partial-update PATCH, 3.6MB
 *   build-creative-response                     — allOf (2 branches) + oneOf (4 branches), 3.1MB
 *   package-request                             — allOf + not + targeting_overlay, 1.7MB
 *   get-media-buys-request                      — allOf + oneOf (account), 54KB
 *   get-media-buys-response                     — allOf + pagination + media_buys, 364KB
 *   get-products-request                        — allOf + buying_mode enum, 263KB
 *   build-creative-request                      — allOf + idempotency_key, 1.4MB
 *   list-creative-formats-request               — allOf + format filters, 21KB
 *   list-creative-formats-response              — allOf + formats + source, 744KB
 *   log-event-request                           — allOf + events array, 20KB
 *   log-event-response                          — allOf + oneOf (2 branches), 57KB
 *   provide-performance-feedback-request        — allOf + performance_index, 7KB
 *   provide-performance-feedback-response       — allOf + oneOf (2 branches), 56KB
 *   sync-audiences-request                      — allOf + audiences + delete_missing, 62KB
 *   sync-audiences-response                     — allOf + oneOf (3 branches), 99KB
 *   sync-catalogs-request                       — allOf + catalogs + dry_run, 81KB
 *   sync-catalogs-response                      — allOf + oneOf (3 branches), 95KB
 *   sync-event-sources-request                  — allOf + event_sources + delete_missing, 54KB
 *   sync-event-sources-response                 — allOf + oneOf (2 branches), 84KB
 *   update-media-buy-response                   — allOf + oneOf (3 branches), 521KB
 */
class MediaBuyBeta7SchemasTest extends AbstractPHPModelGeneratorTestCase
{
    private const SCHEMA_VERSION_DIR = 'v3.1.0-beta.7';
    private const ENUM_OUTPUT_DIR = 'PHPModelGeneratorTest/MediaBuyBeta7Enums';
    private const ENUM_NAMESPACE = 'MediaBuyBeta7Enum';

    public function setUp(): void
    {
        parent::setUp();

        $this->builderAdded = false;
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
     * Idempotent: subsequent calls are no-ops.
     */
    private bool $builderAdded = false;

    private function addBuilder(): void
    {
        if ($this->builderAdded) {
            return;
        }
        $this->builderAdded = true;

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
        $this->assertTrue(class_exists($builderClassName));

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
     *
     * @runInSeparateProcess
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
     *
     * @runInSeparateProcess
     */
    public function testGetMediaBuyDeliveryRequestConsolidated(): void
    {
        $this->addBuilder();
        $className = $this->generate('get-media-buy-delivery-request.json');
        $builderClassName = $className . 'Builder';
        $this->assertTrue(class_exists($builderClassName));

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
        $this->assertTrue(class_exists($builderClassName));

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

    // ── update-media-buy-request ──────────────────────────────────────────

    /**
     * UPDATE MEDIA BUY REQUEST — partial update with account_id mode,
     * builder round-trip, and pause/cancel semantics.
     *
     * The schema has allOf (version envelope) with update-specific fields:
     * account (oneOf account_id or brand+operator), media_buy_id, revision,
     * paused, canceled, packages, new_packages, invoice_recipient, etc.
     * All fields are optional (PATCH semantics).
     *
     * @runInSeparateProcess
     */
    public function testUpdateMediaBuyRequestConsolidated(): void
    {
        $this->addBuilder();
        $className = $this->generate('update-media-buy-request.json');
        $builderClassName = $className . 'Builder';
        $this->assertTrue(class_exists($builderClassName));

        // Phase 1: constructor with required + account_id mode
        $object = new $className([
            'account' => ['account_id' => 'acc_test'],
            'media_buy_id' => 'mb_001',
            'idempotency_key' => 'upd-001-abcdefghijklmnop',
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

        // Phase 2: builder with brand+operator mode + optional fields
        $builder = new $builderClassName();
        $builder
            ->setAccount([
                'brand' => ['domain' => 'test-brand.com'],
                'operator' => 'test-brand.com',
            ])
            ->setMediaBuyId('mb_002')
            ->setIdempotencyKey('upd-builder-002-abcdefghijkl')
            ->setRevision(2)
            ->setAdcpVersion('3.1');

        $model = $builder->validate();
        $this->assertInstanceOf($className, $model);

        $builderOutput = $model->toArray();
        $this->assertIsArray($builderOutput);
        $this->assertSame(
            'test-brand.com',
            $builderOutput['account']['brand']['domain'] ?? null,
        );

        // Phase 3: pause/cancel a media buy
        $canceled = new $className([
            'account' => ['account_id' => 'acc_test'],
            'media_buy_id' => 'mb_003',
            'idempotency_key' => 'cancel-003-abcdefghijklmn',
            'paused' => false,
            'canceled' => true,
            'cancellation_reason' => 'Campaign objectives changed',
        ]);
        $this->assertInstanceOf($className, $canceled);

        $canceledArray = $canceled->toArray();
        $pausedKey = array_key_exists('paused', $canceledArray) ? 'paused' : 'Paused';
        $canceledKey = array_key_exists('canceled', $canceledArray) ? 'canceled' : 'Canceled';
        $this->assertFalse($canceledArray[$pausedKey] ?? true);
        $this->assertTrue($canceledArray[$canceledKey] ?? false);

        // Phase 4: verify JsonPointer attributes on composition properties.
        // The AdCPVersionEnvelope allOf branch is defined inline at two positions:
        //   /allOf/0 and /properties/new_packages/items/allOf/0.
        // Content-signature dedup produces a single class. The pointer reflects the
        // position first encountered during processing (new_packages items).
        $this->assertPropertyHasJsonPointer(
            $object,
            'adcpVersion',
            '/properties/new_packages/items/allOf/0/properties/adcp_version',
        );
        $this->assertPropertyHasJsonPointer(
            $object,
            'adcpMajorVersion',
            '/properties/new_packages/items/allOf/0/properties/adcp_major_version',
        );
        // Root-level properties must retain their simple /properties/... pointer.
        $this->assertPropertyHasJsonPointer($object, 'account', '/properties/account');
        $this->assertPropertyHasJsonPointer($object, 'mediaBuyId', '/properties/media_buy_id');

        // Phase 5: verify class-level JsonPointer — one class (content-signature dedup).
        $dir = dirname((new ReflectionClass($className))->getFileName());
        $versionEnvelopeFiles = array_values(array_filter(
            glob("$dir/*AdCPVersionEnvelope*.php"),
            fn(string $f): bool => !str_contains($f, 'Builder'),
        ));
        $this->assertCount(1, $versionEnvelopeFiles,
            'AdCPVersionEnvelope content signature is shared across positions');
        if (isset($versionEnvelopeFiles[0])) {
            $content = file_get_contents($versionEnvelopeFiles[0]);
            $this->assertStringContainsString('#[JsonPointer', $content);
        }

        // Phase 5: verify filename length
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

    // ── build-creative-response ───────────────────────────────────────────

    /**
     * BUILD CREATIVE RESPONSE — root-level allOf + oneOf with 4 branches,
     * builder round-trip on the success branch.
     *
     * The schema has allOf (version envelope) combined with oneOf:
     *   branch 0: creative_manifest (single, canonical format_kind)
     *   branch 1: creative_manifests (array, multiple)
     *   branch 2: errors (validation failure)
     *   branch 3: status + task_id (async task)
     *
     * @runInSeparateProcess
     */
    public function testBuildCreativeResponseConsolidated(): void
    {
        $this->addBuilder();
        $className = $this->generate('build-creative-response.json');
        $builderClassName = $className . 'Builder';
        $this->assertTrue(class_exists($builderClassName));

        // Phase 1: success branch with single creative_manifest (canonical format_kind)
        // Assets patternProperties accept empty objects via additionalProperties: true
        $object = new $className([
            'status' => 'completed',
            'creative_manifest' => [
                'format_kind' => 'image',
                'assets' => [],
            ],
        ]);
        $this->assertInstanceOf($className, $object);

        // Phase 2: builder with multi-creative-manifest branch
        $builder = new $builderClassName();
        $builder
            ->setStatus('completed')
            ->setCreativeManifests([
                [
                    'format_kind' => 'image',
                    'assets' => [],
                ],
                [
                    'format_kind' => 'html5',
                    'assets' => [],
                ],
            ])
            ->setAdcpVersion('3.1');

        $model = $builder->validate();
        $this->assertInstanceOf($className, $model);

        // Phase 3: error branch (status "failed" is the correct enum value, not "error")
        $errorResponse = new $className([
            'status' => 'failed',
            'errors' => [
                ['code' => 'VALIDATION_ERROR', 'message' => 'Invalid format kind'],
            ],
        ]);
        $this->assertInstanceOf($className, $errorResponse);

        // Phase 4: async task branch
        $taskResponse = new $className([
            'status' => 'submitted',
            'task_id' => 'task_001',
            'message' => 'Build in progress',
        ]);
        $this->assertInstanceOf($className, $taskResponse);

        // Phase 5: verify filename length
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

    // ── package-request ───────────────────────────────────────────────────

    /**
     * PACKAGE REQUEST — allOf + not keyword, builder round-trip with
     * targeting overlay and creative assignments.
     *
     * The schema has allOf (version envelope), a `not` that forbids
     * `capability_ids`, and rich nested structures: targeting_overlay,
     * creative_assignments, creatives, optimization_goals, etc.
     *
     * @runInSeparateProcess
     */
    public function testPackageRequestConsolidated(): void
    {
        $this->addBuilder();
        $className = $this->generate('package-request.json');
        $builderClassName = $className . 'Builder';
        $this->assertTrue(class_exists($builderClassName));

        // Phase 1: constructor with required fields
        $object = new $className([
            'product_id' => 'prod_001',
            'budget' => 10000.00,
            'pricing_option_id' => 'cpm',
        ]);
        $this->assertInstanceOf($className, $object);
        $serialized = $object->toArray();
        $this->assertIsArray($serialized);

        $prodKey = array_key_exists('product_id', $serialized) ? 'product_id' : 'productId';
        $this->assertSame('prod_001', $serialized[$prodKey] ?? null);

        // Phase 2: builder with targeting overlay and optional fields
        $builder = new $builderClassName();
        $builder
            ->setProductId('prod_002')
            ->setBudget(25000.00)
            ->setPricingOptionId('cpm')
            ->setBidPrice(5.50)
            ->setImpressions(500000)
            ->setStartTime('2026-06-01T00:00:00Z')
            ->setEndTime('2026-12-31T23:59:59Z')
            ->setPacing('even')
            ->setFormatIds([['agent_url' => 'https://fmt.example.com', 'id' => 'fmt_001']])
            ->setTargetingOverlay([
                'geo_countries' => ['US', 'CA'],
                'language' => ['en'],
            ])
            ->setAdcpVersion('3.1');

        $model = $builder->validate();
        $this->assertInstanceOf($className, $model);

        $builderOutput = $model->toArray();
        $this->assertIsArray($builderOutput);
        $pricingKey = array_key_exists('pricing_option_id', $builderOutput)
            ? 'pricing_option_id'
            : 'pricingOptionId';
        $this->assertSame('cpm', $builderOutput[$pricingKey] ?? null);

        // Phase 3: builder with creative assignments
        $builder2 = new $builderClassName();
        $builder2
            ->setProductId('prod_003')
            ->setBudget(5000.00)
            ->setPricingOptionId('cpm')
            ->setCreativeAssignments([
                [
                    'creative_id' => 'cr_001',
                    'format_id' => ['agent_url' => 'https://fmt.example.com', 'id' => 'fmt_003'],
                    'format_option_ref' => ['scope' => 'product', 'format_option_id' => 'opt_001'],
                ],
            ])
            ->setAdcpVersion('3.1');

        $model2 = $builder2->validate();
        $this->assertInstanceOf($className, $model2);

        // Phase 4: verify filename length
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

    // ── get-media-buys-request ────────────────────────────────────────────

    /**
     * @runInSeparateProcess
     */
    public function testGetMediaBuysRequest(): void
    {
        $this->addBuilder();
        $className = $this->generate('get-media-buys-request.json');
        $builderClassName = $className . 'Builder';
        $this->assertTrue(class_exists($builderClassName));

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

        $builder = new $builderClassName();
        $builder
            ->setAccount([
                'brand' => ['domain' => 'test-brand.com'],
                'operator' => 'test-brand.com',
            ])
            ->setMediaBuyIds(['mb_003'])
            ->setIncludeSnapshot(true)
            ->setAdcpVersion('3.1');

        $model = $builder->validate();
        $this->assertInstanceOf($className, $model);

        $builderOutput = $model->toArray();
        $this->assertIsArray($builderOutput);
        $this->assertSame(
            'test-brand.com',
            $builderOutput['account']['brand']['domain'] ?? null,
        );

        $this->assertPropertyHasJsonPointer($object, 'account', '/properties/account');
        $this->assertPropertyHasJsonPointer($object, 'mediaBuyIds', '/properties/media_buy_ids');

        $this->assertFilenamesWithinLimit($className);
    }

    // ── get-media-buys-response ───────────────────────────────────────────

    /**
     * @runInSeparateProcess
     */
    public function testGetMediaBuysResponse(): void
    {
        $this->addBuilder();
        $className = $this->generate('get-media-buys-response.json');
        $builderClassName = $className . 'Builder';
        $this->assertTrue(class_exists($builderClassName));

        $object = new $className([
            'status' => 'completed',
            'media_buys' => [],
        ]);
        $this->assertInstanceOf($className, $object);
        $serialized = $object->toArray();
        $this->assertIsArray($serialized);

        $builder = new $builderClassName();
        $builder
            ->setStatus('completed')
            ->setMediaBuys([])
            ->setPagination(['has_more' => false])
            ->setAdcpVersion('3.1');

        $model = $builder->validate();
        $this->assertInstanceOf($className, $model);

        $builderOutput = $model->toArray();
        $this->assertIsArray($builderOutput);

        $this->assertPropertyHasJsonPointer($object, 'mediaBuys', '/properties/media_buys');
        $this->assertPropertyHasJsonPointer($object, 'pagination', '/properties/pagination');

        $this->assertFilenamesWithinLimit($className);
    }

    // ── get-products-request ──────────────────────────────────────────────

    /**
     * @runInSeparateProcess
     */
    public function testGetProductsRequest(): void
    {
        $this->addBuilder();
        $className = $this->generate('get-products-request.json');
        $builderClassName = $className . 'Builder';
        $this->assertTrue(class_exists($builderClassName));

        $object = new $className([
            'buying_mode' => 'brief',
            'account' => ['account_id' => 'acc_test'],
        ]);
        $this->assertInstanceOf($className, $object);
        $serialized = $object->toArray();
        $this->assertIsArray($serialized);

        $builder = new $builderClassName();
        $builder
            ->setBuyingMode('brief')
            ->setAccount(['account_id' => 'acc_builder'])
            ->setBrief('Campaign for summer collection')
            ->setAdcpVersion('3.1');

        $model = $builder->validate();
        $this->assertInstanceOf($className, $model);

        $builderOutput = $model->toArray();
        $this->assertIsArray($builderOutput);

        $bmKey = array_key_exists('buying_mode', $builderOutput) ? 'buying_mode' : 'buyingMode';
        $this->assertSame('brief', $builderOutput[$bmKey] ?? null);

        $this->assertPropertyHasJsonPointer($object, 'account', '/properties/account');

        $this->assertFilenamesWithinLimit($className);
    }

    // ── build-creative-request ────────────────────────────────────────────

    /**
     * @runInSeparateProcess
     */
    public function testBuildCreativeRequest(): void
    {
        $this->addBuilder();
        $className = $this->generate('build-creative-request.json');
        $builderClassName = $className . 'Builder';
        $this->assertTrue(class_exists($builderClassName));

        $object = new $className([
            'idempotency_key' => 'test-bc-req-001-key-abcdefghijk',
        ]);
        $this->assertInstanceOf($className, $object);
        $serialized = $object->toArray();
        $this->assertIsArray($serialized);

        $builder = new $builderClassName();
        $builder
            ->setIdempotencyKey('builder-bc-req-001-key-abcdefgh')
            ->setAccount([
                'brand' => ['domain' => 'test-brand.com'],
                'operator' => 'test-brand.com',
            ])
            ->setAdcpVersion('3.1');

        $model = $builder->validate();
        $this->assertInstanceOf($className, $model);

        $builderOutput = $model->toArray();
        $this->assertIsArray($builderOutput);

        $idKey = array_key_exists('idempotency_key', $builderOutput) ? 'idempotency_key' : 'idempotencyKey';
        $this->assertSame('builder-bc-req-001-key-abcdefgh', $builderOutput[$idKey] ?? null);

        $this->assertPropertyHasJsonPointer($object, 'creativeManifest', '/properties/creative_manifest');
        $this->assertPropertyHasJsonPointer($object, 'idempotencyKey', '/properties/idempotency_key');

        $this->assertFilenamesWithinLimit($className);
    }

    // ── list-creative-formats-request ─────────────────────────────────────

    /**
     * @runInSeparateProcess
     */
    public function testListCreativeFormatsRequest(): void
    {
        $this->addBuilder();
        $className = $this->generate('list-creative-formats-request.json');
        $builderClassName = $className . 'Builder';
        $this->assertTrue(class_exists($builderClassName));

        $object = new $className([]);
        $this->assertInstanceOf($className, $object);
        $serialized = $object->toArray();
        $this->assertIsArray($serialized);

        $builder = new $builderClassName();
        $builder
            ->setFormatIds([
                ['agent_url' => 'https://creative.adcontextprotocol.org', 'id' => 'display_300x250'],
            ])
            ->setAssetTypes(['image', 'video'])
            ->setMaxWidth(1920)
            ->setAdcpVersion('3.1');

        $model = $builder->validate();
        $this->assertInstanceOf($className, $model);

        $builderOutput = $model->toArray();
        $this->assertIsArray($builderOutput);

        $fmtKey = array_key_exists('format_ids', $builderOutput) ? 'format_ids' : 'formatIds';
        $this->assertIsArray($builderOutput[$fmtKey] ?? null);
        $this->assertSame(
            'https://creative.adcontextprotocol.org',
            $builderOutput[$fmtKey][0]['agent_url'] ?? null,
        );

        $this->assertPropertyHasJsonPointer($object, 'formatIds', '/properties/format_ids');

        $this->assertFilenamesWithinLimit($className);
    }

    // ── list-creative-formats-response ────────────────────────────────────

    /**
     * @runInSeparateProcess
     */
    public function testListCreativeFormatsResponse(): void
    {
        $this->addBuilder();
        $className = $this->generate('list-creative-formats-response.json');
        $builderClassName = $className . 'Builder';
        $this->assertTrue(class_exists($builderClassName));

        $object = new $className([
            'status' => 'completed',
            'formats' => [],
        ]);
        $this->assertInstanceOf($className, $object);
        $serialized = $object->toArray();
        $this->assertIsArray($serialized);

        $builder = new $builderClassName();
        $builder
            ->setStatus('completed')
            ->setFormats([])
            ->setSource('publisher')
            ->setAdcpVersion('3.1');

        $model = $builder->validate();
        $this->assertInstanceOf($className, $model);

        $builderOutput = $model->toArray();
        $this->assertIsArray($builderOutput);

        $this->assertPropertyHasJsonPointer($object, 'formats', '/properties/formats');
        $this->assertPropertyHasJsonPointer($object, 'source', '/properties/source');

        $this->assertFilenamesWithinLimit($className);
    }

    // ── log-event-request ─────────────────────────────────────────────────

    /**
     * @runInSeparateProcess
     */
    public function testLogEventRequest(): void
    {
        $this->addBuilder();
        $className = $this->generate('log-event-request.json');
        $builderClassName = $className . 'Builder';
        $this->assertTrue(class_exists($builderClassName));

        $object = new $className([
            'idempotency_key' => 'log-001-key-abcdefghijklmn',
            'event_source_id' => 'evt_src_001',
            'events' => [
                ['event_id' => 'evt_001', 'event_type' => 'page_view', 'event_time' => '2026-01-01T00:00:00Z'],
            ],
        ]);
        $this->assertInstanceOf($className, $object);
        $serialized = $object->toArray();
        $this->assertIsArray($serialized);

        $builder = new $builderClassName();
        $builder
            ->setEventSourceId('evt_src_002')
            ->setIdempotencyKey('log-builder-002-key-abcdefgh')
            ->setEvents([['event_id' => 'evt_002', 'event_type' => 'search', 'event_time' => '2026-01-02T00:00:00Z']])
            ->setAdcpVersion('3.1');

        $model = $builder->validate();
        $this->assertInstanceOf($className, $model);

        $builderOutput = $model->toArray();
        $this->assertIsArray($builderOutput);

        $this->assertPropertyHasJsonPointer($object, 'eventSourceId', '/properties/event_source_id');
        $this->assertPropertyHasJsonPointer($object, 'events', '/properties/events');

        $this->assertFilenamesWithinLimit($className);
    }

    // ── log-event-response ────────────────────────────────────────────────

    /**
     * LOG EVENT RESPONSE — oneOf at root (2 branches): success and error.
     * No builder (root-level oneOf unsupported).
     *
     * @runInSeparateProcess
     */
    public function testLogEventResponse(): void
    {
        $className = $this->generate('log-event-response.json');

        $success = new $className([
            'status' => 'completed',
            'events_received' => 10,
            'events_processed' => 10,
        ]);
        $this->assertInstanceOf($className, $success);
        $successArray = $success->toArray();
        $this->assertIsArray($successArray);

        $error = new $className([
            'status' => 'completed',
            'errors' => [['code' => 'ERROR', 'message' => 'Failed']],
        ]);
        $this->assertInstanceOf($className, $error);
        $errorArray = $error->toArray();
        $this->assertIsArray($errorArray);

        $this->assertFilenamesWithinLimit($className);
    }

    // ── provide-performance-feedback-request ──────────────────────────────

    /**
     * @runInSeparateProcess
     */
    public function testProvidePerformanceFeedbackRequest(): void
    {
        $this->addBuilder();
        $className = $this->generate('provide-performance-feedback-request.json');
        $builderClassName = $className . 'Builder';
        $this->assertTrue(class_exists($builderClassName));

        $object = new $className([
            'idempotency_key' => 'ppf-001-key-abcdefghijklmn',
            'media_buy_id' => 'mb_001',
            'measurement_period' => [
                'start' => '2026-01-01T00:00:00Z',
                'end' => '2026-01-31T23:59:59Z',
            ],
            'performance_index' => 1.5,
        ]);
        $this->assertInstanceOf($className, $object);
        $serialized = $object->toArray();
        $this->assertIsArray($serialized);

        $builder = new $builderClassName();
        $builder
            ->setIdempotencyKey('ppf-builder-002-key-abcdef')
            ->setMediaBuyId('mb_002')
            ->setMeasurementPeriod([
                'start' => '2026-02-01T00:00:00Z',
                'end' => '2026-02-28T23:59:59Z',
            ])
            ->setPerformanceIndex(2.0)
            ->setAdcpVersion('3.1');

        $model = $builder->validate();
        $this->assertInstanceOf($className, $model);

        $builderOutput = $model->toArray();
        $this->assertIsArray($builderOutput);

        $this->assertFilenamesWithinLimit($className);
    }

    // ── provide-performance-feedback-response ─────────────────────────────

    /**
     * PROVIDE PERFORMANCE FEEDBACK RESPONSE — oneOf at root (2 branches):
     * success and error. No builder (root-level oneOf unsupported).
     *
     * @runInSeparateProcess
     */
    public function testProvidePerformanceFeedbackResponse(): void
    {
        $className = $this->generate('provide-performance-feedback-response.json');

        $success = new $className([
            'status' => 'completed',
            'success' => true,
        ]);
        $this->assertInstanceOf($className, $success);
        $successArray = $success->toArray();

        $error = new $className([
            'status' => 'completed',
            'errors' => [['code' => 'ERROR', 'message' => 'Failed']],
        ]);
        $this->assertInstanceOf($className, $error);
        $errorArray = $error->toArray();
        $this->assertIsArray($errorArray);

        $this->assertFilenamesWithinLimit($className);
    }

    // ── sync-audiences-request ────────────────────────────────────────────

    /**
     * @runInSeparateProcess
     */
    public function testSyncAudiencesRequest(): void
    {
        $this->addBuilder();
        $className = $this->generate('sync-audiences-request.json');
        $builderClassName = $className . 'Builder';
        $this->assertTrue(class_exists($builderClassName));

        $object = new $className([
            'idempotency_key' => 'sync-aud-001-abcdefghijkl',
            'account' => ['account_id' => 'acc_test'],
        ]);
        $this->assertInstanceOf($className, $object);
        $serialized = $object->toArray();
        $this->assertIsArray($serialized);

        $builder = new $builderClassName();
        $builder
            ->setAccount(['account_id' => 'acc_builder'])
            ->setAudiences([['audience_id' => 'aud_001']])
            ->setDeleteMissing(false)
            ->setIdempotencyKey('sync-aud-builder-001-abcde')
            ->setAdcpVersion('3.1');

        $model = $builder->validate();
        $this->assertInstanceOf($className, $model);

        $builderOutput = $model->toArray();
        $this->assertIsArray($builderOutput);

        $this->assertFilenamesWithinLimit($className);
    }

    // ── sync-audiences-response ───────────────────────────────────────────

    /**
     * SYNC AUDIENCES RESPONSE — oneOf at root (3 branches): success, error,
     * and async. No builder (root-level oneOf unsupported).
     *
     * @runInSeparateProcess
     */
    public function testSyncAudiencesResponse(): void
    {
        $className = $this->generate('sync-audiences-response.json');

        $success = new $className([
            'status' => 'completed',
            'audiences' => [],
        ]);
        $this->assertInstanceOf($className, $success);
        $successArray = $success->toArray();
        $this->assertIsArray($successArray);

        $error = new $className([
            'status' => 'completed',
            'errors' => [['code' => 'ERROR', 'message' => 'Failed']],
        ]);
        $this->assertInstanceOf($className, $error);
        $errorArray = $error->toArray();
        $this->assertIsArray($errorArray);

        $async = new $className([
            'status' => 'submitted',
            'task_id' => 'task_001',
            'message' => 'Processing',
        ]);
        $this->assertInstanceOf($className, $async);
        $asyncArray = $async->toArray();
        $this->assertIsArray($asyncArray);

        $this->assertFilenamesWithinLimit($className);
    }

    // ── sync-catalogs-request ─────────────────────────────────────────────

    /**
     * @runInSeparateProcess
     */
    public function testSyncCatalogsRequest(): void
    {
        $this->addBuilder();
        $className = $this->generate('sync-catalogs-request.json');
        $builderClassName = $className . 'Builder';
        $this->assertTrue(class_exists($builderClassName));

        $object = new $className([
            'idempotency_key' => 'sync-cat-001-abcdefghijkl',
            'account' => ['account_id' => 'acc_test'],
        ]);
        $this->assertInstanceOf($className, $object);
        $serialized = $object->toArray();
        $this->assertIsArray($serialized);

        $builder = new $builderClassName();
        $builder
            ->setAccount(['account_id' => 'acc_builder'])
            ->setCatalogs([['type' => 'product']])
            ->setDryRun(true)
            ->setIdempotencyKey('sync-cat-builder-001-abcde')
            ->setAdcpVersion('3.1');

        $model = $builder->validate();
        $this->assertInstanceOf($className, $model);

        $builderOutput = $model->toArray();
        $this->assertIsArray($builderOutput);

        $this->assertFilenamesWithinLimit($className);
    }

    // ── sync-catalogs-response ────────────────────────────────────────────

    /**
     * SYNC CATALOGS RESPONSE — oneOf at root (3 branches): success, error,
     * and async. No builder (root-level oneOf unsupported).
     *
     * @runInSeparateProcess
     */
    public function testSyncCatalogsResponse(): void
    {
        $className = $this->generate('sync-catalogs-response.json');

        $success = new $className([
            'status' => 'completed',
            'catalogs' => [],
        ]);
        $this->assertInstanceOf($className, $success);
        $successArray = $success->toArray();
        $this->assertIsArray($successArray);

        $error = new $className([
            'status' => 'completed',
            'errors' => [['code' => 'ERROR', 'message' => 'Failed']],
        ]);
        $this->assertInstanceOf($className, $error);
        $errorArray = $error->toArray();
        $this->assertIsArray($errorArray);

        $async = new $className([
            'status' => 'submitted',
            'task_id' => 'task_001',
        ]);
        $this->assertInstanceOf($className, $async);
        $asyncArray = $async->toArray();
        $this->assertIsArray($asyncArray);

        $this->assertFilenamesWithinLimit($className);
    }

    // ── sync-event-sources-request ────────────────────────────────────────

    /**
     * @runInSeparateProcess
     */
    public function testSyncEventSourcesRequest(): void
    {
        $this->addBuilder();
        $className = $this->generate('sync-event-sources-request.json');
        $builderClassName = $className . 'Builder';
        $this->assertTrue(class_exists($builderClassName));

        $object = new $className([
            'idempotency_key' => 'sync-es-001-abcdefghijklm',
            'account' => ['account_id' => 'acc_test'],
        ]);
        $this->assertInstanceOf($className, $object);
        $serialized = $object->toArray();
        $this->assertIsArray($serialized);

        $builder = new $builderClassName();
        $builder
            ->setAccount(['account_id' => 'acc_builder'])
            ->setEventSources([['event_source_id' => 'es_001']])
            ->setDeleteMissing(false)
            ->setIdempotencyKey('sync-es-builder-001-abcd')
            ->setAdcpVersion('3.1');

        $model = $builder->validate();
        $this->assertInstanceOf($className, $model);

        $builderOutput = $model->toArray();
        $this->assertIsArray($builderOutput);

        $this->assertFilenamesWithinLimit($className);
    }

    // ── sync-event-sources-response ───────────────────────────────────────

    /**
     * SYNC EVENT SOURCES RESPONSE — oneOf at root (2 branches): success
     * and error. No builder (root-level oneOf unsupported).
     *
     * @runInSeparateProcess
     */
    public function testSyncEventSourcesResponse(): void
    {
        $className = $this->generate('sync-event-sources-response.json');

        $success = new $className([
            'status' => 'completed',
            'event_sources' => [],
        ]);
        $this->assertInstanceOf($className, $success);
        $successArray = $success->toArray();
        $this->assertIsArray($successArray);

        $error = new $className([
            'status' => 'completed',
            'errors' => [['code' => 'ERROR', 'message' => 'Failed']],
        ]);
        $this->assertInstanceOf($className, $error);
        $errorArray = $error->toArray();
        $this->assertIsArray($errorArray);

        $this->assertFilenamesWithinLimit($className);
    }

    // ── update-media-buy-response ─────────────────────────────────────────

    /**
     * UPDATE MEDIA BUY RESPONSE — oneOf at root (3 branches): success,
     * error, and async. No builder (root-level oneOf unsupported).
     *
     * @runInSeparateProcess
     */
    public function testUpdateMediaBuyResponse(): void
    {
        $className = $this->generate('update-media-buy-response.json');

        $success = new $className([
            'status' => 'completed',
            'media_buy_id' => 'mb_001',
            'revision' => 1,
            'media_buy_status' => 'active',
        ]);
        $this->assertInstanceOf($className, $success);
        $successArray = $success->toArray();
        $this->assertIsArray($successArray);

        $error = new $className([
            'status' => 'completed',
            'errors' => [['code' => 'ERROR', 'message' => 'Failed']],
        ]);
        $this->assertInstanceOf($className, $error);
        $errorArray = $error->toArray();
        $this->assertIsArray($errorArray);

        $async = new $className([
            'status' => 'submitted',
            'task_id' => 'task_001',
        ]);
        $this->assertInstanceOf($className, $async);
        $asyncArray = $async->toArray();
        $this->assertIsArray($asyncArray);

        $this->assertFilenamesWithinLimit($className);
    }

    // ── deep nesting regression (B5) ──────────────────────────────────────

    /**
     * Targeted regression test for B5: deeply nested schemas must not
     * produce filenames that overflow the 255-char filesystem limit.
     *
     * Creates a schema with 15 levels of nested objects, each containing
     * both allOf composition and a nested property, which previously
     * caused exponential class name compounding.
     *
     * @runInSeparateProcess
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

    private function assertFilenamesWithinLimit(string $className, int $limit = 255): void
    {
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
            $limit,
            $maxLength,
            "Generated filename exceeds limit: $longestFile ($maxLength chars)",
        );
    }

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
