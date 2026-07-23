<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Fixtures\RecordingLogger;
use ReflectionClass;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class IdenticalNestedSchemaTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
#[ApplicableDrafts]
class IdenticalNestedSchemaTest extends AbstractPHPModelGeneratorTestCase
{
    public function testIdenticalSchemaInSingleFileAreMappedToOneClass(): void
    {
        $className = $this->generateClassFromFile('IdenticalSubSchema.json');

        $reflection = new ReflectionClass($className);

        $this->assertSame(
            $reflection->getProperty('object1')->getDocComment(),
            $reflection->getProperty('object2')->getDocComment(),
        );

        $object = new $className([
            'object1' => ['property1' => 'Hello'],
            'object2' => ['property1' => 'Goodbye'],
        ]);

        $this->assertSame('Hello', $object->getObject1()->getProperty1());
        $this->assertSame('Goodbye', $object->getObject2()->getProperty1());

        $this->assertSame($object->getObject1()::class, $object->getObject2()::class);
    }

    public function testIdenticalReferencedSchemaInSingleFileAreMappedToOneClass(): void
    {
        $className = $this->generateClassFromFile('IdenticalReferencedSchema.json');

        $reflection = new ReflectionClass($className);

        $this->assertSame(
            $reflection->getProperty('object1')->getDocComment(),
            $reflection->getProperty('object2')->getDocComment(),
        );

        $object = new $className([
            'object1' => ['member' => ['name' => 'Hannes', 'age' => 42]],
            'object2' => ['member' => ['name' => 'Frida', 'age' => 24]],
        ]);

        $this->assertSame('Hannes', $object->getObject1()->getMember()->getName());
        $this->assertSame(42, $object->getObject1()->getMember()->getAge());

        $this->assertSame('Frida', $object->getObject2()->getMember()->getName());
        $this->assertSame(24, $object->getObject2()->getMember()->getAge());

        $this->assertSame($object->getObject1()->getMember()::class, $object->getObject2()->getMember()::class);
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('identicalReferencedSchemaDataProvider')]
    public function testIdenticalReferencedSchemaInMultipleFilesAreMappedToOneClass(
        string $file,
        string $class1Suffix,
        string $class2Suffix,
    ): void {
        $this->generateDirectory($file, (new GeneratorConfiguration())->setNamespacePrefix($file));

        $namespacePrefix = $this->lastGeneratedNamespacePrefix;
        $object1 = new ("\\{$namespacePrefix}\\{$class1Suffix}")(['member' => ['name' => 'Hannes', 'age' => 42]]);
        $this->assertSame('Hannes', $object1->getMember()->getName());
        $this->assertSame(42, $object1->getMember()->getAge());

        $object2 = new ("\\{$namespacePrefix}\\{$class2Suffix}")(['member' => ['name' => 'Frida', 'age' => 24]]);
        $this->assertSame('Frida', $object2->getMember()->getName());
        $this->assertSame(24, $object2->getMember()->getAge());

        $this->assertSame($object1->getMember()::class, $object2->getMember()::class);
    }

    public static function identicalReferencedSchemaDataProvider(): array
    {
        return [
            'In same namespace' => [
                'IdenticalReferencedSchemaInSameNamespace',
                'Schema1',
                'Schema2',
            ],
            'In different namespace' => [
                'IdenticalReferencedSchemaInDifferentNamespace',
                'SubFolder1\\Schema',
                'SubFolder2\\Schema',
            ],
        ];
    }

    public function testIdenticalSchemasInDifferentNamespaceAreMappedToOneClass(): void
    {
        $this->generateDirectory(
            'IdenticalSubSchemaDifferentNamespace',
            (new GeneratorConfiguration())->setNamespacePrefix('IdenticalSubSchemaDifferentNamespace'),
        );

        $namespacePrefix = $this->lastGeneratedNamespacePrefix;
        $subObject1 = new ("\\{$namespacePrefix}\\DifferentNamespaceSubFolder10\\SubSchema")(['object1' => ['property1' => 'Hello']]);
        $this->assertSame('Hello', $subObject1->getObject1()->getProperty1());

        $subObject2 = new ("\\{$namespacePrefix}\\DifferentNamespaceSubFolder20\\SubSchema")(['object1' => ['property1' => 'Goodbye']]);
        $this->assertSame('Goodbye', $subObject2->getObject1()->getProperty1());

        $this->assertSame($subObject1->getObject1()::class, $subObject2->getObject1()::class);
    }

    public function testIdenticalSchemasInArraysAreMappedToOneClass(): void
    {
        $this->generateDirectory(
            'IdenticalSubSchemaInArray',
            (new GeneratorConfiguration())->setNamespacePrefix('IdenticalSubSchemaInArray'),
        );

        $namespacePrefix = $this->lastGeneratedNamespacePrefix;
        $subObject1 = new ("\\{$namespacePrefix}\\ArraySubFolder1\\SubSchema")(['object1' => [['property1' => 'Hello']]]);
        $this->assertSame('Hello', $subObject1->getObject1()[0]->getProperty1());

        $subObject2 = new ("\\{$namespacePrefix}\\ArraySubFolder2\\SubSchema")(['object1' => [['property1' => 'Goodbye']]]);
        $this->assertSame('Goodbye', $subObject2->getObject1()[0]->getProperty1());

        $this->assertSame($subObject1->getObject1()[0]::class, $subObject2->getObject1()[0]::class);
    }

