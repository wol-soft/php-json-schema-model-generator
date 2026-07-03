<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class ComposedIfTest
 *
 * @package PHPModelGenerator\Tests\ComposedValue
 */
#[ApplicableDrafts]
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
        $className = $this->generateClassFromFile('ConditionalPropertyDefinition.json');

        try {
            new $className(['property' => $value]);
            $this->fail('Expected ConditionalException');
        } catch (ConditionalException $exception) {
            $this->assertSame($expectedExceptionMessage, $exception->getMessage());
            $this->assertSame('/properties/property/if', $exception->getJsonPointer()->pointer);
        }
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
                ERROR,
            ],
            'invalid positive else' => [
                50,
                <<<ERROR
                Invalid value for property declined by conditional composition constraint
                  - Condition: Failed
                    * Value for property must not be smaller than 100
                  - Conditional branch failed:
                    * Value for property must be a multiple of 30
                ERROR,
            ],
            'invalid positive then' => [
                120,
                <<<ERROR
                Invalid value for property declined by conditional composition constraint
                  - Condition: Valid
                  - Conditional branch failed:
                    * Value for property must be a multiple of 50
                ERROR,
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

    /**
     * A property-level if/then/else where the property has no declared type and both then and
     * else branches declare distinct types. The effective property type is the union of the two
     * branch types (anyOf-like semantics: either branch can fire at runtime).
     */
    public function testPropertyLevelIfThenElseWidensTypeToUnionOfBranches(): void
    {
        $className = $this->generateClassFromFile(
            'PropertyLevelIfThenElseTypeWidening.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'null'],
            $this->getReturnTypeNames($className, 'getProperty'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'null'],
            $this->getParameterTypeNames($className, 'setProperty'),
        );

        // Non-negative integer: if passes, then applies
        $object = new $className(['property' => 5]);
        $this->assertSame(5, $object->getProperty());

        // Non-empty string: if fails (not integer), else applies
        $object = new $className(['property' => 'hello']);
        $this->assertSame('hello', $object->getProperty());

        // Negative integer: if passes (integer), then (minimum: 0) fails
        $this->expectException(ConditionalException::class);
        new $className(['property' => -1]);
    }

    /**
     * A property-level if/then/else where else is absent. The absent else branch accepts any
     * value when if evaluates to false, making the composition unconstraining on that path.
     * The property type must remain mixed (not widened to the then branch type only).
     */
    public function testPropertyLevelIfThenWithAbsentElseProducesMixedType(): void
    {
        $className = $this->generateClassFromFile(
            'PropertyLevelIfThenElseAbsentElse.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertSame('mixed', $this->getReturnType($className, 'getProperty')->getName());
        $this->assertSame('mixed', $this->getParameterType($className, 'setProperty')->getName());
    }

    /**
     * A property-level if/then/else where the parent declares type:string but both then and
     * else explicitly declare types incompatible with string. No value can satisfy the parent
     * type constraint together with whichever branch fires — the schema is unsatisfiable.
     */
    public function testPropertyLevelIfThenElseConflictingBranchTypesThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/Property 'property' has an if\/then\/else composition branch with a type incompatible/",
        );

        $this->generateClassFromFile('PropertyLevelIfThenElseConflictingTypes.json');
    }

    /**
     * When a then or else branch declares {type: null}, its non-null type intersection with a
     * non-null parent (e.g. string) is empty — no value can be both a string and null. The
     * conflict is detectable at generation time, so SchemaException is thrown immediately.
     */
    public function testIfThenElseNullTypedBranchConflictsWithNonNullParentThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/Property 'property' has an if\/then\/else composition branch with a type incompatible/",
        );

        $this->generateClassFromFile('IfThenElseNullTypedBranch.json');
    }

    /**
     * A property-level if/then/else where the parent declares type:string, then declares
     * {type: integer}, and else declares {type: string}. The then branch has an empty
     * intersection with the parent type — no string value can be an integer. This demonstrates
     * that the conflict detector is not null-specific: any incompatible type triggers the error.
     */
    public function testIfThenElseDeadThenBranchWithNonNullTypeConflictThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/Property 'property' has an if\/then\/else composition branch with a type incompatible/",
        );

        $this->generateClassFromFile('IfThenElseDeadThenBranchNonNull.json');
    }

    /**
     * A property-level if/then/else where the parent declares type:["string","null"], then
     * declares {type: null}, and else declares {type: string}. The then branch declares null,
     * and null IS in the parent's type set — the intersection is non-empty, so this schema is
     * valid and generation succeeds without throwing.
     */
    public function testIfThenElseNullableParentWithNullTypedBranchGeneratesSuccessfully(): void
    {
        $className = $this->generateClassFromFile(
            'IfThenElseNullableParentNullTypedBranch.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        // null is a valid value for the property (else branch: {type: string} accepts non-null,
        // but the parent type permits null; if condition fires only on string values)
        $object = new $className([]);
        $this->assertNull($object->getProperty());

        $object = new $className(['property' => null]);
        $this->assertNull($object->getProperty());
    }

    /**
     * A property-level if/then/else where the parent declares type:integer and the then/else
     * branches add only numeric constraints (no explicit type). The branches inherit the parent
     * type; the property type must remain int (not widened).
     */
    public function testPropertyLevelIfThenElseWithCompatibleBranchesPreservesParentType(): void
    {
        $className = $this->generateClassFromFile(
            'PropertyLevelIfThenElseParentTypePreserved.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getProperty'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getParameterTypeNames($className, 'setProperty'),
        );

        // Even non-negative integer: if passes, then (multipleOf 2) applies
        $object = new $className(['property' => 4]);
        $this->assertSame(4, $object->getProperty());

        // Negative integer: if fails, else (maximum -1) applies
        $object = new $className(['property' => -3]);
        $this->assertSame(-3, $object->getProperty());

        // Odd non-negative integer: if passes (≥ 0), then (multipleOf 2) fails
        $this->expectException(ConditionalException::class);
        new $className(['property' => 3]);
    }
}
