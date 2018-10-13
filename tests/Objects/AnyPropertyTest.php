<?php

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use stdClass;

/**
 * Class AnyPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class AnyPropertyTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalNullPropertyIsValid(): void
    {
        $className = $this->generateObjectFromFile('AnyProperty.json');

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    /**
     * @dataProvider validPropertyTypeDataProvider
     *
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testAnyProvidedTypeIsValid($propertyValue): void
    {
        $className = $this->generateObjectFromFile('AnyProperty.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validPropertyTypeDataProvider(): array
    {
        return [
            'int' => [0],
            'float' => [0.92],
            'bool' => [true],
            'array' => [[]],
            'object' => [new stdClass()],
            'string' => ['null'],
            'null' => [null],
        ];
    }
}
