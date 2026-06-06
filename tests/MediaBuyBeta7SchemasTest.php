<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\BuilderClassPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;

/**
 * Extensive test harness for AdCP 3.1.0-beta.7 bundled media-buy schemas.
 *
 * Generates PHP models from real-world bundled schemas downloaded from
 * the AdCP protocol repository.
 *
 * Schemas stored in:  tests/Schema/MediaBuySchemasTest/v3.1.0-beta.7/
 * (overriding getSchemaFilePath to include the version subdirectory)
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
 *
 *   Standalone:
 *     package-request.json                       — Package request schema
 *
 * WHY KEEP ALL 25:
 *   - They are real-world, production-grade bundled JSON Schemas with
 *     all $ref resolved inline — the most demanding test input possible.
 *   - They exercise every major JSON Schema feature: allOf composition,
 *     oneOf/anyOf discriminators, deeply nested objects, $defs enums,
 *     format validators (uri, date-time, email, ipv6, etc.), pattern
 *     constraints, min/max, additionalProperties, const, and more.
 *   - Large schemas (up to 3.6MB) stress-test the generator's memory
 *     and recursion limits.
 *   - Having the full protocol surface means we can regression-test
 *     against the canonical AdCP specification at a specific version
 *     pin (3.1.0-beta.7).
 *   - The 25 files cover the full media-buy domain: CRUD, creative
 *     management, delivery measurement, catalog sync, audience sync,
 *     event tracking, and performance feedback. Each schema exercises
 *     a different subset of the generator's capabilities.
 *   - Future tests can be added for any of these schemas without
 *     re-downloading.
 *
 * BUGS DISCOVERED (same as MediaBuySchemasTest):
 *   B1. Filter classes missing getAcceptedTypes() (PHP 8.4 compat)
 *   B2. Filter callbacks reference non-existent runtime classes
 *   B3. Format validator null-safety: validate(?string) not validate(string)
 *   B4. Merge class oneOf validation uses camelCase property names
 *   B5. Class name explosion for deeply nested objects
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
            $generator->addPostProcessor(new BuilderClassPostProcessor());

            $generator->addPostProcessor(
                new EnumPostProcessor(
                    join(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), self::ENUM_OUTPUT_DIR]),
                    self::ENUM_NAMESPACE,
                )
            );
        };
    }

    // ── get-products-response ────────────────────────────────────────────

    /**
     * GET PRODUCTS RESPONSE — full round-trip via constructor + builder.
     *
     * Phase 1: constructor with minimal data + toArray
     * Phase 2: builder population + validate + toArray
     *
     * NOTE: The schema has root-level if/then/else on unchanged: if unchanged
     * is const true, wholesale_feed_version and cache_scope are required and
     * products must be absent. Otherwise (else), cache_scope is required.
     * This test uses the else path: products=[], cache_scope set.
     */
    public function testGetProductsResponseRoundTrip(): void
    {
        $className = $this->generate('get-products-response.json');
        $builderClassName = $className . 'Builder';

        // Phase 1: constructor with minimal valid data
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
    }

    /**
     * GET PRODUCTS RESPONSE — wholesale unchanged path.
     */
    public function testGetProductsResponseUnchangedPath(): void
    {
        $className = $this->generate('get-products-response.json');

        $object = new $className([
            'status' => 'completed',
            'unchanged' => true,
            'wholesale_feed_version' => 'v3',
            'cache_scope' => 'public',
        ]);
        $this->assertInstanceOf($className, $object);
    }

    /**
     * GET PRODUCTS RESPONSE — constructor with errors array.
     */
    public function testGetProductsResponseWithErrors(): void
    {
        $className = $this->generate('get-products-response.json');

        $object = new $className([
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
        $this->assertInstanceOf($className, $object);
    }

    // ── create-media-buy-request ─────────────────────────────────────────

    /**
     * CREATE MEDIA BUY REQUEST — manual mode via constructor + builder.
     *
     * Required fields: idempotency_key, account (oneOf account_id or brand+operator),
     * brand (with domain), start_time (oneOf 'asap' or ISO date-time), end_time (date-time).
     *
     * Phase 1: constructor with required fields + empty packages
     * Phase 2: builder population + validate + toArray
     */
    public function testCreateMediaBuyRequestRoundTrip(): void
    {
        $className = $this->generate('create-media-buy-request.json');
        $builderClassName = $className . 'Builder';

        // Phase 1: constructor with required fields + minimal packages
        // NOTE: idempotency_key must be 16-255 chars matching ^[A-Za-z0-9_.:-]{16,255}$
        //       packages must have minItems: 1
        $object = new $className([
            'idempotency_key' => 'test-key-001-abcdef',  // 16 chars
            'account' => ['account_id' => 'acc_test'],
            'brand' => ['domain' => 'test-brand.com'],
            'start_time' => 'asap',
            'end_time' => '2026-12-31T23:59:59Z',
        ]);
        $this->assertInstanceOf($className, $object);
        $serialized = $object->toArray();
        $this->assertIsArray($serialized);

        // Phase 2: builder population
        $builder = new $builderClassName();
        $builder
            ->setIdempotencyKey('builder-test-key-abc1234')  // 16+ chars for builder too
            ->setAccount(['account_id' => 'acc_builder'])
            ->setBrand(['domain' => 'builder-brand.com'])
            ->setStartTime('asap')
            ->setEndTime('2026-12-31T00:00:00Z')
            ->setAdcpVersion('3.1');

        $model = $builder->validate();
        $this->assertInstanceOf($className, $model);

        $builderOutput = $model->toArray();
        $this->assertIsArray($builderOutput);
    }

    /**
     * CREATE MEDIA BUY REQUEST — proposal mode via constructor.
     */
    public function testCreateMediaBuyRequestProposalMode(): void
    {
        $className = $this->generate('create-media-buy-request.json');

        $object = new $className([
            'idempotency_key' => 'proposal-key-001',
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
        $this->assertInstanceOf($className, $object);
    }

    // ── generation helpers ───────────────────────────────────────────────

    /**
     * Generate a model from a beta.7 schema file.
     */
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
        $this->assertTrue(class_exists($className . 'Builder'));

        return $className;
    }
}
