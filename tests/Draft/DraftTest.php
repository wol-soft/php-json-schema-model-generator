<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Draft;

use PHPModelGenerator\Draft\AutoDetectionDraft;
use PHPModelGenerator\Draft\Draft_07;
use PHPModelGenerator\Draft\Element\Type;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPUnit\Framework\TestCase;

class DraftTest extends TestCase
{
    // --- DraftBuilder::getType ---

    public function testDraftBuilderGetTypeReturnsExistingType(): void
    {
        $builder = (new Draft_07())->getDefinition();

        $type = $builder->getType('string');

        $this->assertInstanceOf(Type::class, $type);
        $this->assertSame('string', $type->getType());
    }

    public function testDraftBuilderGetTypeReturnsNullForUnknownType(): void
    {
        $builder = (new Draft_07())->getDefinition();

        $this->assertNull($builder->getType('nonexistent'));
    }

    // --- Draft::getTypes / Draft::hasType ---

    public function testDraftGetTypesReturnsAllRegisteredTypes(): void
    {
        $types = (new Draft_07())->getDefinition()->build()->getTypes();

        $this->assertArrayHasKey('string', $types);
        $this->assertArrayHasKey('integer', $types);
        $this->assertArrayHasKey('number', $types);
        $this->assertArrayHasKey('boolean', $types);
        $this->assertArrayHasKey('array', $types);
        $this->assertArrayHasKey('object', $types);
        $this->assertArrayHasKey('null', $types);
        $this->assertArrayHasKey('any', $types);
        $this->assertCount(8, $types);
    }

    public function testDraftHasTypeReturnsTrueForRegisteredType(): void
    {
        $draft = (new Draft_07())->getDefinition()->build();

        $this->assertTrue($draft->hasType('string'));
        $this->assertTrue($draft->hasType('integer'));
        $this->assertTrue($draft->hasType('any'));
    }

    public function testDraftHasTypeReturnsFalseForUnknownType(): void
    {
        $draft = (new Draft_07())->getDefinition()->build();

        $this->assertFalse($draft->hasType('nonexistent'));
        $this->assertFalse($draft->hasType('custom'));
    }

    // --- Draft / getCoveredTypes contract ---

    public function testGetCoveredTypesThrowsForUnknownType(): void
    {
        $this->expectException(SchemaException::class);

        (new Draft_07())->getDefinition()->build()->getCoveredTypes('nonexistent');
    }

    // --- AutoDetectionDraft ---

    public function testAutoDetectionReturnsDraft07ForDraft07SchemaKeyword(): void
    {
        $jsonSchema = new JsonSchema('test.json', [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
        ]);

        $this->assertInstanceOf(Draft_07::class, (new AutoDetectionDraft())->getDraftForSchema($jsonSchema));
    }

    public function testAutoDetectionFallsBackToDraft07WhenSchemaKeywordAbsent(): void
    {
        $jsonSchema = new JsonSchema('test.json', ['type' => 'object']);

        $this->assertInstanceOf(Draft_07::class, (new AutoDetectionDraft())->getDraftForSchema($jsonSchema));
    }

    public function testAutoDetectionFallsBackToDraft07ForUnrecognisedSchemaKeyword(): void
    {
        $jsonSchema = new JsonSchema('test.json', ['$schema' => 'https://example.com/custom-schema']);

        $this->assertInstanceOf(Draft_07::class, (new AutoDetectionDraft())->getDraftForSchema($jsonSchema));
    }

    // --- GeneratorConfiguration ---

    public function testGeneratorConfigurationDefaultDraftIsAutoDetection(): void
    {
        $this->assertInstanceOf(AutoDetectionDraft::class, (new GeneratorConfiguration())->getDraft());
    }

    public function testGeneratorConfigurationAcceptsDraftInterface(): void
    {
        $draft = new Draft_07();
        $config = (new GeneratorConfiguration())->setDraft($draft);

        $this->assertSame($draft, $config->getDraft());
    }

    public function testGeneratorConfigurationAcceptsDraftFactoryInterface(): void
    {
        $factory = new AutoDetectionDraft();
        $config = (new GeneratorConfiguration())->setDraft($factory);

        $this->assertSame($factory, $config->getDraft());
    }

    public function testGeneratorConfigurationSetDraftReturnsSelf(): void
    {
        $config = new GeneratorConfiguration();

        $this->assertSame($config, $config->setDraft(new Draft_07()));
    }

    public function testGeneratorConfigurationSetDraftFactoryReturnsSelf(): void
    {
        $config = new GeneratorConfiguration();

        $this->assertSame($config, $config->setDraft(new AutoDetectionDraft()));
    }
}
