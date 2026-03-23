<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\Object\RequiredValueException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #98: When a nested object property has its own anyOf/oneOf whose branches add no new
 * named properties (only constraints such as `required`, `minProperties`, `maxProperties`, or
 * `patternProperties`), the generated getter in the parent class has the wrong PHPDoc type hint:
 * it references a `_Merged_` auxiliary class instead of the correct nested-object class.
 *
 * The actual PHP return type is generated correctly; only the PHPDoc annotation is wrong.
 *
 * Root cause: `ObjectProcessor` correctly sets the property type to the nested class, but
 * `AbstractPropertyProcessor::addComposedValueValidator` subsequently calls
 * `SchemaProcessor::createMergedProperty` (because `rootLevelComposition=false` for a
 * non-root property) and appends the `_Merged_` class name as a type-hint decorator,
 * overriding the correct annotation.
 */
class Issue98Test extends AbstractIssueTestCase
{
    // -------------------------------------------------------------------------
    // anyOf with required-only branches
    // -------------------------------------------------------------------------

    /**
     * The getter for a nested object property must have a type hint that refers to the
     * nested object class, not to a `_Merged_` auxiliary class.
     */
    public function testGetterReturnTypeAnnotationDoesNotContainMergedClassForAnyOfRequired(): void
    {
        $className = $this->generateClassFromFile('nestedObjectWithAnyOfRequired.json');

        $returnAnnotation = $this->getReturnTypeAnnotation($className, 'getBudgetRange');
        $this->assertStringNotContainsString(
            '_Merged_',
            $returnAnnotation,
            "getBudgetRange() @return annotation must not reference a _Merged_ class",
        );
    }

    /**
     * The property @var annotation must also refer to the nested object class, not to a
     * `_Merged_` class.
     */
    public function testPropertyVarAnnotationDoesNotContainMergedClassForAnyOfRequired(): void
    {
        $className = $this->generateClassFromFile('nestedObjectWithAnyOfRequired.json');

        $varAnnotation = $this->getPropertyTypeAnnotation($className, 'budgetRange');
        $this->assertStringNotContainsString(
            '_Merged_',
            $varAnnotation,
            "budgetRange @var annotation must not reference a _Merged_ class",
        );
    }

    /**
     * The PHP return type of the getter must be consistent with its @return annotation.
     */
    public function testGetterReturnTypeAndAnnotationAreConsistentForAnyOfRequired(): void
    {
        $className = $this->generateClassFromFile('nestedObjectWithAnyOfRequired.json');

        $returnAnnotation = $this->getReturnTypeAnnotation($className, 'getBudgetRange');
        $returnTypeNames  = $this->getReturnTypeNames($className, 'getBudgetRange');

        foreach ($returnTypeNames as $typeName) {
            if ($typeName === 'null') {
                continue;
            }
            $this->assertStringContainsString(
                $typeName,
                $returnAnnotation,
                "PHP return type '$typeName' must appear in @return annotation",
            );
        }
    }

    #[DataProvider('validAnyOfRequiredDataProvider')]
    public function testValidInputForAnyOfRequiredIsAccepted(array $budgetRange, ?string $currency): void
    {
        $className = $this->generateClassFromFile('nestedObjectWithAnyOfRequired.json');

        $object = new $className(['budget_range' => $budgetRange]);
        $range  = $object->getBudgetRange();

        $this->assertNotNull($range);
        $this->assertSame($currency, $range->getCurrency());
    }

    public static function validAnyOfRequiredDataProvider(): array
    {
        return [
            'min and currency'      => [['min' => 10.0, 'currency' => 'USD'], 'USD'],
            'max and currency'      => [['max' => 100.0, 'currency' => 'EUR'], 'EUR'],
            'min, max and currency' => [['min' => 10.0, 'max' => 100.0, 'currency' => 'GBP'], 'GBP'],
        ];
    }

    public function testNullOrMissingBudgetRangeIsAccepted(): void
    {
        $className = $this->generateClassFromFile('nestedObjectWithAnyOfRequired.json');

        $this->assertNull((new $className([]))->getBudgetRange());
        $this->assertNull((new $className(['budget_range' => null]))->getBudgetRange());
    }

    #[DataProvider('invalidAnyOfRequiredDataProvider')]
    public function testInvalidInputForAnyOfRequiredThrowsException(array $input): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('nestedObjectWithAnyOfRequired.json');

