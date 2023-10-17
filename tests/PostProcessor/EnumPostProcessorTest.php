<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\PostProcessor;

use BackedEnum;
use Exception;
use PHPModelGenerator\Exception\Generic\EnumException;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\Object\RequiredValueException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use ReflectionEnum;
use UnitEnum;

class EnumPostProcessorTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @requires PHP < 8.1
     */
    public function testEnumPostProcessorThrowsAnExceptionPriorToPhp81(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Enumerations are only allowed since PHP 8.1');

        new EnumPostProcessor('', '');
    }

    /**
     * @requires PHP >= 8.1
     */
    public function testStringOnlyEnum(): void
    {
        $this->addPostProcessor();

        $className = $this->generateClassFromFileTemplate(
            'EnumProperty.json',
            ['["hans", "dieter"]'],
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setCollectErrors(false)
                ->setSerialization(true),
            false
        );

        $this->includeGeneratedEnums(1);

        $object = new $className(['property' => 'hans', 'stringProperty' => 'abc']);
        $this->assertSame('hans', $object->getProperty()->value);
        $this->assertSame('abc', $object->getStringProperty());
        $this->assertSame(['property' => 'hans', 'stringProperty' => 'abc'], $object->toArray());

        $object->setProperty('dieter');
        $this->assertSame('dieter', $object->getProperty()->value);

        $object->setProperty(null);
        $this->assertNull($object->getProperty());

        $returnType = $this->getReturnType($object, 'getProperty');
        $this->assertTrue($returnType->allowsNull());
        $enum = $returnType->getName();
        $this->assertTrue(enum_exists($enum));

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

    /**
     * @requires PHP >= 8.1
     */
    public function testInvalidStringOnlyEnumValueThrowsAnException(): void
    {
        $this->addPostProcessor();
        $className = $this->generateClassFromFileTemplate('EnumProperty.json', ['["Hans", "Dieter"]'], null, false);
        $this->includeGeneratedEnums(1);

        $this->expectException(EnumException::class);
        $this->expectExceptionMessage('Invalid value for property declined by enum constraint');

        new $className(['property' => 'Meier']);
    }

    public function testInvalidEnumThrowsAnException(): void
    {
        $this->addPostProcessor();

        $className = $this->generateClassFromFileTemplate('EnumProperty.json', ['["Hans", "Dieter"]'], null, false);

        $this->expectException(InvalidTypeException::class);
        $this->expectExceptionMessageMatches(
            '/Invalid type for property\. Requires EnumPostProcessorTest_.*Property, got PHPModelGenerator\\\\Tests\\\\PostProcessor\\\\IntEnum/'
        );

        new $className(['property' => IntEnum::A]);
    }

    /**
     * @requires PHP >= 8.1
     */
    public function testMappedStringOnlyEnum(): void
    {
        $this->addPostProcessor();

        $className = $this->generateClassFromFileTemplate(
            'EnumPropertyMapped.json',
            ['["Hans", "Dieter"]', '{"Ceo": "Hans", "Cto": "Dieter"}'],
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setCollectErrors(false)
                ->setSerialization(true),
            false
        );

        $this->includeGeneratedEnums(1);

        $object = new $className(['property' => 'Hans']);
        $this->assertSame('Hans', $object->getProperty()->value);
        $this->assertSame('Ceo', $object->getProperty()->name);
        $this->assertSame(['property' => 'Hans'], $object->toArray());

        $object->setProperty('Dieter');
        $this->assertSame('Dieter', $object->getProperty()->value);
        $this->assertSame('Cto', $object->getProperty()->name);

        $object->setProperty(null);
        $this->assertNull($object->getProperty());

        $returnType = $this->getReturnType($object, 'getProperty');
        $this->assertTrue($returnType->allowsNull());
        $enum = $returnType->getName();

        $this->assertSame('string', (new ReflectionEnum($enum))->getBackingType()->getName());

        $this->assertEqualsCanonicalizing(
            ['Ceo', 'Cto'],
            array_map(function (BackedEnum $value): string { return $value->name; }, $enum::cases())
        );
        $this->assertEqualsCanonicalizing(
            ['Hans', 'Dieter'],
            array_map(function (BackedEnum $value): string { return $value->value; }, $enum::cases())
        );

        $object->setProperty($enum::Ceo);
        $this->assertSame('Hans', $object->getProperty()->value);
    }

    /**
     * @dataProvider unmappedEnumThrowsAnExceptionDataProvider
     * @requires PHP >= 8.1
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
     * @requires PHP >= 8.1
     */
    public function testUnmappedEnumIsSkippedWithEnabledSkipOption(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(
                new EnumPostProcessor(
                    join(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), 'PHPModelGeneratorTest', 'Enum']),
                    'Enum',
                    true
                )
            );
        };

        $className = $this->generateClassFromFileTemplate('EnumProperty.json', ['[0, 1, 2]'], null, false);

        $this->includeGeneratedEnums(0);

        $object = new $className(['property' => 1]);
        $this->assertSame(1, $object->getProperty());
    }

    /**
     * @dataProvider invalidEnumMapThrowsAnExceptionDataProvider
     * @requires PHP >= 8.1
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

    /**
     * @requires PHP >= 8.1
     */
    public function testIntOnlyEnum(): void
    {
        $this->addPostProcessor();

        $className = $this->generateClassFromFileTemplate(
            'EnumPropertyMapped.json',
            ['[10, 100]', '{"a": 10, "b": 100}'],
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setCollectErrors(false)
                ->setSerialization(true),
            false
        );

        $this->includeGeneratedEnums(1);

        $object = new $className(['property' => 10]);
        $this->assertSame(10, $object->getProperty()->value);
        $this->assertSame(['property' => 10], $object->toArray());

        $object->setProperty(100);
        $this->assertSame(100, $object->getProperty()->value);

        $object->setProperty(null);
        $this->assertNull($object->getProperty());

        $returnType = $this->getReturnType($object, 'getProperty');
        $this->assertTrue($returnType->allowsNull());
        $enum = $returnType->getName();

        $this->assertTrue(enum_exists($enum));
        $reflectionEnum = new ReflectionEnum($enum);
        $enumName = $reflectionEnum->getShortName();

        $this->assertEqualsCanonicalizing(
            [$enumName, 'null'],
            explode('|', $this->getReturnTypeAnnotation($object, 'getProperty'))
        );

        $this->assertSame('int', $reflectionEnum->getBackingType()->getName());

        $this->assertEqualsCanonicalizing(
            ['A', 'B'],
            array_map(function (BackedEnum $value): string { return $value->name; }, $enum::cases())
        );
        $this->assertEqualsCanonicalizing(
            [10, 100],
            array_map(function (BackedEnum $value): int { return $value->value; }, $enum::cases())
        );

        $object->setProperty($enum::A);
        $this->assertSame(10, $object->getProperty()->value);

        $this->assertNull($this->getParameterType($object, 'setProperty'));
        $this->assertEqualsCanonicalizing(
            [$enumName, 'int', 'null'],
            explode('|', $this->getParameterTypeAnnotation($object, 'setProperty'))
        );

        $this->expectException(EnumException::class);
        $this->expectExceptionMessage('Invalid value for property declined by enum constraint');
        $object->setProperty(1);
    }

    /**
     * @requires PHP >= 8.1
     */
    public function testMixedEnum(): void
    {
        $this->addPostProcessor();

        $className = $this->generateClassFromFileTemplate(
            'EnumPropertyMapped.json',
            ['["Hans", 100, true]', '{"a": "Hans", "b": 100, "c": true}'],
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false)->setSerialization(true),
            false
        );

        $this->includeGeneratedEnums(1);

        $object = new $className(['property' => 'Hans']);
        $this->assertSame('Hans', $object->getProperty()->value());
        $this->assertSame(['property' => 'Hans'], $object->toArray());

        $object->setProperty(100);
        $this->assertSame(100, $object->getProperty()->value());
        $this->assertSame(['property' => 100], $object->toArray());

        $object->setProperty(null);
        $this->assertNull($object->getProperty());
        $this->assertSame(['property' => null], $object->toArray());

        $returnType = $this->getReturnType($object, 'getProperty');
        $this->assertTrue($returnType->allowsNull());
        $enum = $returnType->getName();

        $this->assertTrue(enum_exists($enum));
        $reflectionEnum = new ReflectionEnum($enum);
        $enumName = $reflectionEnum->getShortName();

        $this->assertEqualsCanonicalizing(
            [$enumName, 'null'],
            explode('|', $this->getReturnTypeAnnotation($object, 'getProperty'))
        );

        $this->assertNull($reflectionEnum->getBackingType());

        $this->assertEqualsCanonicalizing(
            ['A', 'B', 'C'],
            array_map(function (UnitEnum $value): string { return $value->name; }, $enum::cases())
        );

        $object->setProperty($enum::C);
        $this->assertSame(true, $object->getProperty()->value());

        $this->assertNull($this->getParameterType($object, 'setProperty'));

        $this->assertSame($enum::A, $enum::from('Hans'));
        $this->assertSame($enum::A, $enum::tryFrom('Hans'));
        $this->assertNull($enum::tryFrom('Dieter'));

        $this->expectException(EnumException::class);
        $this->expectExceptionMessage('Invalid value for property declined by enum constraint');
        $object->setProperty(1);
    }

    /**
     * @requires PHP >= 8.1
     */
    public function testEnumPropertyWithTransformingFilterThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage("Can't apply enum filter to an already transformed value");

        $this->addPostProcessor();
        $this->generateClassFromFile('EnumPropertyWithTransformingFilter.json');
    }

    /**
     * @dataProvider identicalEnumsDataProvider
     * @requires PHP >= 8.1
     */
    public function testIdenticalEnumsAreMappedToOneEnum(string $file, array $enums): void
    {
        $this->addPostProcessor();

        $className = $this->generateClassFromFileTemplate(
            $file,
            $enums,
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
            false
        );

        $this->includeGeneratedEnums(1);

        $object = new $className(['property1' => 'Hans', 'property2' => 'Dieter']);
        $this->assertSame('Hans', $object->getProperty1()->value);
        $this->assertSame('Dieter', $object->getProperty2()->value);

        $this->assertSame(get_class($object->getProperty1()), get_class($object->getProperty2()));
    }

    public function identicalEnumsDataProvider(): array
    {
        return [
            'simple enum' => [
                'MultipleEnumProperties.json',
                ['["Hans", "Dieter"]', '["Dieter", "Hans"]'],
            ],
            'mapped enum' => [
                'MultipleEnumPropertiesMapped.json',
                [
                    '"names"', '["Hans", "Dieter"]', '{"a": "Hans", "b": "Dieter"}',
                    '"names"', '["Dieter", "Hans"]', '{"b": "Dieter", "a": "Hans"}',
                ],
            ],
        ];
    }

    /**
     * @dataProvider differentEnumsDataProvider
     * @requires PHP >= 8.1
     */
    public function testDifferentEnumsAreNotMappedToOneEnum(string $file, array $enums): void
    {
        $this->addPostProcessor();

        $className = $this->generateClassFromFileTemplate(
            $file,
            $enums,
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
            false
        );

        $this->includeGeneratedEnums(2);
        $object = new $className(['property1' => 'Hans', 'property2' => 'Dieter']);

        $this->assertSame('Hans', $object->getProperty1()->value);
        $this->assertSame('Dieter', $object->getProperty2()->value);

        $this->assertNotSame(get_class($object->getProperty1()), get_class($object->getProperty2()));
    }

    public function differentEnumsDataProvider(): array
    {
        return [
            'different values' => [
                'MultipleEnumProperties.json',
                ['["Hans", "Dieter"]', '["Dieter", "Anna"]'],
            ],
            'different $id' => [
                'MultipleEnumPropertiesMapped.json',
                [
                    '"names"', '["Hans", "Dieter"]', '{"a": "Hans", "b": "Dieter"}',
                    '"attendees"', '["Hans", "Dieter"]', '{"a": "Hans", "b": "Dieter"}',
                ],
            ],
            'different values mapped enum' => [
                'MultipleEnumPropertiesMapped.json',
                [
                    '"names"', '["Hans", "Anna"]', '{"a": "Hans", "b": "Anna"}',
                    '"names"', '["Hans", "Dieter"]', '{"a": "Hans", "b": "Dieter"}',
                ],
            ],
            'different mapping' => [
                'MultipleEnumPropertiesMapped.json',
                [
                    '"names"', '["Hans", "Dieter"]', '{"a": "Hans", "b": "Dieter"}',
                    '"names"', '["Hans", "Dieter"]', '{"a": "Dieter", "b": "Hans"}',
                ],
            ],
        ];
    }

    /**
     * @requires PHP >= 8.1
     */
    public function testDefaultValue(): void
    {
        $this->addPostProcessor();

        $className = $this->generateClassFromFile('EnumPropertyDefaultValue.json');

        $this->includeGeneratedEnums(1);

        $object = new $className();
        $this->assertSame('Dieter', $object->getProperty()->value);
    }

    /**
     * @requires PHP >= 8.1
     */
    public function testNotProvidedRequiredEnumThrowsAnException(): void
    {
        $this->addPostProcessor();

        $className = $this->generateClassFromFile('EnumPropertyRequired.json');

        $this->includeGeneratedEnums(1);

        $this->expectException(RequiredValueException::class);
        $this->expectExceptionMessage('Missing required value for property');

        new $className();
    }

    /**
     * @requires PHP >= 8.1
     */
    public function testRequiredEnum(): void
    {
        $this->addPostProcessor();

        $className = $this->generateClassFromFile(
            'EnumPropertyRequired.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false)
        );

        $this->includeGeneratedEnums(1);

        $object = new $className(['property' => 'Dieter']);
        $this->assertSame('Dieter', $object->getProperty()->value);

        $returnType = $this->getReturnType($object, 'getProperty');
        $this->assertFalse($returnType->allowsNull());
        $enum = $returnType->getName();

        $reflectionEnum = new ReflectionEnum($enum);
        $enumName = $reflectionEnum->getShortName();

        $this->assertSame($enumName, $this->getReturnTypeAnnotation($object, 'getProperty'));

        $object->setProperty($enum::Hans);
        $this->assertSame('Hans', $object->getProperty()->value);

        $this->assertNull($this->getParameterType($object, 'setProperty'));
        $this->assertEqualsCanonicalizing(
            [$enumName, 'string'],
            explode('|', $this->getParameterTypeAnnotation($object, 'setProperty'))
        );

        $this->expectException(EnumException::class);
        $this->expectExceptionMessage('Invalid value for property declined by enum constraint');

        $object->setProperty(null);
    }

    /**
     * @requires PHP >= 8.1
     */
    public function testEmptyNormalizedCaseNameThrowsAnException(): void
    {
        $this->addPostProcessor();

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage("Name '__ -- __' results in an empty name");

        $this->generateClassFromFileTemplate('EnumProperty.json', ['["__ -- __"]'], null, false);
    }

    /**
     * @dataProvider normalizedNamesDataProvider
     * @requires PHP >= 8.1
     */
    public function testNameNormalization(string $name, string $expectedNormalizedName): void
    {
        $this->addPostProcessor();

        $className = $this->generateClassFromFileTemplate('EnumProperty.json', [sprintf('["%s"]', $name)], null, false);

        $this->includeGeneratedEnums(1);

        $object = new $className();

        $returnType = $this->getReturnType($object, 'getProperty');
        $enum = $returnType->getName();

        $this->assertSame(
            [$expectedNormalizedName],
            array_map(function (BackedEnum $value): string { return $value->name; }, $enum::cases())
        );
    }

    public function normalizedNamesDataProvider(): array
    {
        return [
            'includes spaces' => ['not available', 'NotAvailable'],
            'includes non alphanumeric characters' => ['not-available', 'NotAvailable'],
            'numeric' => ['100', '_100'],
        ];
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
