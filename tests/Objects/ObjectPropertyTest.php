<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use stdClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class ObjectPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class ObjectPropertyTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalObjectPropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('ObjectProperty.json');

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validInputProvider')]
    public function testProvidedObjectPropertyIsValid(?array $input, string $typeCheck): void
    {
        $className = $this->generateClassFromFile('ObjectProperty.json');

        $object = new $className(['property' => $input]);
        $this->assertTrue(('is_' . $typeCheck)($object->getProperty()));
    }

    public static function validInputProvider(): array
    {
        return [
            'Empty object' => [[], 'object'],
            'Object with property' => [['integerProperty' => 1, 'stringProperty' => 'Hello'], 'object'],
            'Null' => [null, 'null'],
        ];
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidPropertyTypeDataProvider')]
    public function testInvalidPropertyTypeThrowsAnException(mixed $propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid type for property. Requires object, got ' . gettype($propertyValue));

        $className = $this->generateClassFromFile('ObjectProperty.json');

        new $className(['property' => $propertyValue]);
    }

    public static function invalidPropertyTypeDataProvider(): array
    {
        return [
            'bool' => [true],
            'float' => [0.92],
            'int' => [2],
            'string' => ['1']
        ];
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidPropertyObjectClassThrowsAnException(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches(
            '/Invalid class for property. Requires ObjectPropertyTest_.*, got stdClass/',
        );

        $className = $this->generateClassFromFile('ObjectProperty.json');

        new $className(['property' => new stdClass()]);
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validInputProviderObjectLevelValidation')]
    public function testObjectLevelValidationApplyForNestedObjectsWithValidInput(?array $input, string $typeCheck):void
    {
        $className = $this->generateClassFromFile('ObjectLevelValidation.json');

        $object = new $className(['property' => $input]);

        $this->assertTrue(('is_' . $typeCheck)($object->getProperty()));

        if ($object->getProperty() !== null) {
            $this->assertSame($input['name'], ($object->getProperty()->getName()));
            $this->assertSame($input['age'] ?? null, ($object->getProperty()->getAge()));
            $this->assertSame($input, ($object->getProperty()->getRawModelDataInput()));
        }
    }

    public static function validInputProviderObjectLevelValidation(): array
    {
        return [
            'Null' => [null, 'null'],
            'Required property, one custom property' => [['name' => 'Hannes', 'country' => 'Germany'], 'object'],
            'Required property, two custom property' => [['name' => 'Hannes', 'country' => 'Germany', 'alive' => true], 'object'],
            'Required property, one defined property' => [['name' => 'Hannes', 'age' => 42], 'object'],
            'Required property, one defined property, one custom property' => [['name' => 'Hannes', 'age' => 42, 'alive' => true], 'object'],
        ];
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidInputProviderObjectLevelValidation')]
    public function testObjectLevelValidationApplyForNestedObjectsWithInvalidInput(
        array $input,
        string $exceptionClass,
        string $exceptionMessage,
    ): void {
        $this->expectException($exceptionClass);
        $this->expectExceptionMessageMatches("/$exceptionMessage/");

        $className = $this->generateClassFromFile('ObjectLevelValidation.json');

        new $className(['property' => $input]);
    }

    public static function invalidInputProviderObjectLevelValidation(): array
    {
        return [
            'Missing required property' => [
                ['age' => 42, 'alive' => true],
                ValidationException::class,
                'Missing required value for name'
            ],
            'Too few arguments' => [
                ['name' => 'Hannes'],
                ValidationException::class,
                'Provided object for ObjectPropertyTest_(.*) must not contain less than 2 properties'
            ],
            'Too many arguments' => [
                ['name' => 'Hannes', 'age' => 42, 'alive' => true, 'children' => 3],
                ValidationException::class,
                'Provided object for ObjectPropertyTest_(.*) must not contain more than 3 properties'
            ],
        ];
    }
}
