<?php

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\ComposedValue\NotException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use PHPModelGenerator\Exception\ValidationException;
use stdClass;

/**
 * TODO: test object level not
 *
 * Class ComposedNotTest
 *
 * @package PHPModelGenerator\Tests\ComposedValue
 */
class ComposedNotTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @dataProvider emptyNotDataProvider
     *
     * @param $propertyValue
     */
    public function testEmptyNotIsInvalid($propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(<<<ERROR
Invalid value for property declined by composition constraint.
  Requires to match none composition element but matched 1 elements.
ERROR
        );

        $className = $this->generateClassFromFile('EmptyNot.json');

        new $className(['property' => $propertyValue]);
    }

    public function emptyNotDataProvider(): array
    {
        return [
            'null' => [null],
            'empty array' => [[]],
            'string' => ['Hello'],
            'int' => [9],
            'array' => [['name' => 'Hannes', 'age' => 42]],
        ];
    }

    public function testNotProvidedOptionalNotOfTypeStringPropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('NotOfType.json');

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    /**
     * @dataProvider validPropertyTypeDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testValidProvidedOptionalNotOfTypeStringPropertyIsValid(
        GeneratorConfiguration $configuration,
        $propertyValue
    ): void {
        $className = $this->generateClassFromFile('NotOfType.json', $configuration);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validPropertyTypeDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'int' => [0],
                'float' => [0.92],
                'bool' => [true],
                'array' => [[]],
                'object' => [new stdClass()],
                'null' => [null],
            ]
        );
    }

    /**
     * @dataProvider invalidPropertyTypeDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param string $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidProvidedOptionalNotOfTypeStringPropertyThrowsAnException(
        GeneratorConfiguration $configuration,
        string $propertyValue
    ): void {
        $this->expectValidationError($configuration, 'Invalid value for property declined by composition constraint');

        $className = $this->generateClassFromFile('NotOfType.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public function invalidPropertyTypeDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'empty string' => [''],
                'numeric string' => ['100'],
                'word string' => ['Hello'],
            ]
        );
    }

    /**
     * @dataProvider validationMethodDataProvider
     *
     * @param GeneratorConfiguration $configuration
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalNotNullPropertyThrowsAnException(GeneratorConfiguration $configuration): void
    {
        $this->expectValidationError($configuration, 'Invalid value for property declined by composition constraint');

        $className = $this->generateClassFromFile('NotNull.json', $configuration);

        new $className([]);
    }

    /**
     * @dataProvider validationMethodDataProvider
     *
     * @param GeneratorConfiguration $configuration
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidProvidedOptionalNotNullPropertyThrowsAnException(
        GeneratorConfiguration $configuration
    ): void {
        $this->expectValidationError($configuration, 'Invalid value for property declined by composition constraint');

        $className = $this->generateClassFromFile('NotNull.json', $configuration);

        new $className(['property' => null]);
    }

    /**
     * @dataProvider validNotNullPropertyDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testValidProvidedOptionalNotNullPropertyIsValid(
        GeneratorConfiguration $configuration,
        $propertyValue
    ): void {
        $className = $this->generateClassFromFile('NotNull.json', $configuration);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validNotNullPropertyDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'int' => [0],
                'float' => [0.92],
                'bool' => [true],
                'array' => [[]],
                'object' => [new stdClass()],
                'string' => [''],
            ]
        );
    }

    /**
     * @dataProvider validExtendedPropertyDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testExtendedPropertyDefinitionWithValidValues(
        GeneratorConfiguration $configuration,
        $propertyValue
    ): void {
        $className = $this->generateClassFromFile('ExtendedPropertyDefinition.json', $configuration);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validExtendedPropertyDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                '11.' => [11.],
                '13.' => [13.],
                '10.5' => [10.5],
            ]
        );
    }

    /**
     * @dataProvider invalidExtendedPropertyDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param $propertyValue
     * @param string $exceptionMessage
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testExtendedPropertyDefinitionWithInvalidValuesThrowsAnException(
        GeneratorConfiguration $configuration,
        $propertyValue,
        string $exceptionMessage
    ): void {
        $this->expectValidationError($configuration, $exceptionMessage);

        $className = $this->generateClassFromFile('ExtendedPropertyDefinition.json', $configuration);

        new $className(['property' => $propertyValue]);
    }

    public function invalidExtendedPropertyDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                '10.' => [10., 'Invalid value for property declined by composition constraint'],
                '12.' => [12., 'Invalid value for property declined by composition constraint'],
                '9.9' => [9.9, 'Value for property must not be smaller than 10'],
                '9.' => [9, 'Value for property must not be smaller than 10'],
                '8.' => [8, 'Value for property must not be smaller than 10'],
                'bool' => [true, 'Invalid type for property'],
                'array' => [[], 'Invalid type for property'],
                'object' => [new stdClass(), 'Invalid type for property'],
                'string' => ['', 'Invalid type for property'],
            ]
        );
    }

    /**
     * @dataProvider validationMethodDataProvider
     *
     * @param GeneratorConfiguration $configuration
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedObjectPropertyWithReferencedSchemaIsValid(
        GeneratorConfiguration $configuration
    ): void {
        $className = $this->generateClassFromFile('ReferencedObjectSchema.json', $configuration);

        $object = new $className([]);
        $this->assertNull($object->getPerson());
    }

    /**
     * @dataProvider objectPropertyWithReferencedSchemaDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotMatchingObjectPropertyWithReferencedSchemaIsValid(
        GeneratorConfiguration $configuration,
        $propertyValue
    ): void {
        $className = $this->generateClassFromFile('ReferencedObjectSchema.json', $configuration);

        $object = new $className(['person' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getPerson());
    }

    public function objectPropertyWithReferencedSchemaDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'null' => [null],
                'int' => [0],
                'float' => [0.92],
                'bool' => [true],
                'object' => [new stdClass()],
                'string' => [''],
                'empty array' => [[]],
                'Missing property' => [['name' => 'Hannes']],
                'Too many properties' => [['name' => 'Hannes', 'age' => 42, 'alive' => true]],
                'Matching object with invalid type' => [['name' => 'Hannes', 'age' => '42']],
                'Matching object with invalid data' => [['name' => 'H', 'age' => 42]],
            ]
        );
    }

    /**
     * @dataProvider validationMethodDataProvider
     *
     * @param GeneratorConfiguration $configuration
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testMatchingObjectPropertyWithReferencedSchemaThrowsAnException(
        GeneratorConfiguration $configuration
    ): void {
        $this->expectValidationError($configuration, 'Invalid value for person declined by composition constraint');

        $className = $this->generateClassFromFile('ReferencedObjectSchema.json', $configuration);

        new $className(['person' => ['name' => 'Hannes', 'age' => 42]]);
    }

    /**
     * @dataProvider validationInSetterDataProvider
     *
     * @param GeneratorConfiguration $generatorConfiguration
     * @param string $exceptionMessage
     */
    public function testComposedNotValidationInSetterMethods(
        GeneratorConfiguration $generatorConfiguration,
        string $exceptionMessage
    ): void {
        $className = $this->generateClassFromFile(
            'NotOfType.json',
            $generatorConfiguration->setImmutable(false)
        );

        $object = new $className(['property' => 2]);

        // test a valid change
        $object->setProperty(false);
        $this->assertFalse($object->getProperty());

        // test an invalid change
        try {
            $object->setProperty('Hello');
            $this->fail('Exception not thrown');
        } catch (ErrorRegistryException | NotException $exception) {
            $this->assertStringContainsString($exceptionMessage, $exception->getMessage());
        }

        // make sure the internal state of the object hasn't changed after invalid accesses
        $this->assertFalse($object->getProperty());

        // test valid changes again to make sure the internal validation state is correct after invalid accesses
        $object->setProperty(6);
        $this->assertSame(6, $object->getProperty());
    }

    public function validationInSetterDataProvider(): array
    {
        return [
            'Exception Collection' => [
                (new GeneratorConfiguration())->setCollectErrors(true),
                <<<ERROR
Invalid value for property declined by composition constraint.
  Requires to match none composition element but matched 1 elements.
  - Composition element #1: Valid
ERROR
                ,
            ],
            'Direct Exception' => [
                (new GeneratorConfiguration())->setCollectErrors(false),
                <<<ERROR
Invalid value for property declined by composition constraint.
  Requires to match none composition element but matched 1 elements.
ERROR
            ],
        ];
    }
}