    public function testIdenticalSchemasInCompositionAreMappedToOneClass(): void
    {
        $recordingLogger = new RecordingLogger();

        $this->generateDirectory(
            'IdenticalSubSchemaInComposition',
            (new GeneratorConfiguration())
                ->setNamespacePrefix('IdenticalSubSchemaInComposition')
                ->setLogger($recordingLogger),
        );

        $namespacePrefix = $this->lastGeneratedNamespacePrefix;
        $entries = $recordingLogger->getEntries();

        $this->assertTrue(
            $this->hasLogEntry($entries, 'info', 'Generated class {class}', [
                'class' => "{$namespacePrefix}\\CompositionSubFolder1\\SubSchema",
            ]),
            'Expected a "Generated class" log entry for CompositionSubFolder1\SubSchema.',
        );
        $this->assertTrue(
            $this->hasLogEntry($entries, 'info', 'Rendered class {class}', [
                'class' => "{$namespacePrefix}\\CompositionSubFolder1\\SubSchema",
            ]),
            'Expected a "Rendered class" log entry for CompositionSubFolder1\SubSchema.',
        );
        $this->assertTrue(
            $this->hasLogEntry(
                $entries,
                'notice',
                'Duplicated signature {signature} for class {class}. Redirecting to {redirectClass}',
            ),
            'Expected a duplicated-signature log entry for the identical nested schemas.',
        );
        $this->assertTrue(
            $this->hasLogEntry(
                $entries,
                'warning',
                "Empty composition for '{property}' may lead to unexpected results",
                ['property' => 'property2'],
            ),
            'Expected an empty-composition warning for property2.',
        );

        $subObject1 = new ("\\{$namespacePrefix}\\CompositionSubFolder1\\SubSchema")(['object1' => ['property1' => 'Hello'], 'property3' => 3]);

        $this->assertSame('Hello', $subObject1->getObject1()->getProperty1());
        $this->assertSame(3, $subObject1->getProperty3());

        $subObject2 = new ("\\{$namespacePrefix}\\CompositionSubFolder2\\SubSchema")(['object1' => ['property1' => 'Goodbye'], 'property3' => true]);

        $this->assertSame('Goodbye', $subObject2->getObject1()->getProperty1());
        $this->assertTrue($subObject2->getProperty3());

        $this->assertSame($subObject1->getObject1()::class, $subObject2->getObject1()::class);
    }

    public function testIdenticalSchemasInCompositionInArrayAreMappedToOneClass(): void
    {
        $this->generateDirectory(
            'IdenticalSubSchemaInCompositionInArray',
            (new GeneratorConfiguration())->setNamespacePrefix('IdenticalSubSchema'),
        );

        $namespacePrefix = $this->lastGeneratedNamespacePrefix;
        $subObject1 = new ("\\{$namespacePrefix}\\CompositionSubFolder1\\SubSchema")(['object1' => [['property1' => 'Hello']], 'property3' => 3]);

        $this->assertSame('Hello', $subObject1->getObject1()[0]->getProperty1());
        $this->assertSame(3, $subObject1->getProperty3());

        $subObject2 = new ("\\{$namespacePrefix}\\CompositionSubFolder2\\SubSchema")(['object1' => [['property1' => 'Goodbye']], 'property3' => true]);

        $this->assertSame('Goodbye', $subObject2->getObject1()[0]->getProperty1());
        $this->assertTrue($subObject2->getProperty3());

        $this->assertSame($subObject1->getObject1()[0]::class, $subObject2->getObject1()[0]::class);
    }

    public function testIdenticalSchemasCombined1AreMappedToOneClass(): void
    {
        $this->generateDirectory(
            'IdenticalSubSchemaCombined1',
            (new GeneratorConfiguration())->setNamespacePrefix('IdenticalSubSchemaCombined1'),
        );

        $namespacePrefix = $this->lastGeneratedNamespacePrefix;
        $subObject1 = new ("\\{$namespacePrefix}\\CompositionSubFolder1\\SubSchema")(['object1' => ['property1' => 'Hello'], 'property3' => 3]);

        $this->assertSame('Hello', $subObject1->getObject1()->getProperty1());
        $this->assertSame(3, $subObject1->getProperty3());

        $subObject2 = new ("\\{$namespacePrefix}\\CompositionSubFolder2\\SubSchema")(['object1' => [['property1' => 'Goodbye']], 'property3' => true]);

        $this->assertSame('Goodbye', $subObject2->getObject1()[0]->getProperty1());
        $this->assertTrue($subObject2->getProperty3());

        $this->assertSame($subObject1->getObject1()::class, $subObject2->getObject1()[0]::class);
    }

    public function testIdenticalSchemasCombined2AreMappedToOneClass(): void
    {
        $this->generateDirectory(
            'IdenticalSubSchemaCombined2',
            (new GeneratorConfiguration())->setNamespacePrefix('IdenticalSubSchemaCombined2'),
        );

        $namespacePrefix = $this->lastGeneratedNamespacePrefix;
        $subObject1 = new ("\\{$namespacePrefix}\\CompositionSubFolder1\\SubSchema")(['object1' => ['property1' => 'Hello']]);

        $this->assertSame('Hello', $subObject1->getObject1()->getProperty1());

        $subObject2 = new ("\\{$namespacePrefix}\\CompositionSubFolder2\\SubSchema")([
            'object1' => [['property1' => 'Goodbye']],
            'property3' => true,
            'extendedProperty' => 'Wow so many compositions',
        ]);

        $this->assertSame('Goodbye', $subObject2->getObject1()[0]->getProperty1());
        $this->assertTrue($subObject2->getProperty3());
        $this->assertSame('Wow so many compositions', $subObject2->getExtendedProperty());

        $this->assertSame($subObject1->getObject1()::class, $subObject2->getObject1()[0]::class);
    }
}
