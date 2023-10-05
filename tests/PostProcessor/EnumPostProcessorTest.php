<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\PostProcessor;

use BackedEnum;
use PHPModelGenerator\Exception\Generic\EnumException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

class EnumPostProcessorTest extends AbstractPHPModelGeneratorTest
{
    public function setUp(): void
    {
        if (PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Enumerations are only allowed since PHP 8.1');
        }

        parent::setUp();
    }

    public function testStringOnlyEnum(): void
    {
        $this->addPostProcessor();

        $className = $this->generateClassFromFileTemplate(
            'EnumProperty.json',
            ['["hans", "dieter"]'],
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
            false
        );

        $this->includeGeneratedEnums(1);

        $object = new $className(['property' => 'hans']);
        $this->assertSame('hans', $object->getProperty()->value);

        $object->setProperty('dieter');
        $this->assertSame('dieter', $object->getProperty()->value);

        $object->setProperty(null);
        $this->assertNull($object->getProperty());

        $returnType = $this->getReturnType($object, 'getProperty');
        $this->assertTrue($returnType->allowsNull());
        $enum = $returnType->getName();

        $this->assertEqualsCanonicalizing(
            [basename($enum), 'null'],
            explode('|', $this->getReturnTypeAnnotation($object, 'getProperty'))
        );

        $this->assertTrue(enum_exists($enum));

        $this->assertEqualsCanonicalizing(
            ['Hans', 'Dieter'],
            array_map(function (BackedEnum $value): string { return $value->name; }, $enum::cases())
        );
        $this->assertEqualsCanonicalizing(
            ['hans', 'dieter'],
            array_map(function (BackedEnum $value): string { return $value->value; }, $enum::cases())
        );

        $object->setProperty($enum::Dieter);
        $this->assertSame('dieter', $object->getProperty()->value);

        $this->assertNull($this->getParameterType($object, 'setProperty'));
        $this->assertEqualsCanonicalizing(
            [basename($enum), 'string', 'null'],
            explode('|', $this->getParameterTypeAnnotation($object, 'setProperty'))
        );

        $this->expectException(EnumException::class);
        $this->expectExceptionMessage('Invalid value for property declined by enum constraint');
        $object->setProperty('Meier');
    }

    public function testInvalidStringOnlyEnumValue(): void
    {
        $this->addPostProcessor();
        $className = $this->generateClassFromFileTemplate('EnumProperty.json', ['["Hans", "Dieter"]'], null, false);
        $this->includeGeneratedEnums(1);

        $this->expectException(EnumException::class);
        $this->expectExceptionMessage('Invalid value for property declined by enum constraint');

        new $className(['property' => 'Meier']);
    }

    public function testEnumPropertyWithTransformingFilterThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage("Can't apply enum filter to an already transformed value");

        $this->addPostProcessor();
        $this->generateClassFromFile('EnumPropertyWithTransformingFilter.json');
    }

    private function addPostProcessor(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(
                new EnumPostProcessor(
                    join(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), 'PHPModelGeneratorTest', 'Enum']),
                    'Enum'
                )
            );
        };
    }

    private function includeGeneratedEnums(int $expectedGeneratedEnums): void
    {
        $dir = sys_get_temp_dir() . '/PHPModelGeneratorTest/Enum';
        $files = array_diff(scandir($dir), ['.', '..']);

        $this->assertCount($expectedGeneratedEnums, $files);

        foreach ($files as $file) {
            require_once $dir . DIRECTORY_SEPARATOR . $file;
        }
    }
}
