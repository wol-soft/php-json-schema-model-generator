<?php

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\ErrorRegistryException;
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

    /**
     * @dataProvider validSchemaDependencyReferenceDataProvider
     *
     * @param array $propertyValue
     */
    public function testSchemaDependencyReference(array $propertyValue): void
    {
        $className = $this->generateClassFromFile('ReferenceSchemaDependency.json');

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['credit_card'] ?? null, $object->getCreditCard());
        $this->assertSame($propertyValue['name'] ?? null, $object->getName());
        $this->assertSame($propertyValue['age'] ?? null, $object->getAge());
    }

    public function validSchemaDependencyReferenceDataProvider(): array
    {
        return [
            'No properties provided' => [[]],
            'Only dependant property provided 1' => [['name' => 'Hannes']],
            'Only dependant property provided 2' => [['age' => 42]],
            'Only dependant property provided 3' => [['name' => 'Hannes', 'age' => 42]],
            'All properties provided' => [['credit_card' => 12345, 'name' => 'Hannes', 'age' => 42]],
            'Only required properties provided' => [['credit_card' => 12345, 'name' => 'Hannes']],
        ];
    }

    /**
     * @dataProvider invalidSchemaDependencyReferenceDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param array $propertyValue
     * @param string $message
     */
    public function testInvalidSchemaDependencyReference(
        GeneratorConfiguration $configuration,
        array $propertyValue,
        string $message
    ): void {
        $this->expectValidationError($configuration, $message);

        $className = $this->generateClassFromFile('ReferenceSchemaDependency.json', $configuration);

        new $className($propertyValue);
    }

    public function invalidSchemaDependencyReferenceDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'required attribute not provided 1' => [
                    ['credit_card' => 12345],
                    <<<ERROR
Invalid schema which is dependant on credit_card:
  - Missing required value for name
ERROR
                ],
                'required attribute not provided 2' => [
                    ['credit_card' => 12345, 'age' => 42],
                    <<<ERROR
Invalid schema which is dependant on credit_card:
  - Missing required value for name
ERROR
                ],
                'invalid data type' => [
                    ['credit_card' => 12345, 'name' => false],
                    <<<ERROR
Invalid schema which is dependant on credit_card:
  - Invalid type for name. Requires string, got boolean
ERROR
                ],
            ]
        );
    }

    /**
     * @dataProvider validSchemaDependencyCompositionDataProvider
     *
     * @param array $propertyValue
     */
    public function testSchemaDependencyComposition(array $propertyValue): void
    {
        $className = $this->generateClassFromFile('CompositionSchemaDependency.json');

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['credit_card'] ?? null, $object->getCreditCard());
        $this->assertSame($propertyValue['name'] ?? null, $object->getName());
        $this->assertSame($propertyValue['age'] ?? null, $object->getAge());
    }

    public function validSchemaDependencyCompositionDataProvider(): array
    {
        return [
            'No properties provided' => [[]],
            'Only dependant property provided 1' => [['name' => 'Hannes']],
            'Only dependant property provided 2' => [['age' => 42]],
            'Only dependant property provided 3' => [['name' => 'Hannes', 'age' => 42]],
            'All properties provided' => [['credit_card' => 12345, 'name' => 'Hannes', 'age' => 42]],
        ];
    }

    /**
     * @dataProvider invalidSchemaDependencyCompositionDataProvider
     *
     * @param array $propertyValue
     * @param string $message
     */
    public function testInvalidSchemaDependencyComposition(
        array $propertyValue,
        string $message
    ): void {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches("/$message/m");

        $className = $this->generateClassFromFile(
            'CompositionSchemaDependency.json',
            (new GeneratorConfiguration())->setCollectErrors(true)
        );

        new $className($propertyValue);
    }

    public function invalidSchemaDependencyCompositionDataProvider(): array
    {
        return [
            'required attribute not provided 1' => [
                ['credit_card' => 12345],
                <<<ERROR
Invalid schema which is dependant on credit_card:
  - Invalid value for Credit_card_Dependency_(.*) declined by composition constraint.
      Requires to match 2 composition elements but matched 0 elements.
      - Composition element #1: Failed
        \* Missing required value for name
        \* Invalid type for name. Requires string, got NULL
      - Composition element #2: Failed
        \* Missing required value for age
        \* Invalid type for age. Requires int, got NULL
ERROR
            ],
            'required attribute not provided 2' => [
                ['credit_card' => 12345, 'age' => 42],
                <<<ERROR
Invalid schema which is dependant on credit_card:
  - Invalid value for Credit_card_Dependency_(.*) declined by composition constraint.
      Requires to match 2 composition elements but matched 1 elements.
      - Composition element #1: Failed
        \* Missing required value for name
        \* Invalid type for name. Requires string, got NULL
      - Composition element #2: Valid
ERROR
            ],
            'invalid data type' => [
                ['credit_card' => 12345, 'name' => false, 'age' => 42],
                <<<ERROR
Invalid schema which is dependant on credit_card:
  - Invalid value for Credit_card_Dependency_(.*) declined by composition constraint.
      Requires to match 2 composition elements but matched 1 elements.
      - Composition element #1: Failed
        \* Invalid type for name. Requires string, got boolean
      - Composition element #2: Valid
ERROR
            ]
        ];
    }
}
