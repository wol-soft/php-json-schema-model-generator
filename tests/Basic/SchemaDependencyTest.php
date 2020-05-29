<?php

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

/**
 * Class SchemaDependencyTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class SchemaDependencyTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @dataProvider validSchemaDependencyDataProvider
     *
     * @param array $propertyValue
     */
    public function testValidSchemaDependency(array $propertyValue): void
    {
        $className = $this->generateClassFromFile('SchemaDependency.json');

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['credit_card'] ?? null, $object->getCreditCard());
        $this->assertSame($propertyValue['billing_address'] ?? null, $object->getBillingAddress());
        $this->assertSame($propertyValue['date_of_birth'] ?? null, $object->getDateOfBirth());
    }

    public function validSchemaDependencyDataProvider(): array
    {
        return [
            'No properties provided' => [[]],
            'Only dependant property provided 1' => [['billing_address' => '555 Debitors Lane']],
            'Only dependant property provided 2' => [['date_of_birth' => '01-01-1990']],
            'Only dependant property provided 3' => [['billing_address' => '555 Debitors Lane', 'date_of_birth' => '01-01-1990']],
            'All properties provided' => [['credit_card' => 12345, 'billing_address' => '555 Debitors Lane', 'date_of_birth' => '01-01-1990']],
            'Only required properties provided' => [['credit_card' => 12345, 'date_of_birth' => '01-01-1990']],
        ];
    }

    /**
     * @dataProvider invalidSchemaDependencyDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param array $propertyValue
     * @param string $message
     */
    public function testInvalidSchemaDependency(
        GeneratorConfiguration $configuration,
        array $propertyValue,
        string $message
    ): void {
        $this->expectValidationError($configuration, $message);

        $className = $this->generateClassFromFile('SchemaDependency.json', $configuration);

        new $className($propertyValue);
    }


    public function invalidSchemaDependencyDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'required attribute not provided 1' => [
                    ['credit_card' => 12345],
                    <<<ERROR
Invalid schema which is dependant on credit_card:
  - Missing required value for date_of_birth
ERROR
                ],
                'required attribute not provided 2' => [
                    ['credit_card' => 12345, 'billing_address' => '555 Debitors Lane'],
                    <<<ERROR
Invalid schema which is dependant on credit_card:
  - Missing required value for date_of_birth
ERROR
                ],
                'invalid data type' => [
                    ['credit_card' => 12345, 'date_of_birth' => false],
                    <<<ERROR
Invalid schema which is dependant on credit_card:
  - Invalid type for date_of_birth. Requires string, got boolean
ERROR
                ],
            ]
        );
    }
}
