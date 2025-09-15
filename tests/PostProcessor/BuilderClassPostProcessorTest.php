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

    public function testPopulateMethod(): void
    {
        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setSerialization(true),
        );

        $this->includeGeneratedBuilder(1);

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
        $this->assertSame('int|null', $this->getParameterTypeAnnotation($builderObject, 'setAge'));
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

    private function includeGeneratedBuilder(int $expectedGeneratedBuilders): void
    {
        $dir = sys_get_temp_dir() . '/PHPModelGeneratorTest/Models';
        $files = array_filter(scandir($dir), fn (string $file): bool => str_ends_with($file, 'Builder.php'));

        $this->assertCount($expectedGeneratedBuilders, $files);

        foreach ($files as $file) {
            require_once $dir . DIRECTORY_SEPARATOR . $file;
        }
    }
}
