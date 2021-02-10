<?php

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

/**
 * Class ComposedIfTest
 *
 * @package PHPModelGenerator\Tests\ComposedValue
 */
class ComposedIfTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @dataProvider conditionalKeywordsDataProvider
     *
     * @param string $keyword
     */
    public function testIncompleteConditionalsOnPropertyLevelResolveToProperties(string $keyword): void
    {
        $className = $this->generateClassFromFileTemplate('IncompleteConditionalOnPropertyLevel.json', [$keyword]);

        $object = new $className([$keyword => 'Hello']);

        $getter = 'get' . ucfirst($keyword);
        $this->assertSame('Hello', $object->$getter());
    }

    public function conditionalKeywordsDataProvider(): array
    {
        return [
            'if' => ['if'],
            'then' => ['then'],
            'else' => ['else'],
        ];
    }

    /**
     * @dataProvider validConditionalPropertyDefinitionDataProvider
     * @param int $value
     */
    public function testConditionalPropertyDefinition(int $value): void
    {
        $className = $this->generateClassFromFile('ConditionalPropertyDefinition.json');

        $object = new $className(['property' => $value]);
        $this->assertSame($value, $object->getProperty());
    }

    public function validConditionalPropertyDefinitionDataProvider(): array
    {
        return [
            'zero' => [0],
            'negative multiple of else' => [-30],
            'positive multiple of else 1' => [30],
            'positive multiple of else 2' => [60],
            'exactly on minimum' => [100],
            'positive multiple of then' => [150],
        ];
    }

    /**
     * @dataProvider invalidConditionalPropertyDefinitionDataProvider
     *
     * @param int $value
     * @param string $expectedExceptionMessage
     */
    public function testInvalidConditionalPropertyDefinition(int $value, string $expectedExceptionMessage): void {
        $this->expectException(ConditionalException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $className = $this->generateClassFromFile('ConditionalPropertyDefinition.json');
        new $className(['property' => $value]);
    }

    public function invalidConditionalPropertyDefinitionDataProvider(): array
    {
        return [
            'invalid negative' => [
                -50,
                <<<ERROR
Invalid value for property declined by conditional composition constraint
  - Condition: Failed
    * Value for property must not be smaller than 100
  - Conditional branch failed:
    * Value for property must be a multiple of 30
ERROR
            ],
            'invalid positive else' => [
                50,
                <<<ERROR
Invalid value for property declined by conditional composition constraint
  - Condition: Failed
    * Value for property must not be smaller than 100
  - Conditional branch failed:
    * Value for property must be a multiple of 30
ERROR
            ],
            'invalid positive then' => [
                120,
                <<<ERROR
Invalid value for property declined by conditional composition constraint
  - Condition: Valid
  - Conditional branch failed:
    * Value for property must be a multiple of 50
ERROR
            ],
        ];
    }

    /**
     * @dataProvider validConditionalObjectPropertyDataProvider
     *
     * @param string $schemaFile
     * @param GeneratorConfiguration $configuration
     * @param string|null $streetAddress
     * @param string|null $country
     * @param string|null $postalCode
     */
    public function testConditionalObjectProperty(
        string $schemaFile,
        GeneratorConfiguration $configuration,
        ?string $streetAddress,
        ?string $country,
        ?string $postalCode
    ): void {
        $className = $this->generateClassFromFile($schemaFile, $configuration);

        $object = new $className([
            'street_address' => $streetAddress,
            'country' => $country,
            'postal_code' => $postalCode,
        ]);

        $this->assertSame($streetAddress, $object->getStreetAddress());
        $this->assertSame($country, $object->getCountry());
        $this->assertSame($postalCode, $object->getPostalCode());
    }

    public function objectLevelConditionalSchemaDataProvider(): array
    {
        return [
            'Object top level conditional composition' => ['ConditionalObjectProperty.json'],
            'Conditional composition nested in another composition' => ['NestedIfInComposition.json'],
        ];
    }

    public function validConditionalObjectPropertyDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->objectLevelConditionalSchemaDataProvider(),
            $this->combineDataProvider(
                $this->validationMethodDataProvider(),
                [
                    'not provided postal code' => ['1600 Pennsylvania Avenue NW', 'USA', null],
                    'USA postal code' => ['1600 Pennsylvania Avenue NW', 'USA', '20500'],
                    'Canada postal code' => ['24 Sussex Drive', 'Canada', 'K1M 1M4'],
                ]
            )
        );
    }

    /**
     * @dataProvider invalidConditionalObjectPropertyDataProvider
     *
     * @param string $schemaFile
     * @param GeneratorConfiguration $configuration
     * @param string|null $streetAddress
     * @param string|null $country
     * @param string|null $postalCode
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidConditionalObjectPropertyThrowsAnException(
        string $schemaFile,
        GeneratorConfiguration $configuration,
        ?string $streetAddress,
        ?string $country,
        ?string $postalCode
    ): void {
        $this->expectValidationErrorRegExp(
            $configuration,
            '/(Invalid value for .*? declined by composition constraint|postal_code doesn\'t match pattern .*)/'
        );

        $className = $this->generateClassFromFile($schemaFile, $configuration);

        new $className([
            'street_address' => $streetAddress,
            'country' => $country,
            'postal_code' => $postalCode,
        ]);
    }

    public function invalidConditionalObjectPropertyDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->objectLevelConditionalSchemaDataProvider(),
            $this->combineDataProvider(
                $this->validationMethodDataProvider(),
                [
                    'empty provided postal code' => ['1600 Pennsylvania Avenue NW', 'USA', ''],
                    'Canadian postal code for USA' => ['1600 Pennsylvania Avenue NW', 'USA', 'K1M 1M4'],
                    'USA postal code for Canada' => ['24 Sussex Drive', 'Canada', '20500'],
                    'Unmatching postal code for both' => ['24 Sussex Drive', 'Canada', 'djqwWDJId8juw9duq9'],
                ]
            )
        );
    }

    public function testIncompleteCompositionThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Incomplete conditional composition for property');

        $this->generateClassFromFile('IncompleteConditional.json');
    }
}
