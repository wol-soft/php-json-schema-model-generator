<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\PostProcessor;

use Closure;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\AdditionalPropertiesAccessorPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PatternPropertiesAccessorPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\UnevaluatedPropertiesAccessorPostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * The post processors keep the runtime state of a model in internal properties (e.g. the collected additional
 * properties in '_additionalProperties'). A property of the schema may carry the name of such an internal property:
 * the model must keep both, the internal property must neither be merged into the property of the schema nor be
 * dropped.
 */
#[ApplicableDrafts]
class InternalStoragePropertyNameCollisionTest extends AbstractPHPModelGeneratorTestCase
{
    public static function internalStorageProvider(): array
    {
        return [
            // the collection of the additional properties; without additional properties nothing writes it, the
            // accessor reads the declared empty collection
            'collected additional properties' => [
                'additionalProperties',
                '"additionalProperties": {"type": "integer"}',
                [],
                AdditionalPropertiesAccessorPostProcessor::class,
                false,
                true,
                static fn(object $object) => self::assertSame([], $object->additionalProperties()->getAll()),
            ],
            // the cached additional properties accessor
            'additional properties accessor cache' => [
                'additionalPropertiesAccessor',
                '"additionalProperties": {"type": "integer"}',
                ['extra' => 1],
                AdditionalPropertiesAccessorPostProcessor::class,
                false,
                true,
                static fn(object $object) => self::assertSame(
                    ['extra' => 1],
                    $object->additionalProperties()->getAll(),
                ),
            ],
            // the collection of the pattern properties grouped by pattern
            'collected pattern properties' => [
                'patternProperties',
                '"patternProperties": {"^x": {"type": "integer"}}',
                ['x1' => 1],
                PatternPropertiesAccessorPostProcessor::class,
                false,
                true,
                static fn(object $object) => self::assertSame(['x1' => 1], $object->patternProperties()->get('^x')),
            ],
            // the map of the declared properties matching a pattern
            'pattern properties map' => [
                'patternPropertiesMap',
                '"patternProperties": {"^x": {"type": "integer"}}',
                ['x1' => 1],
                PatternPropertiesAccessorPostProcessor::class,
                false,
                true,
                static fn(object $object) => self::assertSame(['x1' => 1], $object->patternProperties()->get('^x')),
            ],
            // the cached pattern properties accessor
            'pattern properties accessor cache' => [
                'patternPropertiesAccessor',
                '"patternProperties": {"^x": {"type": "integer"}}',
                ['x1' => 1],
                PatternPropertiesAccessorPostProcessor::class,
                false,
                true,
                static fn(object $object) => self::assertSame(['x1' => 1], $object->patternProperties()->get('^x')),
            ],
            // the cached validation state of the composition branches, read by the setters
            'composition validation state' => [
                'propertyValidationState',
                '"allOf": [{"properties": {"b": {"type": "integer"}}}]',
                ['b' => 1],
                null,
                false,
                true,
                static function (object $object): void {
                    $object->setB(2);
                    self::assertSame(2, $object->getB());
                },
            ],
            // the optional properties the serialization skips if they are not provided
            'serialization skip map' => [
                'skipNotProvidedPropertiesMap',
                '"minProperties": 1',
                [],
                null,
                true,
                false,
                static fn(object $object) => self::assertSame(
                    ['skipNotProvidedPropertiesMap' => 'value of the property'],
                    $object->toArray(),
                ),
            ],
        ];
    }

    /**
     * @param class-string|null $postProcessorClass
     */
    #[DataProvider('internalStorageProvider')]
    public function testPropertyNamedLikeAnInternalStoragePropertyKeepsTheInternalStorage(
        string $propertyName,
        string $keywords,
        array $data,
        ?string $postProcessorClass,
        bool $serialization,
        bool $implicitNull,
        Closure $assertFeature,
    ): void {
        $this->assertInternalStorageIsKept(
            'UserPropertyNamedLikeInternalStorage.json',
            [$propertyName, $keywords],
            $propertyName,
            $data,
            $postProcessorClass,
            $serialization,
            $implicitNull,
            $assertFeature,
        );
    }

