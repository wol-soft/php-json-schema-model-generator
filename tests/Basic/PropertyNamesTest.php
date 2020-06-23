<?php

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

/**
 * Class PropertyNamesTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class PropertyNamesTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @dataProvider validationMethodDataProvider
     *
     * @param GeneratorConfiguration $generatorConfiguration
     */
    public function testEmptyPropertyNamesAcceptsAllProperties(GeneratorConfiguration $generatorConfiguration): void
    {
        $className = $this->generateClassFromFileTemplate('PropertyNames.json', ['{}'], $generatorConfiguration);

        $object = new $className([
            'myProperty1' => 1,
            '1278371' => 2,
            '__strange - NAMES ()' => 3,
            '#' => 4,
        ]);

        $this->assertSame(1, $object->getRawModelDataInput()['myProperty1']);
        $this->assertSame(2, $object->getRawModelDataInput()['1278371']);
        $this->assertSame(3, $object->getRawModelDataInput()['__strange - NAMES ()']);
        $this->assertSame(4, $object->getRawModelDataInput()['#']);
    }

    /**
     * @dataProvider validPropertyNamesDataProvider
     *
     * @param GeneratorConfiguration $generatorConfiguration
     * @param string $propertyNames
     * @param array $properties
     */
    public function testValidPropertyNames(
        GeneratorConfiguration $generatorConfiguration,
        string $propertyNames,
        array $properties
    ): void {
        $className = $this->generateClassFromFileTemplate(
            'PropertyNames.json',
            [$propertyNames],
            $generatorConfiguration,
            false
        );

        $object = new $className($properties);

        foreach ($properties as $propertyName => $value) {
            $this->assertSame($value, $object->getRawModelDataInput()[$propertyName]);
        }
    }

    public function validPropertyNamesDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'length limitations' => [
                    '{"minLength": 3, "maxLength": 5}',
                    [
                        '123' => 123,
                        '12345' => 12345,
                        'abc' => 1,
                        'ABCDE' => 2,
                        '__+__' => 3,
                    ]
                ],
                'pattern matching' => [
                    '{"pattern": "^test[0-9]+$"}',
                    [
                        'test1' => 1,
                        'test1298398717931793179317937197931' => 2,
                    ],
                ],
            ]
        );
    }

    /**
     * @dataProvider invalidPropertyNamesDataProvider
     * @dataProvider invalidCombinedPropertyNamesDataProvider
     *
     * @param GeneratorConfiguration $generatorConfiguration
     * @param string $propertyNames
     * @param array $properties
     * @param string $exceptionMessage
     */
    public function testInvalidPropertyNamesThrowsAnException(
        GeneratorConfiguration $generatorConfiguration,
        string $propertyNames,
        array $properties,
        string $exceptionMessage
    ): void {
        $className = $this->generateClassFromFileTemplate(
            'PropertyNames.json',
            [$propertyNames],
            $generatorConfiguration,
            false
        );

        $this->expectValidationError($generatorConfiguration, $exceptionMessage);

        new $className($properties);
    }

    public function invalidPropertyNamesDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'length limitation violation' => [
                    '{"minLength": 3, "maxLength": 5}',
                    [
                        '12' => 123,
                        '123456' => 12345,
                        'abc' => 1,
                    ],
                    <<<ERROR
contains properties with invalid names.
  - invalid property '12'
    * Value for property name must not be shorter than 3
  - invalid property '123456'
    * Value for property name must not be longer than 5
ERROR
                ],
                'pattern violation' => [
                    '{"pattern": "^test[0-9]+$"}',
                    [
                        '12test12' => 123,
                        'test' => 12345,
                        'test12' => 1,
                        'test12w12' => 1,
                    ],
                    <<<ERROR
contains properties with invalid names.
  - invalid property '12test12'
    * Value for property name doesn't match pattern ^test[0-9]+$
  - invalid property 'test'
    * Value for property name doesn't match pattern ^test[0-9]+$
  - invalid property 'test12w12'
    * Value for property name doesn't match pattern ^test[0-9]+$
ERROR
                ],
                'multiple violations' => [
                    '{"minLength": 6, "maxLength": 8, "pattern": "^test[0-9]+$"}',
                    [
                        'test12345a' => 123,
                        'test123' => 2,
                        'test' => 1,
                    ],
                    <<<ERROR
contains properties with invalid names.
  - invalid property 'test12345a'
    * Value for property name doesn't match pattern ^test[0-9]+$
  - invalid property 'test'
    * Value for property name doesn't match pattern ^test[0-9]+$
ERROR
                ],
            ]
        );
    }

    public function invalidCombinedPropertyNamesDataProvider(): array
    {
        return [
            'Direct Exception - multiple violations' => [
                (new GeneratorConfiguration())->setCollectErrors(false),
                '{"minLength": 6, "maxLength": 8, "pattern": "^test[0-9]+$"}',
                [
                    'test12345a' => 123,
                    'test123' => 2,
                    'test' => 1,
                ],
                <<<ERROR
contains properties with invalid names.
  - invalid property 'test12345a'
    * Value for property name doesn't match pattern ^test[0-9]+$
  - invalid property 'test'
    * Value for property name doesn't match pattern ^test[0-9]+$
ERROR
            ],
            'Error Collection - multiple violations' => [
                new GeneratorConfiguration(),
                '{"minLength": 6, "maxLength": 8, "pattern": "^test[0-9]+$"}',
                [
                    'test12345a' => 123,
                    'test123' => 2,
                    'test' => 1,
                ],
                <<<ERROR
contains properties with invalid names.
  - invalid property 'test12345a'
    * Value for property name doesn't match pattern ^test[0-9]+$
    * Value for property name must not be longer than 8
  - invalid property 'test'
    * Value for property name doesn't match pattern ^test[0-9]+$
    * Value for property name must not be shorter than 6
ERROR
            ],
        ];
    }
}
