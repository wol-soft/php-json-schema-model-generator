<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\PostProcessor;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\BuilderClassPostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;

class BuilderClassPostProcessorTest extends AbstractPHPModelGeneratorTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new BuilderClassPostProcessor());
        };
    }

    public function testBuilder(): void
    {
        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setSerialization(true),
            implicitNull: false,
        );

        $this->assertGeneratedBuilders(1);

        $builderClassName = $className . 'Builder';
        $builderObject = new $builderClassName();

        $this->assertNull($builderObject->getName());
        $this->assertNull($builderObject->getAge());

        $builderObject->setName('Albert');
        $builderObject->setAge(65);

        $this->assertSame('Albert', $builderObject->getName());
        $this->assertSame(65, $builderObject->getAge());
        $this->assertEqualsCanonicalizing(['name' => 'Albert', 'age' => 65], $builderObject->getRawModelDataInput());

        $this->assertSame('string', $this->getParameterTypeAnnotation($builderObject, 'setName'));
        $this->assertSame('int', $this->getParameterTypeAnnotation($builderObject, 'setAge'));
        $this->assertSame('string|null', $this->getReturnTypeAnnotation($builderObject, 'getName'));
        $this->assertSame('int|null', $this->getReturnTypeAnnotation($builderObject, 'getAge'));

        $returnType = $this->getReturnType($builderObject, 'getName');
        $this->assertSame('string', $returnType->getName());
        $this->assertTrue($returnType->allowsNull());

        $returnType = $this->getReturnType($builderObject, 'getAge');
        $this->assertSame('int', $returnType->getName());
        $this->assertTrue($returnType->allowsNull());

        $validatedObject = $builderObject->validate();

        $this->assertInstanceOf($className, $validatedObject);
        $this->assertSame('Albert', $validatedObject->getName());
        $this->assertSame(65, $validatedObject->getAge());
        $this->assertEqualsCanonicalizing(['name' => 'Albert', 'age' => 65], $validatedObject->getRawModelDataInput());
        $this->assertEqualsCanonicalizing(['name' => 'Albert', 'age' => 65], $validatedObject->toArray());
    }

    public function testImplicitNull(): void
    {
        $className = $this->generateClassFromFile('BasicSchema.json');

        $builderClassName = $className . 'Builder';
        $builderObject = new $builderClassName();

        $this->assertSame('string', $this->getParameterTypeAnnotation($builderObject, 'setName'));
        $this->assertSame('int|null', $this->getParameterTypeAnnotation($builderObject, 'setAge'));
        $this->assertSame('string|null', $this->getReturnTypeAnnotation($builderObject, 'getName'));
        $this->assertSame('int|null', $this->getReturnTypeAnnotation($builderObject, 'getAge'));
    }

    public function testNestedObject(): void
    {
        $className = $this->generateClassFromFile('NestedObject.json');

        $this->assertGeneratedBuilders(2);

        $builderClassName = $className . 'Builder';
        $builderObject = new $builderClassName();

        $nestedObjectClassName = null;
        foreach ($this->getGeneratedFiles() as $file) {
            if (str_contains($file, 'Address')) {
                $nestedObjectClassName = str_replace('.php', '', basename($file));

                break;
            }
        }

        $this->assertNotEmpty($nestedObjectClassName);
        $expectedTypeHint = "$nestedObjectClassName|{$nestedObjectClassName}Builder|array|null";
        $this->assertSame($expectedTypeHint, $this->getParameterTypeAnnotation($builderObject, 'setAddress'));
        $this->assertSame($expectedTypeHint, $this->getReturnTypeAnnotation($builderObject, 'getAddress'));

        // test generate nested object from array
        $addressArray = ['street' => 'Test street', 'number' => 10];
        $builderObject->setAddress($addressArray);
        $this->assertSame($addressArray, $builderObject->getAddress());
        $this->assertSame(['address' => $addressArray], $builderObject->getRawModelDataInput());
        $object = $builderObject->validate();
        $this->assertSame('Test street', $object->getAddress()->getStreet());
        $this->assertSame(10, $object->getAddress()->getNumber());

        // test generate nested object from nested builder
        $nestedBuilderClassName = $nestedObjectClassName . 'Builder';
        $nestedBuilderObject = new $nestedBuilderClassName();
        $this->assertSame('string|null', $this->getParameterTypeAnnotation($nestedBuilderObject, 'setStreet'));
        $this->assertSame('int|null', $this->getParameterTypeAnnotation($nestedBuilderObject, 'setNumber'));
        $this->assertSame('string|null', $this->getReturnTypeAnnotation($nestedBuilderObject, 'getStreet'));
        $this->assertSame('int|null', $this->getReturnTypeAnnotation($nestedBuilderObject, 'getNumber'));

        $nestedBuilderObject->setStreet('Test street')->setNumber(10);
        $this->assertSame($addressArray, $nestedBuilderObject->getRawModelDataInput());
        $builderObject->setAddress($nestedBuilderObject);
        $this->assertSame($nestedBuilderObject, $builderObject->getAddress());
        $object = $builderObject->validate();
        $this->assertSame('Test street', $object->getAddress()->getStreet());
        $this->assertSame(10, $object->getAddress()->getNumber());

        // test add validated object
        $nestedObject = new $nestedObjectClassName($addressArray);
        $builderObject->setAddress($nestedObject);
        $this->assertSame($nestedObject, $builderObject->getAddress());
        $object = $builderObject->validate();
        $this->assertSame('Test street', $object->getAddress()->getStreet());
        $this->assertSame(10, $object->getAddress()->getNumber());
    }

    private function assertGeneratedBuilders(int $expectedGeneratedBuilders): void
    {
        $dir = sys_get_temp_dir() . '/PHPModelGeneratorTest/Models';
        $files = array_filter(scandir($dir), fn (string $file): bool => str_ends_with($file, 'Builder.php'));

        $this->assertCount($expectedGeneratedBuilders, $files);
    }
}
