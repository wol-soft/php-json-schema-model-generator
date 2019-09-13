<?php

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGeneratorException\ValidationException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

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
        $className = $this->generateClassFromFileTemplate('AdditionalProperties.json', ['true']);

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
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Provided JSON contains not allowed additional properties');

        $className = $this->generateClassFromFileTemplate('AdditionalProperties.json', ['false']);

        new $className($propertyValue);
    }
}
