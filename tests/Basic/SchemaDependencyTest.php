<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\Dependency\InvalidSchemaDependencyException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Object\UnevaluatedPropertiesException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\String\MinLengthException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * Class SchemaDependencyTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
#[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
class SchemaDependencyTest extends AbstractPHPModelGeneratorTestCase
{
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
        string $message,
    ): void {
        $className = $this->generateClassFromFile('SchemaDependency.json', $configuration);

        try {
            new $className($propertyValue);
            $this->fail('Expected exception for invalid schema dependency');
        } catch (ErrorRegistryException | InvalidSchemaDependencyException $exception) {
            // collectErrors(true) appends additional collected errors after the expected message,
            // matching the substring-style assertion the original expectExceptionMessage() used.
            $this->assertStringContainsString($message, $exception->getMessage());

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
                ],
                'required attribute not provided 2' => [
                    ['credit_card' => 12345, 'billing_address' => '555 Debitors Lane'],
                    <<<ERROR
                    Invalid schema which is dependant on 'credit_card':
                      - Missing required value for 'date_of_birth'
                    ERROR,
                ],
                'invalid data type' => [
                    ['credit_card' => 12345, 'date_of_birth' => false],
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
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches("/$message/m");

        $className = $this->generateClassFromFile(
            'NestedObjectSchemaDependency.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        new $className($propertyValue);
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
            "Invalid schema which is dependant on 'credit_card':\n  - Missing required value for 'billing_address'",
        );

        new $className(['credit_card' => 12345]);
    }

    /**
     * A `dependencies` trigger that is declared neither in `properties` nor `required` was
     * silently dropped instead of being enforced — the schema-form variant of
     * testDependencyTriggerNotDeclaredInPropertiesOrRequiredIsEnforced in PropertyDependencyTest.
     * Restricted to Draft 7 to avoid entangling this with the separate Draft-gating defect.
     *
     * Also asserts that the trigger property and the properties declared inside the dependency
     * subschema are both synthesised onto the generated class and accessible via their accessors,
     * even though neither appears in the schema's top-level `properties` block.
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
            "Invalid schema which is dependant on credit_card:\n  - Missing required value for billing_address",
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
     * When a schema-form dependency trigger is absent from the root `properties` block and its
     * subschema declares properties that are already present — either in root `properties` or via
     * a composition branch — the trigger is synthesised as mixed while each subschema property is
     * merged with its pre-existing declaration (same path as allOf property merging) rather than
     * being introduced anew. Covers root-property merging (billing_address) and composition
     * merging (name via allOf). Restricted to Draft 7.
     */
    public function testSchemaDependencySubschemaPropertiesMergeWithPreExistingDeclarations(): void
    {
        $className = $this->generateClassFromFile('NarrowedTargetSchemaDependency.json');

        // Valid: no trigger; all properties readable with their declared types
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
            "Invalid schema which is dependant on credit_card:\n  - Missing required value for billing_address",
        );

        new $className(['credit_card' => 'any']);
    }

    /**
     * When a dep-subschema declares a property whose type contradicts the type already declared
     * for that property in the root schema, generation must fail with SchemaException. The
     * contradiction is unsatisfiable: the root schema constrains billing_address to integer while
     * the dependency subschema for credit_card constrains it to string. Restricted to Draft 7.
     */
    public function testSchemaDependencyConflictingPropertyTypeThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $conflictPattern = "/^Property 'billing_address' is defined with conflicting types"
            . " between the dependency schema for 'credit_card' and the base schema in file /";
        $this->expectExceptionMessageMatches($conflictPattern);

        $this->generateClassFromFile('ConflictingSchemaDependency.json');
    }

    /**
     * Under Draft 2019-09, the legacy schema-form `dependencies` must be inert — it is not part
     * of the 2019-09 vocabulary — so omitting the dependency's required property must NOT be
     * rejected. It was incorrectly rejected because `dependencies` fired regardless of the active
     * Draft, and once it fired the resulting `billing_address` requirement was enforced even
     * though `unevaluatedProperties` (the keyword meant to be active here) never saw it.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testSchemaDependencyDoesNotWronglyRejectValidDraft201909Instance(): void
    {
        $className = $this->generateClassFromFile('SchemaDependencyWithUnevaluatedProperties.json');

        $object = new $className(['credit_card' => 12345]);

        $this->assertSame(12345, $object->getCreditCard());
    }

    /**
     * Companion to testSchemaDependencyDoesNotWronglyRejectValidDraft201909Instance: a property
     * that exists only because the (now-inert) dependency subschema declares it must still be
     * rejected by `unevaluatedProperties`. This assertion holds both before and after the fix —
     * before, it was rejected for the wrong reason (never added to the evaluated-keys set);
     * after, it is rejected because the keyword that would have introduced it is correctly inert.
     * Kept as a guard against the fix overcorrecting into silently accepting everything.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testSchemaDependencyIntroducedPropertyStillRejectedByUnevaluatedProperties(): void
    {
        $className = $this->generateClassFromFile('SchemaDependencyWithUnevaluatedProperties.json');

        $this->expectException(UnevaluatedPropertiesException::class);
        $this->expectExceptionMessageMatches('/contains not allowed unevaluated properties \[billing_address\]/');

        new $className(['credit_card' => 12345, 'billing_address' => '555 Debitors Lane']);
    }

    /**
     * Regression guard: removing `dependencies` from Draft 2019-09 must not disable it under
     * Draft 7. The `unevaluatedProperties` keyword present in this fixture is simply unrecognised
     * (ignored) under Draft 7, so `dependencies` must enforce exactly as it does without that
     * keyword present at all.
     */
    public function testSchemaDependencyStillEnforcedUnderDraft07RegardlessOfUnevaluatedPropertiesKeyword(): void
    {
        $className = $this->generateClassFromFile('SchemaDependencyWithUnevaluatedProperties.json');

        $this->expectException(InvalidSchemaDependencyException::class);
        $this->expectExceptionMessage(
            "Invalid schema which is dependant on credit_card:\n  - Missing required value for billing_address",
        );

        new $className(['credit_card' => 12345]);
    }
}
