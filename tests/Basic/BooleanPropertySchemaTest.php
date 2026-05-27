<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class BooleanPropertySchemaTest extends AbstractPHPModelGeneratorTestCase
{
    #[DataProvider('invalidFalsePropertySchemaDataProvider')]
    public function testInvalidFalsePropertySchemaThrowsSchemaException(
        string $schemaFile,
        string $expectedMessagePattern,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches($expectedMessagePattern);
        $this->generateClassFromFile($schemaFile);
    }

    public static function invalidFalsePropertySchemaDataProvider(): array
    {
        return [
            // property denied by boolean false but also listed in required[]
            'required false property' => [
                'RequiredFalseProperty.json',
                "/^Property 'forbidden' is denied \(schema false\) but also listed as required in file/",
            ],
            // property denied by boolean false but also has dependencies defined
            'false property with dependency' => [
                'FalsePropertyWithDependency.json',
                "/^Property 'forbidden' is denied \(schema false\) but also has dependencies defined in file/",
            ],
        ];
    }

    public function testNotProvidingFalsePropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('FalseProperty.json');

        $object = new $className(['name' => 'Alice']);
        $this->assertSame('Alice', $object->getName());

        // No getter is generated for the denied property
        $this->assertFalse(method_exists($className, 'getForbidden'));
    }

    #[DataProvider('falsePropertyValueDataProvider')]
    public function testProvidingFalsePropertyThrowsException(
        GeneratorConfiguration $configuration,
        mixed $value,
    ): void {
        $this->expectValidationError($configuration, 'Value for forbidden is not allowed');
        $className = $this->generateClassFromFile('FalseProperty.json', $configuration);
        new $className(['forbidden' => $value]);
    }

    public static function falsePropertyValueDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            self::anyValueDataProvider(),
        );
    }

    public static function anyValueDataProvider(): array
    {
        return [
            'string' => ['hello'],
            'int'    => [42],
            'null'   => [null],
            'bool'   => [true],
            'array'  => [[]],
        ];
    }

    #[DataProvider('anyValueDataProvider')]
    public function testTruePropertyAcceptsAnyValue(mixed $value): void
    {
        $className = $this->generateClassFromFile('TrueProperty.json');
        $object = new $className(['anything' => $value]);
        $this->assertSame($value, $object->getAnything());
    }

    public function testTruePropertyGetterHasMixedReturnType(): void
    {
        $className = $this->generateClassFromFile('TrueProperty.json');
        $returnType = $this->getReturnType($className, 'getAnything');
        $this->assertNotNull($returnType);
        $this->assertSame('mixed', $returnType->getName());
    }

    public function testTruePropertyHonoursDependency(): void
    {
        $className = $this->generateClassFromFile('TruePropertyWithDependency.json');

        // Absent entirely — valid
        $object = new $className([]);
        $this->assertNull($object->getAnything());

        // Providing 'other' alone without 'anything' — valid
        $object = new $className(['other' => 'hello']);
        $this->assertSame('hello', $object->getOther());

        // Providing both — valid
        $object = new $className(['anything' => 42, 'other' => 'hello']);
        $this->assertSame(42, $object->getAnything());
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testTruePropertyDependencyIsEnforced(GeneratorConfiguration $configuration): void
    {
        $this->expectValidationError($configuration, "Missing required attributes which are dependants of anything");

        $className = $this->generateClassFromFile('TruePropertyWithDependency.json', $configuration);
        new $className(['anything' => 'hello']);
    }
}
