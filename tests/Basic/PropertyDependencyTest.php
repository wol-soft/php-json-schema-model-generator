<?php

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

/**
 * Class PropertyDependencyTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class PropertyDependencyTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @dataProvider validPropertyDependencyDataProvider
     *
     * @param array $propertyValue
     */
    public function testValidPropertyDependency(array $propertyValue): void
    {
        $className = $this->generateClassFromFile('PropertyDependency.json');

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['credit_card'] ?? null, $object->getCreditCard());
        $this->assertSame($propertyValue['billing_address'] ?? null, $object->getBillingAddress());
    }

    public function validPropertyDependencyDataProvider(): array
    {
        return [
            'No properties provided' => [[]],
            'Only dependant property provided' => [['billing_address' => '555 Debitors Lane']],
            'All properties provided' => [['credit_card' => 12345, 'billing_address' => '555 Debitors Lane']],
        ];
    }

    /**
     * @dataProvider validMultiplePropertyDependenciesDataProvider
     *
     * @param array $propertyValue
     */
    public function testValidMultiplePropertyDependencies(array $propertyValue): void
    {
        $className = $this->generateClassFromFile('MultiplePropertyDependencies.json');

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['credit_card'] ?? null, $object->getCreditCard());
        $this->assertSame($propertyValue['billing_address'] ?? null, $object->getBillingAddress());
        $this->assertSame($propertyValue['name'] ?? null, $object->getName());
    }

    public function validMultiplePropertyDependenciesDataProvider(): array
    {
        return [
            'No properties provided' => [[]],
            'Only dependant property provided 1' => [['billing_address' => '555 Debitors Lane']],
            'Only dependant property provided 2' => [['name' => 'John']],
            'Only dependant property provided 3' => [['billing_address' => '555 Debitors Lane', 'name' => 'John']],
            'All properties provided' => [['credit_card' => 12345, 'billing_address' => '555 Debitors Lane', 'name' => 'John']],
        ];
    }

    /**
     * @dataProvider validBidirectionalPropertyDependencyDataProvider
     *
     * @param array $propertyValue
     */
    public function testValidBidirectionalPropertyDependency(array $propertyValue): void
    {
        $className = $this->generateClassFromFile('BidirectionalPropertyDependency.json');

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['credit_card'] ?? null, $object->getCreditCard());
        $this->assertSame($propertyValue['billing_address'] ?? null, $object->getBillingAddress());
    }

    public function validBidirectionalPropertyDependencyDataProvider(): array
    {
        return [
            'No properties provided' => [[]],
            'All properties provided' => [['credit_card' => 12345, 'billing_address' => '555 Debitors Lane']],
        ];
    }

    /**
     * @dataProvider validationMethodDataProvider
     *
     * @param GeneratorConfiguration $configuration
     */
    public function testInvalidPropertyDependencyThrowsAnException(GeneratorConfiguration $configuration): void {
        $this->expectValidationError(
            $configuration,
            <<<ERROR
Missing required attributes which are dependants of credit_card:
  - billing_address
ERROR
        );

        $className = $this->generateClassFromFile('PropertyDependency.json', $configuration);

        new $className(['credit_card' => 12345]);
    }

    /**
     * @dataProvider invalidMultiplePropertyDependenciesDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param array $propertyValue
     * @param string $message
     */
    public function testInvalidMultiplePropertyDependenciesThrowsAnException(
        GeneratorConfiguration $configuration,
        array $propertyValue,
        string $message
    ): void {
        $this->expectValidationError($configuration, $message);

        $className = $this->generateClassFromFile('MultiplePropertyDependencies.json', $configuration);

        new $className($propertyValue);
    }

    public function invalidMultiplePropertyDependenciesDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
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
            ]
        );
    }

    /**
     * @dataProvider invalidBidirectionalPropertyDependencyDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param array $propertyValue
     * @param string $message
     */
    public function testInvalidBidirectionalPropertyDependencyThrowsAnException(
        GeneratorConfiguration $configuration,
        array $propertyValue,
        string $message
    ): void {
        $this->expectValidationError($configuration, $message);

        $className = $this->generateClassFromFile('BidirectionalPropertyDependency.json', $configuration);

        new $className($propertyValue);
    }

    public function invalidBidirectionalPropertyDependencyDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
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
            ]
        );
    }
}
