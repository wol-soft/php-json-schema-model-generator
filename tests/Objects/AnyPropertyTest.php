<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use stdClass;

/**
 * Class AnyPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class AnyPropertyTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * @dataProvider validationMethodDataProvider
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalAnyPropertyIsValid(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('AnyProperty.json', $configuration);

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    /**
     * @dataProvider validationMethodDataProvider
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedRequiredAnyPropertyThrowsAnException(GeneratorConfiguration $configuration): void
    {
        $this->expectValidationError($configuration, 'Missing required value for property');
        $className = $this->generateClassFromFile('RequiredAnyProperty.json', $configuration);

        new $className([]);
    }

    /**
     * @dataProvider validPropertyTypeDataProvider
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testAnyProvidedTypeIsValid(GeneratorConfiguration $configuration, mixed $propertyValue): void
    {
        $className = $this->generateClassFromFile('AnyProperty.json', $configuration);

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
                'string' => ['null'],
                'null' => [null],
            ],
        );
    }
}
