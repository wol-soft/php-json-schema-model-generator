<?php

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\Object\AdditionalPropertiesException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use stdClass;

/**
 * Class AdditionalPropertiesTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class AdditionalPropertiesTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @dataProvider additionalPropertiesDataProvider
     *
     * @param array $propertyValue
     */
    public function testAdditionalPropertiesAreIgnoredByDefault(array $propertyValue): void
    {
        $className = $this->generateClassFromFile('AdditionalPropertiesNotDefined.json');

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['name'] ?? null, $object->getName());
        $this->assertSame($propertyValue['age'] ?? null, $object->getAge());
    }

    /**
     * @dataProvider additionalPropertiesDataProvider
     *
     * @param array $propertyValue
     */
    public function testAdditionalPropertiesAreIgnoredWhenSetToTrue(array $propertyValue): void
    {
        $className = $this->generateClassFromFileTemplate(
            'AdditionalProperties.json',
            ['true'],
            // make sure the deny additional properties setting doesn't affect specified additional properties
            (new GeneratorConfiguration())->setDenyAdditionalProperties(true)
        );

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['name'] ?? null, $object->getName());
        $this->assertSame($propertyValue['age'] ?? null, $object->getAge());
    }

    public function additionalPropertiesDataProvider():array
    {
        return [
            'all properties plus additional property' => [['name' => 'test', 'age' => 24, 'additional' => 'ignored']],
            'some properties plus additional property' => [['age' => 24, 'additional' => 'ignored']],
            'only additional property' => [['additional' => 'ignored']]
        ];
    }

    /**
     * @dataProvider definedPropertiesDataProvider
     *
     * @param array $propertyValue
     */
    public function testDefinedPropertiesAreAcceptedWhenSetToFalse(array $propertyValue): void
    {
        $className = $this->generateClassFromFileTemplate('AdditionalProperties.json', ['false']);

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['name'] ?? null, $object->getName());
        $this->assertSame($propertyValue['age'] ?? null, $object->getAge());
    }

    public function definedPropertiesDataProvider():array
    {
        return [
            'all properties' => [['name' => 'test', 'age' => 24]],
            'some properties' => [['age' => 24]],
            'no property' => [[]]
        ];
    }

    /**
     * @dataProvider additionalPropertiesDataProvider
     *
     * @param array $propertyValue
     */
    public function testAdditionalPropertiesThrowAnExceptionWhenSetToFalse(array $propertyValue): void
    {
        $this->expectException(AdditionalPropertiesException::class);
        $this->expectExceptionMessageMatches(
            '/Provided JSON for .* contains not allowed additional properties \[additional\]/'
        );

        $className = $this->generateClassFromFileTemplate('AdditionalProperties.json', ['false']);

        new $className($propertyValue);
    }

    /**
     * @dataProvider additionalPropertiesDataProvider
     *
     * @param array $propertyValue
     */
    public function testAdditionalPropertiesThrowAnExceptionWhenNotDefinedAndDeniedByGeneratorConfiguration(
        array $propertyValue
    ): void {
        $this->expectException(AdditionalPropertiesException::class);
        $this->expectExceptionMessageMatches(
            '/Provided JSON for .* contains not allowed additional properties \[additional\]/'
        );

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesNotDefined.json',
            (new GeneratorConfiguration())->setDenyAdditionalProperties(true)->setCollectErrors(false)
        );

        new $className($propertyValue);
    }

    /**
     * @dataProvider validTypedAdditionalPropertiesDataProvider
     *
     * @param GeneratorConfiguration $generatorConfiguration
     * @param array $propertyValue
     */
    public function testValidTypedAdditionalPropertiesAreValid(
        GeneratorConfiguration $generatorConfiguration,
        array $propertyValue
    ): void {
        $className = $this->generateClassFromFile('AdditionalPropertiesTyped.json', $generatorConfiguration);

        $object = new $className($propertyValue);

        $this->assertEquals($propertyValue['id'] ?? null, $object->getId());
        foreach ($propertyValue as $key => $value) {
            $this->assertSame($value, $object->getRawModelDataInput()[$key]);
        }
    }

    public function validTypedAdditionalPropertiesDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'no provided values' => [[]],
                'only defined property' => [['id' => 12]],
                'only additional properties' => [['additional1' => 'AB', 'additional2' => '12345']],
                'defined and additional properties' => [['id' => 10, 'additional1' => 'AB', 'additional2' => '12345']],
            ]
        );
    }

    /**
     * @dataProvider invalidTypedAdditionalPropertiesDataProvider
     *
     * @param GeneratorConfiguration $generatorConfiguration
     * @param array $propertyValue
     * @param string $errorMessage
     */
    public function testInvalidTypedAdditionalPropertiesThrowsAnException(
        GeneratorConfiguration $generatorConfiguration,
        array $propertyValue,
        string $errorMessage
    ): void {
        $this->expectValidationError($generatorConfiguration, $errorMessage);
        $className = $this->generateClassFromFile('AdditionalPropertiesTyped.json', $generatorConfiguration);

        new $className($propertyValue);
    }

    public function invalidTypedAdditionalPropertiesDataProvider(): array
    {
        $exception = <<<ERROR
contains invalid additional properties.
  - invalid additional property 'additional1'
    * %s
ERROR;

        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'invalid type for additional property (null)' => [
                    ['additional1' => null, 'additional2' => 'Hello'],
                    sprintf($exception, 'Invalid type for additional property. Requires string, got NULL')
                ],
                'invalid type for additional property (int)' => [
                    ['additional1' => 1, 'additional2' => 'Hello'],
                    sprintf($exception, 'Invalid type for additional property. Requires string, got int')
                ],
                'invalid type for additional property (float)' => [
                    ['additional1' => 0.92, 'additional2' => 'Hello'],
                    sprintf($exception, 'Invalid type for additional property. Requires string, got double')
                ],
                'invalid type for additional property (bool)' => [
                    ['additional1' => true, 'additional2' => 'Hello'],
                    sprintf($exception, 'Invalid type for additional property. Requires string, got bool')
                ],
                'invalid type for additional property (array)' => [
                    ['additional1' => [], 'additional2' => 'Hello'],
                    sprintf($exception, 'Invalid type for additional property. Requires string, got array')
                ],
                'invalid type for additional property (object)' => [
                    ['additional1' => new stdClass(), 'additional2' => 'Hello'],
                    sprintf($exception, 'Invalid type for additional property. Requires string, got object')
                ],
                'empty short string' => [
                    ['additional1' => '', 'additional2' => 'Hello'],
                    sprintf($exception, 'Value for additional property must not be shorter than 2')
                ],
                'too short string' => [
                    ['additional1' => '1', 'additional2' => 'Hello'],
                    sprintf($exception, 'Value for additional property must not be shorter than 2')
                ],
                'too long string' => [
                    ['additional1' => '12345678', 'additional2' => 'Hello'],
                    sprintf($exception, 'Value for additional property must not be longer than 5')
                ],
            ]
        );
    }

    /**
     * @dataProvider validAdditionalPropertiesObjectsDataProvider
     *
     * @param GeneratorConfiguration $generatorConfiguration
     * @param array $propertyValue
     */
    public function testValidAdditionalPropertiesObjectsAreValid(
        GeneratorConfiguration $generatorConfiguration,
        array $propertyValue
    ): void {
        $className = $this->generateClassFromFile('AdditionalPropertiesObject.json', $generatorConfiguration);

        $object = new $className($propertyValue);

        $this->assertEquals($propertyValue['id'] ?? null, $object->getId());
        foreach ($propertyValue as $key => $value) {
            $this->assertSame($value, $object->getRawModelDataInput()[$key]);
        }
    }

    public function validAdditionalPropertiesObjectsDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'no provided values' => [[]],
                'only defined property' => [['id' => 12]],
                'only additional properties' => [[
                    'additional1' => ['name' => 'AB'],
                    'additional2' => ['name' => 'AB', 'age' => 12],
                ]],
                'defined and additional properties' => [[
                    'id' => 10,
                     'additional1' => ['name' => 'AB'],
                     'additional2' => ['name' => 'AB', 'age' => 12],
                ]],
            ]
        );
    }

    /**
     * @dataProvider invalidAdditionalPropertiesObjectsDataProvider
     *
     * @param GeneratorConfiguration $generatorConfiguration
     * @param array $propertyValue
     * @param string $errorMessage
     */
    public function testInvalidAdditionalPropertiesObjectsThrowsAnException(
        GeneratorConfiguration $generatorConfiguration,
        array $propertyValue,
        string $errorMessage
    ): void {
        $this->expectValidationError($generatorConfiguration, $errorMessage);
        $className = $this->generateClassFromFile('AdditionalPropertiesObject.json', $generatorConfiguration);

        new $className($propertyValue);
    }

    public function invalidAdditionalPropertiesObjectsDataProvider(): array
    {
        $exception = <<<ERROR
contains invalid additional properties.
  - invalid additional property 'additional1'
    * %s
ERROR;

        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'invalid type for additional property (null)' => [
                    ['additional1' => null, 'additional2' => ['name' => 'AB', 'age' => 12]],
                    sprintf($exception, 'Invalid type for additional property. Requires object, got NULL')
                ],
                'invalid type for additional property (int)' => [
                    ['additional1' => 1, 'additional2' => ['name' => 'AB', 'age' => 12]],
                    sprintf($exception, 'Invalid type for additional property. Requires object, got int')
                ],
                'invalid type for additional property (float)' => [
                    ['additional1' => 0.92, 'additional2' => ['name' => 'AB', 'age' => 12]],
                    sprintf($exception, 'Invalid type for additional property. Requires object, got double')
                ],
                'invalid type for additional property (bool)' => [
                    ['additional1' => true, 'additional2' => ['name' => 'AB', 'age' => 12]],
                    sprintf($exception, 'Invalid type for additional property. Requires object, got bool')
                ],
                'invalid type for additional property (object)' => [
                    ['additional1' => 'Hello', 'additional2' => ['name' => 'AB', 'age' => 12]],
                    sprintf($exception, 'Invalid type for additional property. Requires object, got string')
                ],
                'Missing required name' => [
                    ['additional1' => ['age' => 12], 'additional2' => ['name' => 'AB', 'age' => 12]],
                    sprintf($exception, 'Missing required value for name')
                ],
                'Invalid type for name' => [
                    ['additional1' => ['name' => 12], 'additional2' => ['name' => 'AB', 'age' => 12]],
                    sprintf($exception, 'Invalid type for name. Requires string, got integer')
                ],
                'Multiple violations' => [
                    ['additional1' => ['name' => 12], 'additional2' => ['name' => 'AB', 'age' => '12']],
                    <<<ERROR
contains invalid additional properties.
  - invalid additional property 'additional1'
    * Invalid type for name. Requires string, got integer
  - invalid additional property 'additional2'
    * Invalid type for age. Requires int, got string
ERROR
                ],
            ]
        );
    }
}
