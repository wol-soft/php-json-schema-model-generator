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
use ReflectionClass;
use ReflectionEnum;

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
        $reflectionEnum = new ReflectionEnum($enum);
        $enumName = $reflectionEnum->getShortName();

        $this->assertEqualsCanonicalizing(
            [$enumName, 'null'],
            explode('|', $this->getReturnTypeAnnotation($object, 'getProperty'))
        );

        $this->assertSame('string', $reflectionEnum->getBackingType()->getName());

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
            [$enumName, 'string', 'null'],
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

    public function testMappedStringOnlyEnum(): void
    {
        $this->addPostProcessor();

        $className = $this->generateClassFromFileTemplate(
            'EnumPropertyMapped.json',
            ['["Hans", "Dieter"]', '{"CEO": "Hans", "CTO": "Dieter"}'],
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
            false
        );

        $this->includeGeneratedEnums(1);

        $object = new $className(['property' => 'Hans']);
        $this->assertSame('Hans', $object->getProperty()->value);
        $this->assertSame('CEO', $object->getProperty()->name);

        $object->setProperty('Dieter');
        $this->assertSame('Dieter', $object->getProperty()->value);
        $this->assertSame('CTO', $object->getProperty()->name);

        $object->setProperty(null);
        $this->assertNull($object->getProperty());

        $returnType = $this->getReturnType($object, 'getProperty');
        $this->assertTrue($returnType->allowsNull());
        $enum = $returnType->getName();

        $this->assertSame('string', (new ReflectionEnum($enum))->getBackingType()->getName());

        $this->assertEqualsCanonicalizing(
            ['CEO', 'CTO'],
            array_map(function (BackedEnum $value): string { return $value->name; }, $enum::cases())
        );
        $this->assertEqualsCanonicalizing(
            ['Hans', 'Dieter'],
            array_map(function (BackedEnum $value): string { return $value->value; }, $enum::cases())
        );

        $object->setProperty($enum::CEO);
        $this->assertSame('Hans', $object->getProperty()->value);
        $this->assertSame('CEO', $object->getProperty()->name);
    }

    /**
     * @dataProvider unmappedEnumThrowsAnExceptionDataProvider
     */
    public function testUnmappedEnumThrowsAnException(string $enumValues): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage("Unmapped enum property in file");

        $this->addPostProcessor();

        $this->generateClassFromFileTemplate('EnumProperty.json', [$enumValues], null, false);
    }

    public function unmappedEnumThrowsAnExceptionDataProvider(): array
    {
        return [
            'int enum'                         => ['[0, 1, 2]'],
            'mixed enum with string values'    => ['["dieter", 1, "hans"]'],
            'mixed enum without string values' => ['[0, 1, false, true]'],
        ];
    }

    /**
     * @dataProvider invalidEnumMapThrowsAnExceptionDataProvider
     */
    public function testInvalidEnumMapThrowsAnException(string $enumValues, string $enumMap): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage("invalid enum map property in file");

        $this->addPostProcessor();

        $this->generateClassFromFileTemplate('EnumPropertyMapped.json', [$enumValues, $enumMap], null, false);
    }

    public function invalidEnumMapThrowsAnExceptionDataProvider(): array
    {
        return [
            'invalid map (int)'                 => ['[0, 1, 2]',       '100'],
            'invalid map (array)'               => ['[0, 1, 2]',       '[0, 1, 2]'],
            'missing mapped elements (int)'     => ['[0, 1, 2]',       '{"a": 0, "b": 1}'],
            'too many mapped elements (int)'    => ['[0, 1]',          '{"a": 0, "b": 1, "c": 2}'],
            'wrong elements mapped (int)'       => ['[0, 1]',          '{"a": 0, "c": 2}'],
            'missing mapped elements (string)'  => ['["a", "b", "c"]', '{"x": "a", "y": "b"}'],
            'too many mapped elements (string)' => ['["a", "b"]',      '{"x": "a", "y": "b", "z": "c"}'],
            'wrong elements mapped (string)'    => ['["a", "b"]',      '{"x": "a", "y": "c"}'],
        ];
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
