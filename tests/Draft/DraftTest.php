<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Draft;

use PHPModelGenerator\Draft\AutoDetectionDraft;
use PHPModelGenerator\Draft\Draft_07;
use PHPModelGenerator\Draft\Draft_2019_09;
use PHPModelGenerator\Draft\Element\Type;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\String\MinLengthException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\Factory\SimplePropertyValidatorFactory;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // --- Draft::getTypesForKeyword ---

    /** @return array<string, array{string, string[]}> */
    public static function keywordToExpectedTypesProvider(): array
    {
        return [
            // one representative per type-space so the registry wiring is exercised
            'string keyword (minLength)'     => ['minLength', ['string']],
            'numeric keyword (minimum)'      => ['minimum', ['integer', 'number']],
            'array keyword (minItems)'       => ['minItems', ['array']],
            'object keyword (minProperties)' => ['minProperties', ['object']],
            'any keyword (enum)'             => ['enum', ['any']],
            'any keyword (allOf)'            => ['allOf', ['any']],
            'unknown keyword'                => ['nonexistentKeyword', []],
            'metadata keyword ($schema)'     => ['$schema', []],
        ];
    }

    /**
     * @param string[] $expectedTypes
     */
    #[DataProvider('keywordToExpectedTypesProvider')]
    public function testGetTypesForKeywordReturnsRegisteredTypes(
        string $keyword,
        array $expectedTypes,
    ): void {
        $types = (new Draft_07())->getDefinition()->build()->getTypesForKeyword($keyword);

        foreach ($expectedTypes as $expected) {
            $this->assertContains($expected, $types);
        }

        $this->assertCount(count($expectedTypes), $types);
    }

    public function testGetTypesForKeywordReflectsCustomRegistration(): void
    {
        $customFactory = new class extends SimplePropertyValidatorFactory {
            protected function isValueValid(mixed $value): bool
            {
                return is_int($value) && $value >= 0;
            }

            protected function getValidator(
                PropertyInterface $property,
                mixed $value,
            ): PropertyValidatorInterface {
                return new PropertyValidator(
                    $property,
                    "is_string(\$value) && mb_strlen(\$value) < $value",
                    MinLengthException::class,
                    [$value],
                );
            }
        };

        $builder = (new Draft_07())->getDefinition();
        $builder->getType('string')->addValidator('customMin', $customFactory);
        $draft = $builder->build();

        $this->assertSame(['string'], $draft->getTypesForKeyword('customMin'));
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

    /** @return array<string, array{string}> */
    public static function draft201909SchemaUriProvider(): array
    {
        return [
            'https without trailing hash' => ['https://json-schema.org/draft/2019-09/schema'],
            'https with trailing hash'    => ['https://json-schema.org/draft/2019-09/schema#'],
            'http without trailing hash'  => ['http://json-schema.org/draft/2019-09/schema'],
            'http with trailing hash'     => ['http://json-schema.org/draft/2019-09/schema#'],
        ];
    }

    #[DataProvider('draft201909SchemaUriProvider')]
    public function testAutoDetectionReturnsDraft201909ForDraft201909SchemaKeyword(string $schemaUri): void
    {
        $jsonSchema = new JsonSchema('test.json', ['$schema' => $schemaUri]);

        $this->assertInstanceOf(Draft_2019_09::class, (new AutoDetectionDraft())->getDraftForSchema($jsonSchema));
    }

    public function testAutoDetectionReusesCachedDraft07Instance(): void
    {
        $autoDetectionDraft = new AutoDetectionDraft();

        $firstSchema = new JsonSchema('first.json', ['$schema' => 'http://json-schema.org/draft-07/schema#']);
        $secondSchema = new JsonSchema('second.json', ['type' => 'object']);

        $this->assertSame(
            $autoDetectionDraft->getDraftForSchema($firstSchema),
            $autoDetectionDraft->getDraftForSchema($secondSchema),
        );
    }

    public function testAutoDetectionReusesCachedDraft201909Instance(): void
    {
        $autoDetectionDraft = new AutoDetectionDraft();

        $firstSchema = new JsonSchema('first.json', ['$schema' => 'https://json-schema.org/draft/2019-09/schema']);
        $secondSchema = new JsonSchema('second.json', ['$schema' => 'https://json-schema.org/draft/2019-09/schema#']);

        $this->assertSame(
            $autoDetectionDraft->getDraftForSchema($firstSchema),
            $autoDetectionDraft->getDraftForSchema($secondSchema),
        );
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
