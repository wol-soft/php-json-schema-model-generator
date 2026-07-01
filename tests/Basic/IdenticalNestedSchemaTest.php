<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use ReflectionClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class IdenticalNestedSchemaTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class IdenticalNestedSchemaTest extends AbstractPHPModelGeneratorTestCase
{
    public function testIdenticalSchemaInSingleFileAreMappedToOneClass(): void
    {
        $className = $this->generateClassFromFile('IdenticalSubSchema.json');

        $object = new $className([
            'object1' => ['property1' => 'Hello'],
            'object2' => ['property1' => 'Goodbye'],
        ]);

        $this->assertSame('Hello', $object->getObject1()->getProperty1());
        $this->assertSame('Goodbye', $object->getObject2()->getProperty1());

        // Identical inline schemas at different positions now get distinct classes
        // (B5 fix: cache key includes pointer). Both must still produce valid objects.
        $this->assertNotSame($object->getObject1()::class, $object->getObject2()::class);
    }

    public function testIdenticalReferencedSchemaInSingleFileAreMappedToOneClass(): void
    {
        $className = $this->generateClassFromFile('IdenticalReferencedSchema.json');

        $object = new $className([
            'object1' => ['member' => ['name' => 'Hannes', 'age' => 42]],
            'object2' => ['member' => ['name' => 'Frida', 'age' => 24]],
        ]);

        $this->assertSame('Hannes', $object->getObject1()->getMember()->getName());
        $this->assertSame(42, $object->getObject1()->getMember()->getAge());

        $this->assertSame('Frida', $object->getObject2()->getMember()->getName());
        $this->assertSame(24, $object->getObject2()->getMember()->getAge());

        // $ref'd schemas still share a class (same $id pointer)
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
        string $class1FQCN,
        string $class2FQCN,
    ): void {
        $this->generateDirectory(
            $file,
            (new GeneratorConfiguration())
                ->setNamespacePrefix($file)
                ->setOutputEnabled(false),
        );

        $object1 = new $class1FQCN(['member' => ['name' => 'Hannes', 'age' => 42]]);
        $this->assertSame('Hannes', $object1->getMember()->getName());
        $this->assertSame(42, $object1->getMember()->getAge());

        $object2 = new $class2FQCN(['member' => ['name' => 'Frida', 'age' => 24]]);
        $this->assertSame('Frida', $object2->getMember()->getName());
        $this->assertSame(24, $object2->getMember()->getAge());

        $this->assertSame($object1->getMember()::class, $object2->getMember()::class);
    }

    public static function identicalReferencedSchemaDataProvider(): array
    {
        return [
            'In same namespace' => [
                'IdenticalReferencedSchemaInSameNamespace',
                '\\IdenticalReferencedSchemaInSameNamespace\\Schema1',
                '\\IdenticalReferencedSchemaInSameNamespace\\Schema2'
            ],
            'In different namespace' => [
                'IdenticalReferencedSchemaInDifferentNamespace',
                '\\IdenticalReferencedSchemaInDifferentNamespace\\SubFolder1\\Schema',
                '\\IdenticalReferencedSchemaInDifferentNamespace\\SubFolder2\\Schema'
            ],
        ];
    }

    public function testIdenticalSchemasInDifferentNamespaceAreMappedToOneClass(): void
    {
        $this->generateDirectory(
            'IdenticalSubSchemaDifferentNamespace',
            (new GeneratorConfiguration())
                ->setNamespacePrefix('IdenticalSubSchemaDifferentNamespace')
                ->setOutputEnabled(false),
        );

        $subClass1FQCN = '\\IdenticalSubSchemaDifferentNamespace\\DifferentNamespaceSubFolder10\\SubSchema';
        $subObject1 = new $subClass1FQCN(['object1' => ['property1' => 'Hello']]);
        $this->assertSame('Hello', $subObject1->getObject1()->getProperty1());

        $subClass2FQCN = '\\IdenticalSubSchemaDifferentNamespace\\DifferentNamespaceSubFolder20\\SubSchema';
        $subObject2 = new $subClass2FQCN(['object1' => ['property1' => 'Goodbye']]);
        $this->assertSame('Goodbye', $subObject2->getObject1()->getProperty1());

        // Inline schemas at the same relative pointer in different namespaces still
        // share a Schema (same content + same pointer → same cache key).
        $this->assertSame($subObject1->getObject1()::class, $subObject2->getObject1()::class);
    }

    public function testIdenticalSchemasCombined2AreMappedToOneClass(): void
    {
        $this->generateDirectory(
            'IdenticalSubSchemaCombined2',
            (new GeneratorConfiguration())->setNamespacePrefix('IdenticalSubSchemaCombined2')->setOutputEnabled(false),
        );

        $subClass1FQCN = '\\IdenticalSubSchemaCombined2\\CompositionSubFolder1\\SubSchema';
        $subObject1 = new $subClass1FQCN(['object1' => ['property1' => 'Hello']]);

        $this->assertSame('Hello', $subObject1->getObject1()->getProperty1());

        $subClass2FQCN = '\\IdenticalSubSchemaCombined2\\CompositionSubFolder2\\SubSchema';
        $subObject2 = new $subClass2FQCN([
            'object1' => [['property1' => 'Goodbye']],
            'property3' => true,
            'extendedProperty' => 'Wow so many compositions',
        ]);

        $this->assertSame('Goodbye', $subObject2->getObject1()[0]->getProperty1());
        $this->assertTrue($subObject2->getProperty3());
        $this->assertSame('Wow so many compositions', $subObject2->getExtendedProperty());

        // Inline schemas at different composition positions get distinct classes
        // (B5 fix: cache key includes pointer). Both must still produce valid objects.
        $this->assertNotSame($subObject1->getObject1()::class, $subObject2->getObject1()[0]::class);
    }
}
