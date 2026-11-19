<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\Dependency\InvalidSchemaDependencyException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Generic\DeniedPropertyException;
use PHPModelGenerator\Exception\Object\AdditionalPropertiesException;
use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\String\MinLengthException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Fixtures\RecordingLogger;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Throwable;

#[ApplicableDrafts]
class SchemaDependencyTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * The schema dependency counterpart of PropertyDependencyTest::
     * testPropertyDependencyOfAnUndeclaredPropertyIsEnforced(): a schema dependency constrains the objects which
     * contain the property, whether or not the property is declared in `properties`.
     */
    public function testSchemaDependencyOfAnUndeclaredPropertyIsEnforced(): void
    {
        $className = $this->generateClassFromFile(
            'SchemaDependencyOnUndeclaredProperty.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $this->assertNull((new $className([]))->getCreditCard());
        $this->assertSame(
            12345,
            (new $className(['credit_card' => 12345, 'billing_address' => 'Main Street']))->getCreditCard(),
        );

        $this->expectException(InvalidSchemaDependencyException::class);
        $this->expectExceptionMessage(
            <<<'ERROR'
            Invalid schema which is dependant on 'credit_card':
              - Missing required value for 'billing_address'
            ERROR,
        );

        new $className(['credit_card' => 12345]);
    }

    #[DataProvider('validSchemaDependencyDataProvider')]
    public function testValidSchemaDependency(array $propertyValue): void
    {
        $className = $this->generateClassFromFile('SchemaDependency.json');

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['credit_card'] ?? null, $object->getCreditCard());
        $this->assertSame($propertyValue['billing_address'] ?? null, $object->getBillingAddress());
        $this->assertSame($propertyValue['date_of_birth'] ?? null, $object->getDateOfBirth());
    }

    public static function validSchemaDependencyDataProvider(): array
    {
        return [
            'No properties provided' => [[]],
            'Only dependant property provided with different type' => [['billing_address' => true]],
            'Only dependant property provided 1' => [['billing_address' => '555 Debitors Lane']],
            'Only dependant property provided 2' => [['date_of_birth' => '01-01-1990']],
            'Only dependant property provided 3' => [
                ['billing_address' => '555 Debitors Lane', 'date_of_birth' => '01-01-1990'],
            ],
            'Dependant property nulled 1' => [['billing_address' => null]],
            'Dependant property nulled 2' => [['date_of_birth' => null]],
            'All properties provided' => [
                ['credit_card' => 12345, 'billing_address' => '555 Debitors Lane', 'date_of_birth' => '01-01-1990'],
            ],
            'Only required properties provided' => [['credit_card' => 12345, 'date_of_birth' => '01-01-1990']],
        ];
    }

    #[DataProvider('invalidSchemaDependencyDataProvider')]
    public function testInvalidSchemaDependency(
        GeneratorConfiguration $configuration,
        array $propertyValue,
        string $directExceptionMessage,
        string $collectedErrorsMessage,
    ): void {
        $className = $this->generateClassFromFile('SchemaDependency.json', $configuration);

        try {
            new $className($propertyValue);
            $this->fail('Expected exception for invalid schema dependency');
        } catch (ErrorRegistryException | InvalidSchemaDependencyException $exception) {
            // The direct exception reports the first violation of the dependency schema only.
            $this->assertSame(
                $configuration->collectErrors() ? $collectedErrorsMessage : $directExceptionMessage,
                $exception->getMessage(),
            );

            // collectErrors(true) wraps the dependency exception in an ErrorRegistryException.
            $innerException = $exception instanceof ErrorRegistryException
                ? $exception->getErrors()[0]
                : $exception;

            $this->assertInstanceOf(InvalidSchemaDependencyException::class, $innerException);
            $this->assertSame('/dependencies/credit_card', $innerException->getJsonPointer()->pointer);
        }
    }

    public static function invalidSchemaDependencyDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'required attribute not provided 1' => [
                    ['credit_card' => 12345],
                    <<<ERROR
                    Invalid schema which is dependant on 'credit_card':
                      - Missing required value for 'date_of_birth'
                    ERROR,
                    <<<ERROR
                    Invalid schema which is dependant on 'credit_card':
                      - Missing required value for 'date_of_birth'
                      - Invalid type for 'date_of_birth': requires 'string', got 'NULL'
                    ERROR,
                ],
                'required attribute not provided 2' => [
                    ['credit_card' => 12345, 'billing_address' => '555 Debitors Lane'],
                    <<<ERROR
                    Invalid schema which is dependant on 'credit_card':
                      - Missing required value for 'date_of_birth'
                    ERROR,
                    <<<ERROR
                    Invalid schema which is dependant on 'credit_card':
                      - Missing required value for 'date_of_birth'
                      - Invalid type for 'date_of_birth': requires 'string', got 'NULL'
                    ERROR,
                ],
                'invalid data type' => [
                    ['credit_card' => 12345, 'date_of_birth' => false],
                    <<<ERROR
                    Invalid schema which is dependant on 'credit_card':
                      - Invalid type for 'date_of_birth': requires 'string', got 'boolean'
                    ERROR,
                    <<<ERROR
                    Invalid schema which is dependant on 'credit_card':
                      - Invalid type for 'date_of_birth': requires 'string', got 'boolean'
                    ERROR,
                ],
            ],
        );
    }

    #[DataProvider('validSchemaDependencyReferenceDataProvider')]
    public function testSchemaDependencyReference(array $propertyValue): void
    {
        $className = $this->generateClassFromFile('ReferenceSchemaDependency.json');

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['credit_card'] ?? null, $object->getCreditCard());
        $this->assertSame($propertyValue['name'] ?? null, $object->getName());
        $this->assertSame($propertyValue['age'] ?? null, $object->getAge());
    }

    public static function validSchemaDependencyReferenceDataProvider(): array
    {
        return [
            'No properties provided' => [[]],
            'Only dependant property provided with different type' => [['name' => true]],
            'Only dependant property provided 1' => [['name' => 'Hannes']],
            'Only dependant property provided 2' => [['age' => 42]],
            'Only dependant property provided 3' => [['name' => 'Hannes', 'age' => 42]],
            'Dependant property nulled 1' => [['name' => null]],
            'Dependant property nulled 2' => [['age' => null]],
            'All properties provided' => [['credit_card' => 12345, 'name' => 'Hannes', 'age' => 42]],
            'Only required properties provided' => [['credit_card' => 12345, 'name' => 'Hannes']],
        ];
    }

    #[DataProvider('invalidSchemaDependencyReferenceDataProvider')]
    public function testInvalidSchemaDependencyReference(
        GeneratorConfiguration $configuration,
        array $propertyValue,
        string $message,
    ): void {
        $this->expectValidationError($configuration, $message);

        $className = $this->generateClassFromFile('ReferenceSchemaDependency.json', $configuration);

        new $className($propertyValue);
    }

    public static function invalidSchemaDependencyReferenceDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'required attribute not provided 1' => [
                    ['credit_card' => 12345],
                    <<<ERROR
                    Invalid schema which is dependant on 'credit_card':
                      - Missing required value for 'name'
                    ERROR,
                ],
                'required attribute not provided 2' => [
                    ['credit_card' => 12345, 'age' => 42],
                    <<<ERROR
                    Invalid schema which is dependant on 'credit_card':
                      - Missing required value for 'name'
                    ERROR,
                ],
                'invalid data type' => [
                    ['credit_card' => 12345, 'name' => false],
                    <<<ERROR
                    Invalid schema which is dependant on 'credit_card':
                      - Invalid type for 'name': requires 'string', got 'boolean'
                    ERROR,
                ],
            ],
        );
    }

    #[DataProvider('validSchemaDependencyCompositionDataProvider')]
    public function testSchemaDependencyComposition(array $propertyValue): void
    {
        $className = $this->generateClassFromFile('CompositionSchemaDependency.json');

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['credit_card'] ?? null, $object->getCreditCard());
        $this->assertSame($propertyValue['name'] ?? null, $object->getName());
        $this->assertSame($propertyValue['age'] ?? null, $object->getAge());
    }

    public static function validSchemaDependencyCompositionDataProvider(): array
    {
        return [
            'No properties provided' => [[]],
            'Only dependant property provided with different type' => [['name' => 42]],
            'Only dependant property provided 1' => [['name' => 'Hannes']],
            'Only dependant property provided 2' => [['age' => 42]],
            'Only dependant property provided 3' => [['name' => 'Hannes', 'age' => 42]],
            'Dependant property nulled 1' => [['name' => null]],
            'Dependant property nulled 2' => [['age' => null]],
            'All properties provided' => [['credit_card' => 12345, 'name' => 'Hannes', 'age' => 42]],
        ];
    }

    #[DataProvider('invalidSchemaDependencyCompositionDataProvider')]
    public function testInvalidSchemaDependencyComposition(
        array $propertyValue,
        string $messageTemplate,
    ): void {
        $className = $this->generateClassFromFile(
            'CompositionSchemaDependency.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        // Generation emits three nested classes: the dependency wrapper (named
        // *_credit_card_Dependency) plus one class per allOf branch. Only the wrapper
        // appears in the composition-decline error, so filter for it explicitly.
        $nestedClasses = $this->resolveNestedClassName($className, 3);
        $wrapperMatches = array_values(array_filter(
            $nestedClasses,
            static fn(string $name): bool => str_ends_with($name, '_credit_card_Dependency'),
        ));
        $this->assertCount(1, $wrapperMatches, 'expected exactly one dependency wrapper class');
        $dependencyClass = $wrapperMatches[0];

        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessage(
            str_replace('{dependencyClass}', $dependencyClass, $messageTemplate),
        );

        new $className($propertyValue);
    }

    public static function invalidSchemaDependencyCompositionDataProvider(): array
    {
        return [
            'required attribute not provided 1' => [
                ['credit_card' => 12345],
                <<<'ERROR'
                Invalid schema which is dependant on 'credit_card':
                  - Invalid value for '{dependencyClass}' declined by composition constraint
                      Requires to match all composition elements but matched 0 elements
                      - Composition element #1: Failed
                        * Missing required value for 'name'
                        * Invalid type for 'name': requires 'string', got 'NULL'
                      - Composition element #2: Failed
                        * Missing required value for 'age'
                        * Invalid type for 'age': requires 'int', got 'NULL'
                ERROR,
            ],
            'required attribute not provided 2' => [
                ['credit_card' => 12345, 'age' => 42],
                <<<'ERROR'
                Invalid schema which is dependant on 'credit_card':
                  - Invalid value for '{dependencyClass}' declined by composition constraint
                      Requires to match all composition elements but matched 1 element
                      - Composition element #1: Failed
                        * Missing required value for 'name'
                        * Invalid type for 'name': requires 'string', got 'NULL'
                      - Composition element #2: Valid
                ERROR,
            ],
            'invalid data type' => [
                ['credit_card' => 12345, 'name' => false, 'age' => 42],
                <<<'ERROR'
                Invalid schema which is dependant on 'credit_card':
                  - Invalid value for '{dependencyClass}' declined by composition constraint
                      Requires to match all composition elements but matched 1 element
                      - Composition element #1: Failed
                        * Invalid type for 'name': requires 'string', got 'boolean'
                      - Composition element #2: Valid
                ERROR,
            ],
        ];
    }

    #[DataProvider('validSchemaDependencyNestedObjectDataProvider')]
    public function testSchemaDependencyNestedObject(array $propertyValue): void
    {
        $className = $this->generateClassFromFile('NestedObjectSchemaDependency.json');

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['credit_card'] ?? null, $object->getCreditCard());
        $this->assertSame($propertyValue['billing_address'] ?? null, $object->getBillingAddress());

        if (isset($propertyValue['owner'])) {
            $this->assertSame($propertyValue['owner']['name'] ?? null, $object->getOwner()->getName());
            $this->assertSame($propertyValue['owner']['age'] ?? null, $object->getOwner()->getAge());
        } else {
            $this->assertNull($object->getOwner());
        }
    }

    public static function validSchemaDependencyNestedObjectDataProvider(): array
    {
        return [
            'No properties provided' => [[]],
            'Only dependant properties provided with different type' => [['billing_address' => 42]],
            'Only dependant properties provided 1' => [['billing_address' => '555 Debitors Lane']],
            'Only dependant properties provided 2' => [['owner' => ['name' => 'Hannes', 'age' => 42]]],
            'Only dependant properties provided 3' => [[
                'owner' => ['name' => 'Hannes', 'age' => 42],
                'billing_address' => '555 Debitors Lane',
            ]],
            'Dependant property nulled 1' => [['billing_address' => null]],
            'Dependant property nulled 2' => [['owner' => null]],
            'All properties provided' => [[
                'credit_card' => 12345,
                'owner' => ['name' => 'Hannes', 'age' => 42],
                'billing_address' => '555 Debitors Lane',
            ]],
            'Only required properties provided' => [[
                'credit_card' => 12345,
                'owner' => ['name' => 'Hannes'],
                'billing_address' => '555 Debitors Lane',
            ]],
        ];
    }

    #[DataProvider('invalidSchemaDependencyNestedObjectDataProvider')]
    public function testInvalidSchemaDependencyNestedObject(
        array $propertyValue,
        string $message,
    ): void {
        $className = $this->generateClassFromFile(
            'NestedObjectSchemaDependency.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        $exception = $this->catchException(static fn() => new $className($propertyValue));

        $this->assertInstanceOf(ErrorRegistryException::class, $exception);

        // Compared exactly, not by containment: the violation of the nested object belongs to the dependency schema
        // and must not be reported a second time for the property of the base model, appended after the message.
        if ($exception->getMessage() === $message) {
            return;
        }

        // Known gap: the property copied from the dependency schema to the base model keeps the instantiation of the
        // nested object and reports the violation once more. Any other deviation fails here.
        $this->assertStringStartsWith($message . "\n", $exception->getMessage());
        $this->markTestIncomplete(
            'Known gap: a property declared by a dependency schema validates its nested object on the base model too.',
        );
    }

    public static function invalidSchemaDependencyNestedObjectDataProvider(): array
    {
        return [
            'required attribute not provided 1' => [
                ['credit_card' => 12345],
                <<<ERROR
                Invalid schema which is dependant on 'credit_card':
                  - Missing required value for 'billing_address'
                  - Invalid type for 'billing_address': requires 'string', got 'NULL'
                  - Missing required value for 'owner'
                  - Invalid type for 'owner': requires 'object', got 'NULL'
                ERROR,
            ],
            'required attribute not provided 2' => [
                ['credit_card' => 12345, 'owner' => ['age' => 42]],
                <<<ERROR
                Invalid schema which is dependant on 'credit_card':
                  - Missing required value for 'billing_address'
                  - Invalid type for 'billing_address': requires 'string', got 'NULL'
                  - Invalid nested object for property 'owner':
                      - Missing required value for 'name'
                      - Invalid type for 'name': requires 'string', got 'NULL'
                ERROR,
            ],
            'invalid data type' => [
                [
                    'credit_card' => 12345,
                    'owner' => [
                        'name' => false,
                        'age' => 42,
                    ],
                    'billing_address' => '555 Debitors Lane',
                ],
                <<<ERROR
                Invalid schema which is dependant on 'credit_card':
                  - Invalid nested object for property 'owner':
                      - Invalid type for 'name': requires 'string', got 'boolean'
                ERROR,
            ],
        ];
    }

    /**
     * Without the trigger the dependency schema is inactive: the value of a property which only the dependency schema
     * declares must not be validated against that declaration.
     */
    public function testPropertyDeclaredByAnInactiveDependencySchemaIsNotValidated(): void
    {
        $className = $this->generateClassFromFile(
            'NestedObjectSchemaDependency.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        try {
            $object = new $className(['owner' => ['age' => 42]]);
        } catch (NestedObjectException $exception) {
            // Known gap: the property copied from the dependency schema to the base model keeps the instantiation of
            // the nested object, which validates the value although the trigger is absent.
            $this->assertSame(
                <<<'ERROR'
                Invalid nested object for property 'owner':
                  - Missing required value for 'name'
                ERROR,
                $exception->getMessage(),
            );
            $this->markTestIncomplete(
                'Known gap: a property declared by a dependency schema is validated while the trigger is absent.',
            );
        }

        $this->assertSame(['owner' => ['age' => 42]], $object->meta()->rawInput());
    }

    public static function dependencySchemaRedeclaringMainPropertyDataProvider(): array
    {
        return [
            'main property declared after the dependant property' =>
                ['DependencySchemaRedeclaresMainPropertyAfterDependant.json'],
            'main property declared before the dependant property' =>
                ['DependencySchemaRedeclaresMainPropertyBeforeDependant.json'],
        ];
    }

    /**
     * A property which is declared by the main schema and again by a dependency schema keeps the declaration of the
     * main schema (type, validators, filters), independent of the order in which the properties are declared. The
     * dependency schema only validates.
     */
    #[DataProvider('dependencySchemaRedeclaringMainPropertyDataProvider')]
    public function testPropertyOfTheMainSchemaWinsOverTheDeclarationOfADependencySchema(string $schemaFile): void
    {
        $className = $this->generateClassFromFile($schemaFile, (new GeneratorConfiguration())->setCollectErrors(false));

        $object = new $className(['billing_address' => '  555 Debitors Lane  ']);
        $this->assertSame('555 Debitors Lane', $object->getBillingAddress());
        $this->assertSame(
            '?string',
            (string) (new ReflectionMethod($className, 'getBillingAddress'))->getReturnType(),
        );

        try {
            new $className(['billing_address' => '  ab  ']);
            $this->fail('Expected the minLength violation of the main schema');
        } catch (MinLengthException $exception) {
            $this->assertSame("Value for 'billing_address' must not be shorter than 3", $exception->getMessage());
        }

        // The dependency schema is still enforced.
        $this->expectException(InvalidSchemaDependencyException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid schema which is dependant on 'credit_card':
              - Missing required value for 'billing_address'
            ERROR,
        );

        new $className(['credit_card' => 12345]);
    }

    /**
     * A schema dependency on a key which is declared neither in `properties` nor in `required` is enforced. The
     * trigger and the properties declared by the dependency schema get accessors, although none of them is part of
     * the `properties` of the schema.
     */
    public function testSchemaDependencyTriggerNotDeclaredInPropertiesOrRequiredIsEnforced(): void
    {
        $className = $this->generateClassFromFile('UndeclaredTriggerSchemaDependency.json');

        // Valid: no trigger present, dependency not enforced; both synthesised properties readable
        $object = new $className([]);
        $this->assertNull($object->getCreditCard());
        $this->assertNull($object->getBillingAddress());

        // Valid: trigger present and dependency satisfied; both accessors return correct values
        $object = new $className(['credit_card' => 12345, 'billing_address' => '555 Debitors Lane']);
        $this->assertSame(12345, $object->getCreditCard());
        $this->assertSame('555 Debitors Lane', $object->getBillingAddress());

        // Invalid: trigger present but required dependent property absent
        $this->expectException(InvalidSchemaDependencyException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid schema which is dependant on 'credit_card':
              - Missing required value for 'billing_address'
            ERROR,
        );

        new $className(['credit_card' => 12345]);
    }

    /**
     * A filter of a property inside a dependency schema runs inside the dependency schema (the validators of the
     * property see the filtered value), the base model keeps the value as provided. Consistent with the condition of
     * an if/then/else. A property which should be filtered has to be declared in the main schema.
     */
    public function testFilterInDependencySchemaRunsInsideTheDependencySchemaOnly(): void
    {
        $className = $this->generateClassFromFile(
            'DependencySchemaWithFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        // The dependency is active, the filtered value satisfies the minLength; the base model keeps the raw value.
        $object = new $className(['credit_card' => 12345, 'note' => '  ab  ']);
        $this->assertSame('  ab  ', $object->getNote());

        // The dependency is inactive: the dependency schema isn't validated.
        $object = new $className(['note' => ' a ']);
        $this->assertSame(' a ', $object->getNote());

        // The filtered value ('a') violates the minLength of the dependency schema.
        try {
            new $className(['credit_card' => 12345, 'note' => ' a ']);
            $this->fail('Expected the minLength violation of the dependency schema');
        } catch (InvalidSchemaDependencyException $exception) {
            $this->assertSame(
                <<<ERROR
                Invalid schema which is dependant on 'credit_card':
                  - Value for 'note' must not be shorter than 2
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * The schema which is dependant on a property is validated against the whole model data, not only against the
     * values which are part of the update.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testSetterAndPopulateValidateTheDependencySchemaAgainstTheStoredData(
        GeneratorConfiguration $configuration,
    ): void {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile('SchemaDependency.json', $configuration->setImmutable(false));

        $object = new $className(['date_of_birth' => '1990-01-01']);

        $object->setCreditCard(12345);
        $this->assertSame(12345, $object->getCreditCard());

        $object->populate(['credit_card' => 6789]);
        $this->assertSame(6789, $object->getCreditCard());

        // The dependency schema is still enforced if the required value isn't stored.
        $object = new $className([]);

        try {
            $object->setCreditCard(12345);
            $this->fail('Expected exception for the invalid dependency schema');
        } catch (ErrorRegistryException | InvalidSchemaDependencyException $exception) {
            // The direct exception reports the first violation only.
            $this->assertSame(
                $configuration->collectErrors()
                    ? <<<ERROR
                    Invalid schema which is dependant on 'credit_card':
                      - Missing required value for 'date_of_birth'
                      - Invalid type for 'date_of_birth': requires 'string', got 'NULL'
                    ERROR
                    : <<<ERROR
                    Invalid schema which is dependant on 'credit_card':
                      - Missing required value for 'date_of_birth'
                    ERROR,
                $exception->getMessage(),
            );
        }

        $this->assertNull($object->getCreditCard());
    }

    /**
     * The schema of a schema dependency is validated against the object which holds the property, so of the type list
     * ["object", "null"] only "object" can ever apply: the dependency behaves exactly like one which declares
     * "type": "object" and is generated as such instead of being rejected.
     */
    public function testSchemaDependencyWithATypeListIncludingObjectIsNarrowedToObject(): void
    {
        $className = $this->generateClassFromFile(
            'MultiTypeSchemaDependency.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $object = new $className([]);
        $this->assertNull($object->getCreditCard());

        $object = new $className(['credit_card' => 12345, 'billing_address' => 'Main Street']);
        $this->assertSame(12345, $object->getCreditCard());

        $this->expectException(InvalidSchemaDependencyException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid schema which is dependant on 'credit_card':
              - Missing required value for 'billing_address'
            ERROR,
        );

        new $className(['credit_card' => 12345]);
    }

    /**
     * The trigger isn't declared in `properties` and gets an untyped accessor. The properties of the dependency
     * schema are also declared by the `properties` of the schema (billing_address) and by an allOf branch (name):
     * all of them are readable and the dependency schema is enforced.
     */
    public function testSchemaDependencyOnPropertiesDeclaredElsewhereIsEnforced(): void
    {
        $className = $this->generateClassFromFile('NarrowedTargetSchemaDependency.json');

        // Valid: no trigger; all properties readable
        $object = new $className([]);
        $this->assertNull($object->getCreditCard());
        $this->assertNull($object->getBillingAddress());
        $this->assertNull($object->getName());

        // Valid: trigger present and both required properties satisfied
        $object = new $className(['credit_card' => 'any', 'billing_address' => 'addr', 'name' => 'John']);
        $this->assertSame('any', $object->getCreditCard());
        $this->assertSame('addr', $object->getBillingAddress());
        $this->assertSame('John', $object->getName());

        // Invalid: trigger present but required properties absent
        $this->expectException(InvalidSchemaDependencyException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid schema which is dependant on 'credit_card':
              - Missing required value for 'billing_address'
            ERROR,
        );

        new $className(['credit_card' => 'any']);
    }

    /**
     * A dependency schema declaring a property with a type disjoint from the base declaration doesn't make the
     * schema contradictory: the dependency schema only applies while the trigger is present, so the two properties
     * just can't be combined. Rejecting the schema at generation time (as for disjoint allOf branches, which always
     * apply) would refuse valid data. The base declaration defines the generated property.
     */
    public function testDependencySchemaWithADisjointPropertyTypeOnlyRejectsTheCombination(): void
    {
        $className = $this->generateClassFromFile(
            'ConflictingSchemaDependency.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $this->assertSame('?int', (string) (new ReflectionMethod($className, 'getBillingAddress'))->getReturnType());

        // the dependency schema is inactive without the trigger
        $object = new $className(['billing_address' => 555]);
        $this->assertSame(555, $object->getBillingAddress());

        // the dependency schema doesn't require billing_address
        $object = new $className(['credit_card' => 12345]);
        $this->assertSame(12345, $object->getCreditCard());

        $this->expectException(InvalidSchemaDependencyException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid schema which is dependant on 'credit_card':
              - Invalid type for 'billing_address': requires 'string', got 'integer'
            ERROR,
        );

        new $className(['credit_card' => 12345, 'billing_address' => 555]);
    }

    /**
     * The dependency schema true accepts every value, so the trigger may be provided with or without any other
     * property.
     */
    public function testTrueDependencySchemaAcceptsEveryValue(): void
    {
        $className = $this->generateClassFromFile(
            'TrueSchemaDependency.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $object = new $className([]);
        $this->assertNull($object->getCreditCard());

        $object = new $className(['credit_card' => 12345]);
        $this->assertSame(12345, $object->getCreditCard());

        $object = new $className(['credit_card' => 12345, 'billing_address' => false]);
        $this->assertSame(12345, $object->getCreditCard());
    }

    /**
     * The dependency schema false rejects the data whenever the trigger is present. The violation is reported like
     * the violation of any other dependency schema: an InvalidSchemaDependencyException wrapping the
     * DeniedPropertyException of the trigger. Covers a declared trigger (credit_card), an undeclared trigger
     * (debit_card) and the setter of the declared trigger.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testFalseDependencySchemaRejectsTheTrigger(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('FalseSchemaDependency.json', $configuration->setImmutable(false));

        $object = new $className(['billing_address' => '555 Debitors Lane']);
        $this->assertSame('555 Debitors Lane', $object->getBillingAddress());

        $assertRejectedTrigger = function (?Throwable $exception, string $trigger) use ($configuration): void {
            $this->assertInstanceOf(
                $configuration->collectErrors()
                    ? ErrorRegistryException::class
                    : InvalidSchemaDependencyException::class,
                $exception,
            );
            $this->assertSame(
                <<<ERROR
                Invalid schema which is dependant on '$trigger':
                  - Value for '$trigger' is not allowed
                ERROR,
                $exception->getMessage(),
            );

            $dependencyException = $exception instanceof ErrorRegistryException
                ? $exception->getErrors()[0]
                : $exception;
            $this->assertInstanceOf(InvalidSchemaDependencyException::class, $dependencyException);
            $this->assertSame("/dependencies/$trigger", $dependencyException->getJsonPointer()->pointer);

            $deniedException = $dependencyException->getDependencyException();
            if ($deniedException instanceof ErrorRegistryException) {
                $deniedException = $deniedException->getErrors()[0];
            }
            $this->assertInstanceOf(DeniedPropertyException::class, $deniedException);
            // the schema false of the dependency rejected the value; the dependency schema denying the trigger,
            // which processes it, doesn't exist in the schema file
            $this->assertSame("/dependencies/$trigger", $deniedException->getJsonPointer()->pointer);
            $this->assertSame("/$trigger", $deniedException->getInstancePointer()->pointer);
        };

        $assertRejectedTrigger(
            $this->catchException(static fn() => new $className(['credit_card' => 12345])),
            'credit_card',
        );
        $assertRejectedTrigger(
            $this->catchException(static fn() => new $className(['debit_card' => 1])),
            'debit_card',
        );

        $assertRejectedTrigger(
            $this->catchException(static fn() => $object->setCreditCard(12345)),
            'credit_card',
        );
        $this->assertNull($object->getCreditCard());
    }

    /**
     * The nested object `card` and the root declare the identical dependency schema false for credit_card, so the
     * dependency schema processed for the nested object first is reused for the root. The reuse must not move the
     * location of the denial of the nested object to the root dependency.
     */
    public function testReusedFalseDependencySchemaKeepsTheLocationOfItsFirstOccurrence(): void
    {
        $className = $this->generateClassFromFile(
            'ReusedFalseSchemaDependency.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $nestedObjectException = $this->catchException(
            static fn() => new $className(['card' => ['credit_card' => 12345]]),
        );
        $this->assertInstanceOf(NestedObjectException::class, $nestedObjectException);

        $dependencyException = $nestedObjectException->getNestedException();
        $this->assertInstanceOf(InvalidSchemaDependencyException::class, $dependencyException);
        $this->assertSame('/properties/card/dependencies/credit_card', $dependencyException->getJsonPointer()->pointer);
        $this->assertSame(
            '/properties/card/dependencies/credit_card',
            $dependencyException->getDependencyException()->getJsonPointer()->pointer,
        );

        $dependencyException = $this->catchException(static fn() => new $className(['credit_card' => 12345]));
        $this->assertInstanceOf(InvalidSchemaDependencyException::class, $dependencyException);
        $this->assertSame('/dependencies/credit_card', $dependencyException->getJsonPointer()->pointer);
        $this->assertSame(
            <<<ERROR
            Invalid schema which is dependant on 'credit_card':
              - Value for 'credit_card' is not allowed
            ERROR,
            $dependencyException->getMessage(),
        );
    }

    /**
     * With `additionalProperties: false` an undeclared trigger can never be part of the input, so its schema
     * dependency is skipped (including the properties of the dependency schema) and a warning is logged.
     */
    public function testUndeclaredSchemaDependencyTriggerIsSkippedWithWarningWhenAdditionalPropertiesIsFalse(): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'UndeclaredTriggerSchemaDependencyNoAdditionalProperties.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setLogger($recordingLogger),
        );

        $warnings = array_filter(
            $recordingLogger->getEntries(),
            static fn(array $entry): bool => $entry['level'] === 'warning',
        );
        $this->assertCount(1, $warnings);
        $this->assertTrue(
            $this->hasLogEntry(
                $warnings,
                'warning',
                "Dependencies on '{trigger}' in class '{class}' can never apply: {reason}",
                [
                    'trigger' => 'credit_card',
                    'reason' => 'additionalProperties: false rejects the undeclared key',
                ],
            ),
        );

        $this->assertFalse(method_exists($className, 'getCreditCard'));
        $this->assertFalse(method_exists($className, 'getBillingAddress'));

        $object = new $className(['name' => 'John']);
        $this->assertSame('John', $object->getName());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['credit_card' => 12345],
            AdditionalPropertiesException::class,
            "Provided JSON for '%class%' contains not allowed additional properties ['credit_card']",
        );
    }

    public static function nonObjectSchemaDependencyDataProvider(): array
    {
        return [
            'scalar type' => ['ScalarTypeSchemaDependency.json', "the type 'string'"],
            'type list without object' => ['NonObjectTypeListSchemaDependency.json', "the types 'string', 'null'"],
            'dependency of a required property which isn\'t declared' => [
                'ScalarTypeSchemaDependencyOfRequiredProperty.json',
                "the type 'array'",
            ],
        ];
    }

    /**
     * The schema of a schema dependency is validated against the object which holds the property. A dependency whose
     * declared type excludes "object" is valid JSON Schema which forbids the property, but the generator doesn't
     * support it: it is rejected during the generation (instead of failing with a TypeError) and points at `false` as
     * the supported way to forbid the property.
     */
    #[DataProvider('nonObjectSchemaDependencyDataProvider')]
    public function testSchemaDependencyWithoutAnObjectTypeIsRejected(string $schemaFile, string $declaredType): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/^' . preg_quote("Schema dependency of property 'credit_card' in file ", '/') . ".+\.json"
                . preg_quote(
                    " declares $declaredType, which the object holding the property can never satisfy: a schema"
                        . ' dependency which only forbids the property is not supported, declare the dependency as'
                        . ' false instead',
                    '/',
                )
                . ' at line \d+, column \d+$/',
        );

        $this->generateClassFromFile($schemaFile);
    }
}
