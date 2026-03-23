<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #101: When a nested object property has a `type: object` declaration together with
 * `anyOf` branches that each introduce **distinct named properties**, the generated parent class
 * places a `ComposedPropertyValidator` directly on the property's own `validateXxx()` method.
 *
 * By the time that validator runs, `$value` has already been transformed into an instantiated
 * nested-object instance by `ObjectInstantiationDecorator` (which fires in `processXxx()` before
 * `validateXxx()` is called). The composition validator therefore operates on an object, not the
 * original raw array. This causes two problems:
 *
 *  1. Every valid input is wrongly rejected with an `AnyOfException` / `ErrorRegistryException`,
 *     because the instanceof checks for each branch-specific class all fail against the already-
 *     instantiated outer-object class.
 *  2. In collect-errors mode an earlier PHP version could also produce a `TypeError` when the
 *     `_getModifiedValues_*` helper was called with an object where an array was expected.
 *
 * Root cause: `AbstractPropertyProcessor::addComposedValueValidator` was not skipping the
 * composition validation for non-root `type: object` properties. The issue-#98 fix adds that
 * guard, which also resolves issue #101.
 */
class Issue101Test extends AbstractIssueTestCase
{
    // -------------------------------------------------------------------------
    // anyOf with distinct named properties per branch
    // -------------------------------------------------------------------------

    /**
     * Valid input matching the first anyOf branch must be accepted without an exception.
     */
    #[DataProvider('validAnyOfDistinctBranchPropertiesProvider')]
    public function testValidInputForAnyOfDistinctBranchPropertiesIsAccepted(array $input): void
    {
        $className = $this->generateClassFromFile('nestedObjectWithAnyOfDistinctBranchProperties.json');

        $object = new $className($input);
        $this->assertNotNull($object->getMode());
    }

    public static function validAnyOfDistinctBranchPropertiesProvider(): array
    {
        return [
            'first branch (speed)'      => [['mode' => ['speed' => 3]]],
            'second branch (level)'     => [['mode' => ['level' => 5]]],
            'both branches (anyOf: ≥1)' => [['mode' => ['speed' => 3, 'level' => 5]]],
        ];
    }

    /**
     * The getter for the nested property must return an object with the correct property values.
     */
    public function testGetterReturnsCorrectValuesForFirstBranch(): void
    {
        $className = $this->generateClassFromFile('nestedObjectWithAnyOfDistinctBranchProperties.json');

        $object = new $className(['mode' => ['speed' => 7]]);
        $mode   = $object->getMode();

        $this->assertNotNull($mode);
        $this->assertSame(7, $mode->getSpeed());
    }

    public function testGetterReturnsCorrectValuesForSecondBranch(): void
    {
        $className = $this->generateClassFromFile('nestedObjectWithAnyOfDistinctBranchProperties.json');

        $object = new $className(['mode' => ['level' => 2]]);
        $mode   = $object->getMode();

        $this->assertNotNull($mode);
        $this->assertSame(2, $mode->getLevel());
    }

    /**
     * Input that satisfies neither branch must be rejected.
     */
    #[DataProvider('invalidAnyOfDistinctBranchPropertiesProvider')]
    public function testInvalidInputForAnyOfDistinctBranchPropertiesIsRejected(array $input): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('nestedObjectWithAnyOfDistinctBranchProperties.json');

        new $className($input);
    }

    public static function invalidAnyOfDistinctBranchPropertiesProvider(): array
    {
        return [
            'empty object — neither branch satisfied' => [['mode' => []]],
            'speed below minimum'                    => [['mode' => ['speed' => -1]]],
            'level below minimum'                    => [['mode' => ['level' => -5]]],
        ];
    }

    /**
     * Null or an explicit null value for the optional nested property must be accepted.
     */
    public function testNullModeIsAccepted(): void
    {
        $className = $this->generateClassFromFile('nestedObjectWithAnyOfDistinctBranchProperties.json');

        $this->assertNull((new $className([]))->getMode());
        $this->assertNull((new $className(['mode' => null]))->getMode());
    }

    // -------------------------------------------------------------------------
    // oneOf with distinct named properties per branch
    // -------------------------------------------------------------------------

    /**
     * Valid input matching one oneOf branch must be accepted without an exception.
     */
    #[DataProvider('validOneOfDistinctPropertiesProvider')]
    public function testValidInputForOneOfDistinctPropertiesIsAccepted(array $input): void
    {
        $className = $this->generateClassFromFile('nestedObjectWithOneOfDistinctProperties.json');

        $object = new $className($input);
        $this->assertNotNull($object->getPricingOption());
    }

    public static function validOneOfDistinctPropertiesProvider(): array
    {
        return [
            'first branch (price_per_unit)' => [['pricing_option' => ['price_per_unit' => 9.99]]],
            'second branch (flat_fee)'      => [['pricing_option' => ['flat_fee' => 50.0]]],
        ];
    }

    /**
     * The getter must return an object with the correct property value.
     */
    public function testOneOfGetterReturnsCorrectValueForFirstBranch(): void
    {
        $className = $this->generateClassFromFile('nestedObjectWithOneOfDistinctProperties.json');

        $object = new $className(['pricing_option' => ['price_per_unit' => 4.5]]);
        $this->assertSame(4.5, $object->getPricingOption()->getPricePerUnit());
    }

    public function testOneOfGetterReturnsCorrectValueForSecondBranch(): void
    {
        $className = $this->generateClassFromFile('nestedObjectWithOneOfDistinctProperties.json');

        $object = new $className(['pricing_option' => ['flat_fee' => 100.0]]);
        $this->assertSame(100.0, $object->getPricingOption()->getFlatFee());
    }

    /**
     * Input satisfying both or neither oneOf branch must be rejected.
     */
    #[DataProvider('invalidOneOfDistinctPropertiesProvider')]
    public function testInvalidInputForOneOfDistinctPropertiesIsRejected(array $input): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('nestedObjectWithOneOfDistinctProperties.json');

        new $className($input);
    }

    public static function invalidOneOfDistinctPropertiesProvider(): array
    {
        return [
            'neither branch satisfied'        => [['pricing_option' => []]],
            'both branches (violates oneOf)'  => [['pricing_option' => ['price_per_unit' => 9.99, 'flat_fee' => 50.0]]],
            'price_per_unit below minimum'    => [['pricing_option' => ['price_per_unit' => -1]]],
        ];
    }

    /**
     * Absent or null pricing_option must be accepted.
     */
    public function testNullPricingOptionIsAccepted(): void
    {
        $className = $this->generateClassFromFile('nestedObjectWithOneOfDistinctProperties.json');

        $this->assertNull((new $className([]))->getPricingOption());
        $this->assertNull((new $className(['pricing_option' => null]))->getPricingOption());
    }

}
