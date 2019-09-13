<?php

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGeneratorException\ValidationException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

/**
 * Class RequiredPropertyTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class RequiredPropertyTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @dataProvider validStringPropertyValueProvider
     *
     * @param string $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testRequiredPropertyIsValidIfProvided(string $propertyValue): void
    {
        $className = $this->generateClassFromFile('RequiredStringProperty.json');

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public function validStringPropertyValueProvider(): array
    {
        return [
            'Hello' => ['Hello'],
            'Empty string' => ['']
        ];
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedRequiredPropertyThrowsAnException(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Missing required value for property");

        $className = $this->generateClassFromFile('RequiredStringProperty.json');

        new $className([]);
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNullProvidedForRequiredPropertyThrowsAnException(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("invalid type for property");

        $className = $this->generateClassFromFile('RequiredStringProperty.json');

        new $className(['property' => null]);
    }
}
