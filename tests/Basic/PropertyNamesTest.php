<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class PropertyNamesTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
#[ApplicableDrafts]
class PropertyNamesTest extends AbstractPHPModelGeneratorTestCase
{
    #[DataProvider('validationMethodDataProvider')]
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

    #[DataProvider('validPropertyNamesDataProvider')]
    public function testValidPropertyNames(
        GeneratorConfiguration $generatorConfiguration,
        string $propertyNames,
        array $properties,
    ): void {
        $className = $this->generateClassFromFileTemplate(
            'PropertyNames.json',
            [$propertyNames],
            $generatorConfiguration,
            false,
        );

        $object = new $className($properties);

        foreach ($properties as $propertyName => $value) {
            $this->assertSame($value, $object->getRawModelDataInput()[$propertyName]);
        }
    }

    public static function validPropertyNamesDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
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
                'const' => [
                    '{"const": "test"}',
                    [
                        'test' => 1,
                    ],
                ],
            ],
        );
    }

    #[DataProvider('invalidPropertyNamesDataProvider')]
    #[DataProvider('invalidCombinedPropertyNamesDataProvider')]
    public function testInvalidPropertyNamesThrowsAnException(
        GeneratorConfiguration $generatorConfiguration,
        string $propertyNames,
        array $properties,
        string $exceptionMessage,
    ): void {
        $className = $this->generateClassFromFileTemplate(
            'PropertyNames.json',
            [$propertyNames],
            $generatorConfiguration,
            false,
        );

        $this->expectValidationError($generatorConfiguration, $exceptionMessage);

        new $className($properties);
    }

    public static function invalidPropertyNamesDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
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
                    ERROR,
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
                    ERROR,
                ],
                'const violation' => [
                    '{"const": "test"}',
                    [
                        'test1' => 1,
                        'test' => 2,
                        'bla' => 3,
                    ],
                    <<<ERROR
                    contains properties with invalid names.
                      - invalid property 'test1'
                        * Invalid value for property name declined by const constraint
                      - invalid property 'bla'
                        * Invalid value for property name declined by const constraint
                    ERROR,
                ],
            ],
        );
    }

    public static function invalidCombinedPropertyNamesDataProvider(): array
    {
        return [
            'Direct Exception - combined multiple violations' => [
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
                ERROR,
            ],
            'Error Collection - combined multiple violations' => [
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
                ERROR,
            ],
        ];
    }

    public static function collidingPropertyPairProvider(): array
    {
        return [
            // kebab-case and camelCase normalize to the same attribute
            'foo-bar and fooBar' => ['KebabAndCamelCase.json', 'foo-bar', 'fooBar', 'fooBar'],
            // underscore_case and camelCase normalize to the same attribute
            'foo_bar and fooBar' => ['UnderscoreAndCamelCase.json', 'foo_bar', 'fooBar', 'fooBar'],
            // dot.notation and camelCase normalize to the same attribute
            'foo.bar and fooBar' => ['DotAndCamelCase.json', 'foo.bar', 'fooBar', 'fooBar'],
            // leading underscore is stripped by the normalizer
            '_foo and foo' => ['LeadingUnderscoreAndPlain.json', '_foo', 'foo', 'foo'],
            // multiple separators still collapse to the same attribute as a single separator
            'foo--bar and foo_bar' => ['MultipleSeparators.json', 'foo--bar', 'foo_bar', 'fooBar'],
        ];
    }

    #[DataProvider('collidingPropertyPairProvider')]
    public function testCollidingPropertyNamesThrowSchemaException(
        string $schemaFile,
        string $firstRawName,
        string $secondRawName,
        string $expectedAttribute,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            sprintf(
                "/^Property names '%s' and '%s' both normalize to attribute '%s' in file .+\.json$/",
                preg_quote($firstRawName, '/'),
                preg_quote($secondRawName, '/'),
                preg_quote($expectedAttribute, '/'),
            ),
        );

        $this->generateClassFromFile($schemaFile);
    }

    public function testAllOfCompositionReusingTheSameRawNameDoesNotTriggerCollisionDetection(): void
    {
        // The same raw property name arriving via an allOf branch is a merge, not a new insertion —
        // it must not be treated as a collision.
        $className = $this->generateClassFromFile('AllOfSameProperty.json');
        $object = new $className(['name' => 'Alice']);

        $this->assertSame('Alice', $object->getName());
    }

    public function testNumericPropertyNamesWithDifferentNormalizedFormsDoNotCollide(): void
    {
        // '1st' and '1st-thing' both start with a digit but normalize to different attributes
        // ('1st' vs '1stThing'), so no collision exception must be thrown.
        $className = $this->generateClassFromFile('NumericNamesNoFalsePositive.json');
        $object = new $className(['1st' => 'first', '1st-thing' => 'thing']);

        $this->assertSame('first', $object->get1st());
        $this->assertSame('thing', $object->get1stThing());
    }

    public function testInvalidConstPropertyNamesThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/Invalid const property name in file/');

        $this->generateClassFromFileTemplate('PropertyNames.json', ['{"const": false}'], escape: false);
    }

    #[DataProvider('nonStringPropertyNamesTypeDataProvider')]
    public function testNonStringTypeInPropertyNamesThrowsSchemaException(string $type): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/Invalid type '$type' for propertyNames schema in file/",
        );

        $this->generateClassFromFileTemplate(
            'PropertyNames.json',
            [sprintf('{"type": "%s"}', $type)],
            escape: false,
        );
    }

    public static function nonStringPropertyNamesTypeDataProvider(): array
    {
        return [
            'integer' => ['integer'],
            'number'  => ['number'],
            'boolean' => ['boolean'],
            'array'   => ['array'],
            'object'  => ['object'],
            'null'    => ['null'],
        ];
    }
}
