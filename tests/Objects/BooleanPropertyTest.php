<?php

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use stdClass;

/**
 * Class BooleanPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class BooleanPropertyTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalBooleanPropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('BooleanProperty.json');

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    /**
     * @dataProvider validInputProvider
     *
     * @param bool $input
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedBooleanPropertyIsValid(?bool $input): void
    {
        $className = $this->generateClassFromFile('BooleanProperty.json');

        $object = new $className(['property' => $input]);
        $this->assertSame($input, $object->getProperty());
    }

    public function validInputProvider(): array
    {
        return [
            'true' => [true],
            'false' => [false],
            'null' => [null],
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
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid type for property. Requires bool, got ' . gettype($propertyValue));

        $className = $this->generateClassFromFile('BooleanProperty.json');

        new $className(['property' => $propertyValue]);
    }

    public function invalidPropertyTypeDataProvider(): array
    {
        return [
            'int' => [0],
            'float' => [0.92],
            'array' => [[]],
            'object' => [new stdClass()],
            'string' => ['true']
        ];
    }
}