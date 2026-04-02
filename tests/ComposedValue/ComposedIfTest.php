<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class ComposedIfTest
 *
 * @package PHPModelGenerator\Tests\ComposedValue
 */
class ComposedIfTest extends AbstractPHPModelGeneratorTestCase
{
    #[DataProvider('conditionalKeywordsDataProvider')]
    public function testIncompleteConditionalsOnPropertyLevelResolveToProperties(string $keyword): void
    {
        $className = $this->generateClassFromFileTemplate('IncompleteConditionalOnPropertyLevel.json', [$keyword]);

        $object = new $className([$keyword => 'Hello']);

        $getter = 'get' . ucfirst($keyword);
        $this->assertSame('Hello', $object->$getter());
    }

    public static function conditionalKeywordsDataProvider(): array
    {
        return [
            'if' => ['if'],
            'then' => ['then'],
            'else' => ['else'],
        ];
    }

    #[DataProvider('validConditionalPropertyDefinitionDataProvider')]
    public function testConditionalPropertyDefinition(int $value): void
    {
        $className = $this->generateClassFromFile('ConditionalPropertyDefinition.json');

        $object = new $className(['property' => $value]);
        $this->assertSame($value, $object->getProperty());
        $this->assertPropertyHasJsonPointer($object, 'property', '/properties/property');
    }

    public static function validConditionalPropertyDefinitionDataProvider(): array
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

    #[DataProvider('invalidConditionalPropertyDefinitionDataProvider')]
    public function testInvalidConditionalPropertyDefinition(int $value, string $expectedExceptionMessage): void
    {
        $this->expectException(ConditionalException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $className = $this->generateClassFromFile('ConditionalPropertyDefinition.json');
        new $className(['property' => $value]);
    }

    public static function invalidConditionalPropertyDefinitionDataProvider(): array
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

    #[DataProvider('validConditionalObjectPropertyDataProvider')]
    public function testConditionalObjectProperty(
        string $schemaFile,
        GeneratorConfiguration $configuration,
        ?string $streetAddress,
        ?string $country,
        ?string $postalCode,
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

    public static function objectLevelConditionalSchemaDataProvider(): array
    {
        return [
            'Object top level conditional composition' => ['ConditionalObjectProperty.json'],
            'Conditional composition nested in another composition' => ['NestedIfInComposition.json'],
        ];
    }

    public static function validConditionalObjectPropertyDataProvider(): array
    {
        return self::combineDataProvider(
            static::objectLevelConditionalSchemaDataProvider(),
            self::combineDataProvider(
                self::validationMethodDataProvider(),
                [
                    'not provided postal code' => ['1600 Pennsylvania Avenue NW', 'USA', null],
                    'USA postal code' => ['1600 Pennsylvania Avenue NW', 'USA', '20500'],
                    'Canada postal code' => ['24 Sussex Drive', 'Canada', 'K1M 1M4'],
                ],
            )
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidConditionalObjectPropertyDataProvider')]
    public function testInvalidConditionalObjectPropertyThrowsAnException(
        string $schemaFile,
        GeneratorConfiguration $configuration,
        ?string $streetAddress,
        ?string $country,
        ?string $postalCode,
    ): void {
        $this->expectValidationErrorRegExp(
            $configuration,
            '/(Invalid value for .*? declined by composition constraint|postal_code doesn\'t match pattern .*)/',
        );

        $className = $this->generateClassFromFile($schemaFile, $configuration);

        new $className([
            'street_address' => $streetAddress,
            'country' => $country,
            'postal_code' => $postalCode,
        ]);
    }

    public static function invalidConditionalObjectPropertyDataProvider(): array
    {
        return self::combineDataProvider(
            static::objectLevelConditionalSchemaDataProvider(),
            self::combineDataProvider(
                self::validationMethodDataProvider(),
                [
                    'empty provided postal code' => ['1600 Pennsylvania Avenue NW', 'USA', ''],
                    'Canadian postal code for USA' => ['1600 Pennsylvania Avenue NW', 'USA', 'K1M 1M4'],
                    'USA postal code for Canada' => ['24 Sussex Drive', 'Canada', '20500'],
                    'Unmatching postal code for both' => ['24 Sussex Drive', 'Canada', 'djqwWDJId8juw9duq9'],
                ],
            )
        );
    }

    public function testIncompleteCompositionThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Incomplete conditional composition for property');

        $this->generateClassFromFile('IncompleteConditional.json');
    }

    public function testCrossTypedThenElseProducesUnionHint(): void
    {
        $className = $this->generateClassFromFile(
            'CrossTypedThenElse.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    public function testCrossTypedThenOnlyProducesNullableHint(): void
    {
        $className = $this->generateClassFromFile(
            'CrossTypedThenOnly.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertSame(
            ['int', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        $this->assertSame(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    public function testSameTypeIsNotWidened(): void
    {
        $className = $this->generateClassFromFile(
            'SameTypeThenElse.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertSame(
            ['int', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        $this->assertSame(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    public function testIfOnlyPropertyIsTransferredToParentSchema(): void
    {
        $className = $this->generateClassFromFile(
            'IfOnlyProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        // 'qualifier' is defined only in the if block — it must be transferred as a nullable mixed
        // property because the if condition can receive any additional property value when the
        // then branch applies (then allows additional properties).
        $this->assertSame('mixed', $this->getReturnType($className, 'getQualifier')->getName());
        $this->assertSame('mixed', $this->getParameterType($className, 'setQualifier')->getName());

        // 'value' is from the then-only branch with no other data branch — not widened
        $this->assertSame(['int', 'null'], $this->getReturnTypeNames($className, 'getValue'));
        $this->assertSame(['int', 'null'], $this->getParameterTypeNames($className, 'setValue'));

        // Both properties are accessible from a constructed object
        $object = new $className(['qualifier' => 'test', 'value' => 42]);
        $this->assertSame('test', $object->getQualifier());
        $this->assertSame(42, $object->getValue());
        $this->assertPropertyHasJsonPointer($object, 'qualifier', '/if/properties/qualifier');
        $this->assertPropertyHasJsonPointer($object, 'value', '/then/properties/value');
    }

    public function testExclusiveBranchPropertiesAreTransferred(): void
    {
        $className = $this->generateClassFromFile(
            'ExclusiveBranchProperties.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        // 'kind' is only in if block — transferred as mixed (then allows additional properties)
        $this->assertSame('mixed', $this->getReturnType($className, 'getKind')->getName());

        // 'amount' is exclusive to then; else allows additional properties — widened to mixed
        $this->assertSame('mixed', $this->getReturnType($className, 'getAmount')->getName());

        // 'label' is exclusive to else; then allows additional properties — widened to mixed
        $this->assertSame('mixed', $this->getReturnType($className, 'getLabel')->getName());

        // All properties are accessible
        $object = new $className(['kind' => 'numeric', 'amount' => 5, 'label' => 'hello']);
        $this->assertSame('numeric', $object->getKind());
        $this->assertSame(5, $object->getAmount());
        $this->assertSame('hello', $object->getLabel());
        $this->assertPropertyHasJsonPointer($object, 'kind', '/if/properties/kind');
        $this->assertPropertyHasJsonPointer($object, 'amount', '/then/properties/amount');
        $this->assertPropertyHasJsonPointer($object, 'label', '/else/properties/label');
    }
}
