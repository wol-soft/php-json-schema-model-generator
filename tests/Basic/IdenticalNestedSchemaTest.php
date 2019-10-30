<?php

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use ReflectionClass;

/**
 * Class IdenticalNestedSchemaTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class IdenticalNestedSchemaTest extends AbstractPHPModelGeneratorTest
{
    public function testIdenticalSchemaInSingleFileAreMappedToOneClass(): void
    {
        $className = $this->generateClassFromFile('IdenticalSubSchema.json');

        $reflection = new ReflectionClass($className);

        $this->assertSame(
            $reflection->getProperty('object1')->getDocComment(),
            $reflection->getProperty('object2')->getDocComment()
        );

        $object = new $className([
            'object1' => ['property1' => 'Hello'],
            'object2' => ['property1' => 'Goodbye'],
        ]);

        $this->assertSame('Hello', $object->getObject1()->getProperty1());
        $this->assertSame('Goodbye', $object->getObject2()->getProperty1());

        $this->assertSame(get_class($object->getObject1()), get_class($object->getObject2()));
    }

    public function testIdenticalReferencedSchemaInSingleFileAreMappedToOneClass(): void
    {
        $className = $this->generateClassFromFile('IdenticalReferencedSchema.json');

        $reflection = new ReflectionClass($className);

        $this->assertSame(
            $reflection->getProperty('object1')->getDocComment(),
            $reflection->getProperty('object2')->getDocComment()
        );

        $object = new $className([
            'object1' => ['member' => ['name' => 'Hannes', 'age' => 42]],
            'object2' => ['member' => ['name' => 'Frida', 'age' => 24]],
        ]);

        $this->assertSame('Hannes', $object->getObject1()->getMember()->getName());
        $this->assertSame(42, $object->getObject1()->getMember()->getAge());

        $this->assertSame('Frida', $object->getObject2()->getMember()->getName());
        $this->assertSame(24, $object->getObject2()->getMember()->getAge());

        $this->assertSame(get_class($object->getObject1()->getMember()), get_class($object->getObject2()->getMember()));
    }

    /**
     * @dataProvider identicalReferencedSchemaDataProvider
     *
     * @param string $file
     * @param string $class1FQCN
     * @param string $class2FQCN
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testIdenticalReferencedSchemaInMultipleFilesAreMappedToOneClass(
        string $file,
        string $class1FQCN,
        string $class2FQCN
    ): void {
        $this->generateDirectory(
            $file,
            (new GeneratorConfiguration())
                ->setNamespacePrefix($file)
                ->setOutputEnabled(false)
        );

        $object1 = new $class1FQCN(['member' => ['name' => 'Hannes', 'age' => 42]]);
        $this->assertSame('Hannes', $object1->getMember()->getName());
        $this->assertSame(42, $object1->getMember()->getAge());

        $object2 = new $class2FQCN(['member' => ['name' => 'Frida', 'age' => 24]]);
        $this->assertSame('Frida', $object2->getMember()->getName());
        $this->assertSame(24, $object2->getMember()->getAge());

        $this->assertSame(get_class($object1->getMember()), get_class($object2->getMember()));
    }

    public function identicalReferencedSchemaDataProvider()
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
                ->setOutputEnabled(false)
        );

        $subClass1FQCN = '\\IdenticalSubSchemaDifferentNamespace\\DifferentNamespaceSubFolder1\\SubSchema';
        $subObject1 = new $subClass1FQCN(['object1' => ['property1' => 'Hello']]);
        $this->assertSame('Hello', $subObject1->getObject1()->getProperty1());

        $subClass2FQCN = '\\IdenticalSubSchemaDifferentNamespace\\DifferentNamespaceSubFolder2\\SubSchema';
        $subObject2 = new $subClass2FQCN(['object1' => ['property1' => 'Goodbye']]);
        $this->assertSame('Goodbye', $subObject2->getObject1()->getProperty1());

        $this->assertSame(get_class($subObject1->getObject1()), get_class($subObject2->getObject1()));
    }

    public function testIdenticalSchemasInArraysAreMappedToOneClass(): void
    {
        $this->generateDirectory(
            'IdenticalSubSchemaInArray',
            (new GeneratorConfiguration())->setNamespacePrefix('IdenticalSubSchemaInArray')->setOutputEnabled(false)
        );

        $subClass1FQCN = '\\IdenticalSubSchemaInArray\\ArraySubFolder1\\SubSchema';
        $subObject1 = new $subClass1FQCN(['object1' => [['property1' => 'Hello']]]);
        $this->assertSame('Hello', $subObject1->getObject1()[0]->getProperty1());

        $subClass2FQCN = '\\IdenticalSubSchemaInArray\\ArraySubFolder2\\SubSchema';
        $subObject2 = new $subClass2FQCN(['object1' => [['property1' => 'Goodbye']]]);
        $this->assertSame('Goodbye', $subObject2->getObject1()[0]->getProperty1());

        $this->assertSame(get_class($subObject1->getObject1()[0]), get_class($subObject2->getObject1()[0]));
    }

    public function testIdenticalSchemasInCompositionAreMappedToOneClass(): void
    {
        ob_start();

        $this->generateDirectory(
            'IdenticalSubSchemaInComposition',
            (new GeneratorConfiguration())->setNamespacePrefix('IdenticalSubSchemaInComposition')
        );

        $output = ob_get_contents();
        ob_end_clean();

        // check for output warnings/messages
        foreach ([
             '/(.*)Generated class IdenticalSubSchemaInComposition\\\CompositionSubFolder1\\\SubSchema(.*)/m',
             '/(.*)Rendered class IdenticalSubSchemaInComposition\\\CompositionSubFolder1\\\SubSchema(.*)/m',
             '/(.*)Duplicated signature (.*) for class (.*) Redirecting to(.*)/m',
             '/(.*)Warning: empty composition for property2 may lead to unexpected results(.*)/m',
         ] as $message
        ) {
            $this->assertRegExp($message, $output);
        }

        $subClass1FQCN = '\\IdenticalSubSchemaInComposition\\CompositionSubFolder1\\SubSchema';
        $subObject1 = new $subClass1FQCN(['object1' => ['property1' => 'Hello'], 'property3' => 3]);

        $this->assertSame('Hello', $subObject1->getObject1()->getProperty1());
        $this->assertSame(3, $subObject1->getProperty3());

        $subClass2FQCN = '\\IdenticalSubSchemaInComposition\\CompositionSubFolder2\\SubSchema';
        $subObject2 = new $subClass2FQCN(['object1' => ['property1' => 'Goodbye'], 'property3' => true]);

        $this->assertSame('Goodbye', $subObject2->getObject1()->getProperty1());
        $this->assertSame(true, $subObject2->getProperty3());

        $this->assertSame(get_class($subObject1->getObject1()), get_class($subObject2->getObject1()));
    }

    public function testIdenticalSchemasInCompositionInArrayAreMappedToOneClass(): void
    {
        $this->generateDirectory(
            'IdenticalSubSchemaInCompositionInArray',
            (new GeneratorConfiguration())->setNamespacePrefix('IdenticalSubSchema')->setOutputEnabled(false)
        );

        $subClass1FQCN = '\\IdenticalSubSchema\\CompositionSubFolder1\\SubSchema';
        $subObject1 = new $subClass1FQCN(['object1' => [['property1' => 'Hello']], 'property3' => 3]);

        $this->assertSame('Hello', $subObject1->getObject1()[0]->getProperty1());
        $this->assertSame(3, $subObject1->getProperty3());

        $subClass2FQCN = '\\IdenticalSubSchema\\CompositionSubFolder2\\SubSchema';
        $subObject2 = new $subClass2FQCN(['object1' => [['property1' => 'Goodbye']], 'property3' => true]);

        $this->assertSame('Goodbye', $subObject2->getObject1()[0]->getProperty1());
        $this->assertSame(true, $subObject2->getProperty3());

        $this->assertSame(get_class($subObject1->getObject1()[0]), get_class($subObject2->getObject1()[0]));
    }

    public function testIdenticalSchemasCombined1AreMappedToOneClass(): void
    {
        $this->generateDirectory(
            'IdenticalSubSchemaCombined1',
            (new GeneratorConfiguration())->setNamespacePrefix('IdenticalSubSchemaCombined1')->setOutputEnabled(false)
        );

        $subClass1FQCN = '\\IdenticalSubSchemaCombined1\\CompositionSubFolder1\\SubSchema';
        $subObject1 = new $subClass1FQCN(['object1' => ['property1' => 'Hello'], 'property3' => 3]);

        $this->assertSame('Hello', $subObject1->getObject1()->getProperty1());
        $this->assertSame(3, $subObject1->getProperty3());

        $subClass2FQCN = '\\IdenticalSubSchemaCombined1\\CompositionSubFolder2\\SubSchema';
        $subObject2 = new $subClass2FQCN(['object1' => [['property1' => 'Goodbye']], 'property3' => true]);

        $this->assertSame('Goodbye', $subObject2->getObject1()[0]->getProperty1());
        $this->assertSame(true, $subObject2->getProperty3());

        $this->assertSame(get_class($subObject1->getObject1()), get_class($subObject2->getObject1()[0]));
    }

    public function testIdenticalSchemasCombined2AreMappedToOneClass(): void
    {
        $this->generateDirectory(
            'IdenticalSubSchemaCombined2',
            (new GeneratorConfiguration())->setNamespacePrefix('IdenticalSubSchemaCombined2')->setOutputEnabled(false)
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
        $this->assertSame(true, $subObject2->getProperty3());
        $this->assertSame('Wow so many compositions', $subObject2->getExtendedProperty());

        $this->assertSame(get_class($subObject1->getObject1()), get_class($subObject2->getObject1()[0]));
    }
}
