<?php

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use stdClass;

/**
 * Class ObjectPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class ObjectPropertyTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalObjectPropertyIsValid(): void
    {
        $className = $this->generateObjectFromFile('ObjectProperty.json');

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    /**
     * @dataProvider validInputProvider
     *
     * @param array  $input
     * @param string $typeCheck
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedObjectPropertyIsValid(?array $input, string $typeCheck): void
    {
        $className = $this->generateObjectFromFile('ObjectProperty.json');

        $object = new $className(['property' => $input]);
        $this->assertTrue(('is_' . $typeCheck)($object->getProperty()));
    }

    public function validInputProvider(): array
    {
        return [
            'Empty object' => [[], 'object'],
            'Object with property' => [['integerProperty' => 1, 'stringProperty' => 'Hello'], 'object'],
            'Null' => [null, 'null'],
        ];
    }

    /**
     * @dataProvider invalidPropertyTypeDataProvider
     *
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidPropertyTypeThrowsAnException($propertyValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid type for property');

        $className = $this->generateObjectFromFile('ObjectProperty.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidPropertyTypeDataProvider(): array
    {
        return [
            'bool' => [true],
            'float' => [0.92],
            'int' => [2],
            'object' => [new stdClass()],
            'string' => ['1']
        ];
    }

    /**
     * @dataProvider validInputProviderObjectLevelValidation
     *
     * @param array  $input
     * @param string $typeCheck
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testObjectLevelValidationApplyForNestedObjectsWithValidInput(?array $input, string $typeCheck):void
    {
        $className = $this->generateObjectFromFile('ObjectLevelValidation.json');

        $object = new $className(['property' => $input]);

        $this->assertTrue(('is_' . $typeCheck)($object->getProperty()));

        if ($object->getProperty() !== null) {
            $this->assertSame($input['name'], ($object->getProperty()->getName()));
            $this->assertSame($input['age'] ?? null, ($object->getProperty()->getAge()));
            $this->assertSame($input, ($object->getProperty()->getRawModelDataInput()));
        }
    }

    public function validInputProviderObjectLevelValidation(): array
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
     * @dataProvider invalidInputProviderObjectLevelValidation
     *
     * @param array  $input
     * @param string $exceptionClass
     * @param string $exceptionMessage
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testObjectLevelValidationApplyForNestedObjectsWithInvalidInput(
        array $input,
        string $exceptionClass,
        string $exceptionMessage
    ): void {
        $this->expectException($exceptionClass);
        $this->expectExceptionMessage($exceptionMessage);

        $className = $this->generateObjectFromFile('ObjectLevelValidation.json');

        new $className(['property' => $input]);
    }

    public function invalidInputProviderObjectLevelValidation(): array
    {
        return [
            'Missing required property' => [
                ['age' => 42, 'alive' => true],
                InvalidArgumentException::class,
                'Missing required value for name'
            ],
            'Too few arguments' => [
                ['name' => 'Hannes'],
                InvalidArgumentException::class,
                'Provided object must not contain less than 2 properties'
            ],
            'Too many arguments' => [
                ['name' => 'Hannes', 'age' => 42, 'alive' => true, 'children' => 3],
                InvalidArgumentException::class,
                'Provided object must not contain more than 3 properties'
            ],
        ];
    }
}