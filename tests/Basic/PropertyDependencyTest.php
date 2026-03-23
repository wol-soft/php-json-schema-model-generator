<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class PropertyDependencyTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class PropertyDependencyTest extends AbstractPHPModelGeneratorTestCase
{
    #[DataProvider('validPropertyDependencyDataProvider')]
    public function testValidPropertyDependency(array $propertyValue): void
    {
        $className = $this->generateClassFromFile('PropertyDependency.json');

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['credit_card'] ?? null, $object->getCreditCard());
        $this->assertSame($propertyValue['billing_address'] ?? null, $object->getBillingAddress());
    }

    public static function validPropertyDependencyDataProvider(): array
    {
        return [
            'No properties provided' => [[]],
            'Only dependant property provided' => [['billing_address' => '555 Debitors Lane']],
            'All properties provided' => [['credit_card' => 12345, 'billing_address' => '555 Debitors Lane']],
        ];
    }

    #[DataProvider('validMultiplePropertyDependenciesDataProvider')]
    public function testValidMultiplePropertyDependencies(array $propertyValue): void
    {
        $className = $this->generateClassFromFile('MultiplePropertyDependencies.json');

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['credit_card'] ?? null, $object->getCreditCard());
        $this->assertSame($propertyValue['billing_address'] ?? null, $object->getBillingAddress());
        $this->assertSame($propertyValue['name'] ?? null, $object->getName());
    }

    public static function validMultiplePropertyDependenciesDataProvider(): array
    {
        return [
            'No properties provided' => [[]],
            'Only dependant property provided 1' => [['billing_address' => '555 Debitors Lane']],
            'Only dependant property provided 2' => [['name' => 'John']],
            'Only dependant property provided 3' => [['billing_address' => '555 Debitors Lane', 'name' => 'John']],
            'All properties provided' => [['credit_card' => 12345, 'billing_address' => '555 Debitors Lane', 'name' => 'John']],
        ];
    }

    #[DataProvider('validBidirectionalPropertyDependencyDataProvider')]
    public function testValidBidirectionalPropertyDependency(array $propertyValue): void
    {
        $className = $this->generateClassFromFile('BidirectionalPropertyDependency.json');

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['credit_card'] ?? null, $object->getCreditCard());
        $this->assertSame($propertyValue['billing_address'] ?? null, $object->getBillingAddress());
    }

    public static function validBidirectionalPropertyDependencyDataProvider(): array
    {
        return [
            'No properties provided' => [[]],
            'All properties provided' => [['credit_card' => 12345, 'billing_address' => '555 Debitors Lane']],
        ];
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testInvalidPropertyDependencyThrowsAnException(GeneratorConfiguration $configuration): void {
        $this->expectValidationError(
            $configuration,
            <<<ERROR
Missing required attributes which are dependants of credit_card:
  - billing_address
ERROR,
        );

        $className = $this->generateClassFromFile('PropertyDependency.json', $configuration);

        new $className(['credit_card' => 12345]);
    }

    #[DataProvider('invalidMultiplePropertyDependenciesDataProvider')]
    public function testInvalidMultiplePropertyDependenciesThrowsAnException(
        GeneratorConfiguration $configuration,
        array $propertyValue,
        string $message,
    ): void {
        $this->expectValidationError($configuration, $message);

        $className = $this->generateClassFromFile('MultiplePropertyDependencies.json', $configuration);

        new $className($propertyValue);
    }

    public static function invalidMultiplePropertyDependenciesDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'no required attribute provided' => [
                    ['credit_card' => 12345],
                    <<<ERROR
Missing required attributes which are dependants of credit_card:
  - billing_address
  - name
ERROR
                ],
                'only one required attribute provided 1' => [
                    ['credit_card' => 12345, 'billing_address' => '555 Debitors Lane'],
                    <<<ERROR
Missing required attributes which are dependants of credit_card:
  - name
ERROR
                ],
                'only one required attribute provided 2' => [
                    ['credit_card' => 12345, 'name' => 'John'],
                    <<<ERROR
Missing required attributes which are dependants of credit_card:
  - billing_address
ERROR
                ],
            ],
        );
    }

    #[DataProvider('invalidBidirectionalPropertyDependencyDataProvider')]
    public function testInvalidBidirectionalPropertyDependencyThrowsAnException(
        GeneratorConfiguration $configuration,
        array $propertyValue,
        string $message,
    ): void {
        $this->expectValidationError($configuration, $message);

        $className = $this->generateClassFromFile('BidirectionalPropertyDependency.json', $configuration);

        new $className($propertyValue);
    }

    public static function invalidBidirectionalPropertyDependencyDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                [
                    ['credit_card' => 12345],
                    <<<ERROR
Missing required attributes which are dependants of credit_card:
  - billing_address
ERROR
                ],
                [
                    ['billing_address' => '555 Debitors Lane'],
                    <<<ERROR
Missing required attributes which are dependants of billing_address:
  - credit_card
ERROR
                ],
            ],
        );
    }
}
