<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use stdClass;

/**
 * Class ConstPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class ConstPropertyTest extends AbstractPHPModelGeneratorTestCase
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
        ];
    }

    /**
     * @dataProvider invalidRequiredAndOptionalConstPropertiesDataProvider
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotMatchingRequiredAndOptionalProvidedDataThrowsAnException(
        string $reqPropertyValue,
        ?string $optPropertyValue,
        string $exceptionMessage
    ): void
    {
        $className = $this->generateClassFromFile('RequiredAndOptionalConstProperties.json');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($exceptionMessage);

        new $className(['requiredProperty' => $reqPropertyValue, 'optionalProperty' => $optPropertyValue]);
    }

    public function invalidRequiredAndOptionalConstPropertiesDataProvider(): array
    {
        return [
            ['blue', 'green', 'Invalid value for requiredProperty declined by const constraint'],
            ['blue', null, 'Invalid value for requiredProperty declined by const constraint'],
            ['red', 'blue', 'Invalid value for optionalProperty declined by const constraint'],
            ['red', '0', 'Invalid value for optionalProperty declined by const constraint'],
            ['red', '', 'Invalid value for optionalProperty declined by const constraint'],
        ];
    }
}
