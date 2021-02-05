<?php

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use stdClass;

/**
 * Class ConstPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class ConstPropertyTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedConstPropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('ConstProperty.json');

        $object = new $className(['stringProperty' => 'MyConstValue', 'integerProperty' => 42]);

        $this->assertSame('MyConstValue', $object->getStringProperty());
        $this->assertSame(42, $object->getIntegerProperty());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedConstPropertyThrowsAnException(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid value for stringProperty declined by const constraint');

        $className = $this->generateClassFromFile('ConstProperty.json');

        new $className([]);
    }

    /**
     * @dataProvider invalidPropertyDataProvider
     *
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotMatchingProvidedDataThrowsAnException($propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid value for stringProperty declined by const constraint');

        $className = $this->generateClassFromFile('ConstProperty.json');

        new $className(['stringProperty' => $propertyValue]);
    }

    public function invalidPropertyDataProvider(): array
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