    public static function unevaluatedPropertiesInternalStorageProvider(): array
    {
        return [
            // the collection of the unevaluated properties
            'collected unevaluated properties' => [
                'unevaluatedProperties',
                '"unevaluatedProperties": {"type": "integer"}',
                ['extra' => 1],
                UnevaluatedPropertiesAccessorPostProcessor::class,
                static fn(object $object) => self::assertSame(
                    ['extra' => 1],
                    $object->unevaluatedProperties()->getAll(),
                ),
            ],
            // the cached unevaluated properties accessor
            'unevaluated properties accessor cache' => [
                'unevaluatedPropertiesAccessor',
                '"unevaluatedProperties": {"type": "integer"}',
                ['extra' => 1],
                UnevaluatedPropertiesAccessorPostProcessor::class,
                static fn(object $object) => self::assertSame(
                    ['extra' => 1],
                    $object->unevaluatedProperties()->getAll(),
                ),
            ],
            // the keys evaluated by the schema form of unevaluatedProperties
            'evaluated property keys' => [
                'evaluatedPropertyKeys',
                '"unevaluatedProperties": {"type": "integer"}',
                ['extra' => 1],
                null,
                static fn(object $object) => self::assertSame(1, $object->meta()->rawInput()['extra']),
            ],
            // the keys evaluated by the composition branches
            'composition evaluations' => [
                'compositionEvaluations',
                '"anyOf": [{"properties": {"b": {"type": "integer"}}}], "unevaluatedProperties": false',
                ['b' => 1],
                null,
                static fn(object $object) => self::assertSame(1, $object->getB()),
            ],
        ];
    }

    /**
     * @param class-string|null $postProcessorClass
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('unevaluatedPropertiesInternalStorageProvider')]
    public function testPropertyNamedLikeAnUnevaluatedPropertiesStoragePropertyKeepsTheInternalStorage(
        string $propertyName,
        string $keywords,
        array $data,
        ?string $postProcessorClass,
        Closure $assertFeature,
    ): void {
        $this->assertInternalStorageIsKept(
            'UserPropertyNamedLikeInternalStorage.json',
            [$propertyName, $keywords],
            $propertyName,
            $data,
            $postProcessorClass,
            false,
            true,
            $assertFeature,
        );
    }

    public static function arrayTrackingInternalStorageProvider(): array
    {
        return [
            // the item indices evaluated by the compositions of an array property
            'composition annotations' => ['compositionAnnotated'],
            // the item indices evaluated per array property
            'evaluated item indices' => ['evaluatedItemIndices'],
        ];
    }

    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('arrayTrackingInternalStorageProvider')]
    public function testPropertyNamedLikeAnArrayTrackingStoragePropertyKeepsTheInternalStorage(
        string $propertyName,
    ): void {
        $this->assertInternalStorageIsKept(
            'UserPropertyNamedLikeArrayTrackingStorage.json',
            [$propertyName],
            $propertyName,
            ['list' => [1]],
            null,
            false,
            true,
            static fn(object $object) => self::assertSame([1], $object->getList()),
        );
    }

    /**
     * @param string[] $templateValues
     * @param class-string|null $postProcessorClass
     */
    private function assertInternalStorageIsKept(
        string $schemaFile,
        array $templateValues,
        string $propertyName,
        array $data,
        ?string $postProcessorClass,
        bool $serialization,
        bool $implicitNull,
        Closure $assertFeature,
    ): void {
        $this->modifyModelGenerator = static function (ModelGenerator $generator) use ($postProcessorClass): void {
            if ($postProcessorClass !== null) {
                $generator->addPostProcessor(new $postProcessorClass());
            }
        };

        $className = $this->generateClassFromFileTemplate(
            $schemaFile,
            $templateValues,
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setCollectErrors(false)
                ->setSerialization($serialization),
            false,
            $implicitNull,
        );

        // An undeclared internal property is created dynamically (deprecated) or read as an undefined property:
        // collect the PHP errors raised while the model is used.
        $phpErrors = [];
        set_error_handler(static function (int $level, string $message) use (&$phpErrors): bool {
            $phpErrors[] = $message;

            return true;
        });

        try {
            $object = new $className([$propertyName => 'value of the property'] + $data);

            $this->assertSame('value of the property', $object->{'get' . ucfirst($propertyName)}());
            $assertFeature($object);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $phpErrors);
        $this->assertTrue(
            (new ReflectionClass($className))->hasProperty('_' . $propertyName),
            "The internal property '_$propertyName' must be declared",
        );
    }
}