        new $className($input);
    }

    public static function invalidAnyOfRequiredDataProvider(): array
    {
        return [
            'currency only — neither min nor max'     => [['budget_range' => ['currency' => 'USD']]],
            'min only — currency missing'             => [['budget_range' => ['min' => 10.0]]],
            'max only — currency missing'             => [['budget_range' => ['max' => 100.0]]],
            'empty budget_range'                      => [['budget_range' => []]],
            'additional property rejected' => [
                ['budget_range' => ['min' => 1.0, 'currency' => 'USD', 'extra' => true]],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // oneOf with required-only branches (oneOf is also MergedComposedPropertiesInterface)
    // -------------------------------------------------------------------------

    public function testGetterReturnTypeAnnotationDoesNotContainMergedClassForOneOfRequired(): void
    {
        $className = $this->generateClassFromFile('nestedObjectWithOneOfRequired.json');

        $returnAnnotation = $this->getReturnTypeAnnotation($className, 'getBudgetRange');
        $this->assertStringNotContainsString(
            '_Merged_',
            $returnAnnotation,
            "getBudgetRange() @return annotation must not reference a _Merged_ class for oneOf",
        );
    }

    #[DataProvider('validOneOfRequiredDataProvider')]
    public function testValidInputForOneOfRequiredIsAccepted(array $budgetRange, string $currency): void
    {
        $className = $this->generateClassFromFile('nestedObjectWithOneOfRequired.json');

        $object = new $className(['budget_range' => $budgetRange]);
        $range  = $object->getBudgetRange();

        $this->assertNotNull($range);
        $this->assertSame($currency, $range->getCurrency());
    }

    public static function validOneOfRequiredDataProvider(): array
    {
        return [
            'min and currency' => [['min' => 10.0, 'currency' => 'USD'], 'USD'],
            'max and currency' => [['max' => 100.0, 'currency' => 'EUR'], 'EUR'],
        ];
    }

    public function testNullOrMissingBudgetRangeIsAcceptedForOneOf(): void
    {
        $className = $this->generateClassFromFile('nestedObjectWithOneOfRequired.json');

        $this->assertNull((new $className([]))->getBudgetRange());
    }

    #[DataProvider('invalidOneOfRequiredDataProvider')]
    public function testInvalidInputForOneOfRequiredThrowsException(array $input): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('nestedObjectWithOneOfRequired.json');

        new $className($input);
    }

    public static function invalidOneOfRequiredDataProvider(): array
    {
        return [
            'currency only — neither min nor max'  => [['budget_range' => ['currency' => 'USD']]],
            'both min and max match both branches' => [
                ['budget_range' => ['min' => 1.0, 'max' => 5.0, 'currency' => 'USD']],
            ],
            'empty budget_range'                   => [['budget_range' => []]],
        ];
    }

    // -------------------------------------------------------------------------
    // anyOf with size-constraint-only branches (minProperties / maxProperties)
    // -------------------------------------------------------------------------

    public function testGetterReturnTypeAnnotationDoesNotContainMergedClassForAnyOfSizeConstraints(): void
    {
        $className = $this->generateClassFromFile('nestedObjectWithAnyOfSizeConstraints.json');

        $returnAnnotation = $this->getReturnTypeAnnotation($className, 'getConfig');
        $this->assertStringNotContainsString(
            '_Merged_',
            $returnAnnotation,
            "getConfig() @return annotation must not reference a _Merged_ class for anyOf size constraints",
        );
    }

    public function testValidInputForAnyOfSizeConstraintsIsAccepted(): void
    {
        $className = $this->generateClassFromFile('nestedObjectWithAnyOfSizeConstraints.json');

        $object = new $className(['config' => ['name' => 'foo', 'value' => 'bar']]);
        $this->assertSame('foo', $object->getConfig()->getName());
        $this->assertSame('bar', $object->getConfig()->getValue());
    }

    // -------------------------------------------------------------------------
    // anyOf with patternProperties-only branch
    // -------------------------------------------------------------------------

    public function testGetterReturnTypeAnnotationDoesNotContainMergedClassForAnyOfPatternProperties(): void
    {
        $className = $this->generateClassFromFile('nestedObjectWithAnyOfPatternProperties.json');

        $returnAnnotation = $this->getReturnTypeAnnotation($className, 'getConfig');
        $this->assertStringNotContainsString(
            '_Merged_',
            $returnAnnotation,
            "getConfig() @return annotation must not reference a _Merged_ class for anyOf patternProperties",
        );
    }

    public function testValidInputForAnyOfPatternPropertiesIsAccepted(): void
    {
        $className = $this->generateClassFromFile('nestedObjectWithAnyOfPatternProperties.json');

        $object = new $className(['config' => ['name' => 'test']]);
        $this->assertSame('test', $object->getConfig()->getName());
    }
}
