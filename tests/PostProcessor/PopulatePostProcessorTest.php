<?php

namespace PHPModelGenerator\Tests\PostProcessor;

use Exception;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\Object\InvalidAdditionalPropertiesException;
use PHPModelGenerator\Exception\Object\InvalidPropertyNamesException;
use PHPModelGenerator\Exception\Object\MaxPropertiesException;
use PHPModelGenerator\Exception\String\PatternException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\Hook\SetterAfterValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\Hook\SetterBeforeValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

class PopulatePostProcessorTest extends AbstractPHPModelGeneratorTest
{
    public function setUp(): void
    {
        parent::setUp();

        $this->modifyModelGenerator = function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };
    }

    public function testPopulateMethod(): void
    {
        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setSerialization(true)
        );
        $object = new $className(['name' => 'Albert']);

        $this->assertTrue(is_callable([$object, 'populate']));

        // test an empty populate call doesn't change the internal behaviour
        $object->populate([]);
        $this->assertSame(['name' => 'Albert'], $object->getRawModelDataInput());
        $this->assertEqualsCanonicalizing(['name' => 'Albert', 'age' => null], $object->toArray());

        // test adding an additional property to the model
        $object->populate(['birthdate' => '10.10.1990']);
        $this->assertEqualsCanonicalizing(
            ['name' => 'Albert', 'birthdate' => '10.10.1990'],
            $object->getRawModelDataInput()
        );
        $this->assertEqualsCanonicalizing(
            ['name' => 'Albert', 'age' => null, 'birthdate' => '10.10.1990'],
            $object->toArray()
        );

        // test overwriting a single property
        $object->populate(['age' => 30]);
        $this->assertEqualsCanonicalizing(
            ['name' => 'Albert', 'birthdate' => '10.10.1990', 'age' => 30],
            $object->getRawModelDataInput()
        );
        $this->assertEqualsCanonicalizing(
            ['name' => 'Albert', 'age' => 30, 'birthdate' => '10.10.1990'],
            $object->toArray()
        );

        // test overwriting multiple properties
        $object->populate(['age' => 26, 'name' => 'Harry']);
        $this->assertEqualsCanonicalizing(
            ['name' => 'Harry', 'birthdate' => '10.10.1990', 'age' => 26],
            $object->getRawModelDataInput()
        );
        $this->assertEqualsCanonicalizing(
            ['name' => 'Harry', 'age' => 26, 'birthdate' => '10.10.1990'],
            $object->toArray()
        );
    }

    /**
     * @dataProvider implicitNullDataProvider
     *
     * @param bool $implicitNull
     */
    public function testImplicitNullCheckOnPopulateMethodForOptionalProperty(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile('BasicSchema.json', null, false, $implicitNull);

        $object = new $className(['name' => 'Albert']);

        $this->assertSame('Albert', $object->getName());
        $this->assertNull($object->getAge());

        $object->populate(['age' => 35]);
        $this->assertSame(35, $object->getAge());

        if (!$implicitNull) {
            $this->expectException(InvalidTypeException::class);
            $this->expectExceptionMessage('Invalid type for age. Requires int, got NULL');
        }

        $object->populate(['age' => null]);
        $this->assertNull($object->getAge());
    }

    /**
     * @dataProvider implicitNullDataProvider
     *
     * @param bool $implicitNull
     */
    public function testImplicitNullCheckOnPopulateMethodForOptionalRequiredProperty(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile('BasicSchema.json', null, false, $implicitNull);

        $object = new $className(['name' => 'Albert']);

        $this->assertSame('Albert', $object->getName());
        $this->assertNull($object->getAge());

        $object->populate(['name' => 'Hannes']);
        $this->assertSame('Hannes', $object->getName());

        $this->expectException(InvalidTypeException::class);
        $this->expectExceptionMessage('Invalid type for name. Requires string, got NULL');

        $object->populate(['name' => null]);
    }

    /**
     * @dataProvider invalidPopulateDataProvider
     */
    public function testInvalidPopulateThrowsAnException(
        array $data,
        bool $collectErrors,
        string $expectedException,
        string $expectedMessage
    ): void {
        $this->expectException($expectedException);
        $this->expectExceptionMessage($expectedMessage);

        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setCollectErrors($collectErrors)->setSerialization(true)
        );
        $object = new $className(['name' => 'Albert', 'age' => 30]);

        try {
            $object->populate($data);
            $this->fail('No exception thrown');
        } catch (Exception $exception) {
            // test if the internal state hasn't been changed
            $this->assertEqualsCanonicalizing(['name' => 'Albert', 'age' => 30], $object->getRawModelDataInput());
            $this->assertEqualsCanonicalizing(['name' => 'Albert', 'age' => 30], $object->toArray());

            throw $exception;
        }
    }

    public function invalidPopulateDataProvider(): array
    {
        return [
            'No error collection - multiple violations' => [
                ['name' => 'Anne-Marie', 'age' => false],
                false,
                PatternException::class,
                "Value for name doesn't match pattern ^[a-zA-Z]*$"
            ],
            'No error collection - first value ok second invalid' => [
                ['name' => 'Hannes', 'age' => false],
                false,
                InvalidTypeException::class,
                "Invalid type for age. Requires int, got boolean"
            ],
            'Error collection - first value ok second invalid' => [
                ['name' => 'Hannes', 'age' => false],
                true,
                ErrorRegistryException::class,
                "Invalid type for age. Requires int, got boolean"
            ],
            'Error collection - multiple violations' => [
                ['name' => 'Anne-Marie', 'age' => false],
                true,
                ErrorRegistryException::class,
                "Value for name doesn't match pattern ^[a-zA-Z]*$
Value for name must not be longer than 8
Invalid type for age. Requires int, got boolean"
            ],
            'Invalid additional property' => [
                ['numeric' => 9],
                false,
                InvalidAdditionalPropertiesException::class,
                "contains invalid additional properties.
  - invalid additional property 'numeric'
    * Invalid type for additional property. Requires string, got integer"
            ],
            'Invalid additional property name' => [
                ['invalid name' => 'Hi'],
                false,
                InvalidPropertyNamesException::class,
                "contains properties with invalid names.
  - invalid property 'invalid name'
    * Value for property name doesn't match pattern ^[a-zA-Z]*$"
            ],
            'Too many properties' => [
                ['additional' => 'Hi', 'another' => 'Ho'],
                false,
                MaxPropertiesException::class,
                "must not contain more than 3 properties"
            ],
        ];
    }

    public function testSetterBeforeValidationHookInsidePopulateIsResolved(): void
    {
        $this->modifyModelGenerator = function (ModelGenerator $modelGenerator): void {
            $modelGenerator->addPostProcessor(new PopulatePostProcessor());
            $modelGenerator->addPostProcessor(new class () extends PostProcessor {
                public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
                {
                    $schema->addSchemaHook(new class () implements SetterBeforeValidationHookInterface {
                        public function getCode(PropertyInterface $property, bool $batchUpdate = false): string
                        {
                            return $property->getName() === 'age'
                                ? 'throw new \Exception("SetterBeforeValidationHook");'
                                : '';
                        }
                    });
                }
            });
        };

        $className = $this->generateClassFromFile('BasicSchema.json');

        $object = new $className(['name' => 'Albert', 'age' => 35]);
        $object->populate(['name' => 'Hannes']);

        // make sure the setter logic is not executed if the value is not updated due to identical values
        $object->populate(['age' => 35]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("SetterBeforeValidationHook");

        $object->populate(['age' => 40]);
    }

    /**
     * @dataProvider setterAfterValidationHookDataProvider
     *
     * @param string|null $expectedException
     * @param string|null $expectedExceptionMessage
     * @param array $populateValues
     */
    public function testSetterAfterValidationHookInsidePopulateIsResolved(
        ?string $expectedException,
        ?string $expectedExceptionMessage,
        array $populateValues
    ): void
    {
        $this->modifyModelGenerator = function (ModelGenerator $modelGenerator): void {
            $modelGenerator->addPostProcessor(new PopulatePostProcessor());
            $modelGenerator->addPostProcessor(new class () extends PostProcessor {
                public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
                {
                    $schema->addSchemaHook(new class () implements SetterAfterValidationHookInterface {
                        public function getCode(PropertyInterface $property, bool $batchUpdate = false): string
                        {
                            return $property->getName() === 'age'
                                ? 'throw new \Exception("SetterAfterValidationHook");'
                                : '';
                        }
                    });
                }
            });
        };

        $className = $this->generateClassFromFile('BasicSchema.json');

        $object = new $className(['name' => 'Albert', 'age' => 35]);

        if ($expectedException) {
            $this->expectException($expectedException);
            $this->expectExceptionMessage($expectedExceptionMessage);
        } else {
            $this->expectNotToPerformAssertions();
        }

        $object->populate($populateValues);
    }

    public function setterAfterValidationHookDataProvider(): array
    {
        return [
            'update not hooked value valid' => [
                null,
                null,
                ['name' => 'Hannes'],
            ],
            'update not hooked value invalid' => [
                InvalidTypeException::class,
                'Invalid type for name. Requires string, got boolean',
                ['name' => false],
            ],
            'update hooked value not changed' => [
                null,
                null,
                ['age' => 35],
            ],
            'update hooked value valid' => [
                Exception::class,
                'SetterAfterValidationHook',
                ['age' => 40],
            ],
            'update hooked value invalid' => [
                InvalidTypeException::class,
                'Invalid type for age. Requires int, got boolean',
                ['age' => false],
            ],
        ];
    }

    /**
     * @dataProvider compositionValidationInPopulateDataProvider
     *
     * @param GeneratorConfiguration $generatorConfiguration
     * @param string $exceptionMessageBothValid
     * @param string $exceptionMessageBothInvalid
     */
    public function testPopulateComposition(
        GeneratorConfiguration $generatorConfiguration,
        string $exceptionMessageBothValid,
        string $exceptionMessageBothInvalid
    ): void {
        $className = $this->generateClassFromFile(
            'ObjectLevelCompositionRequired.json',
            $generatorConfiguration->setImmutable(false)
        );

        $object = new $className(['integerProperty' => 2, 'stringProperty' => 99]);

        // test a valid change
        $object->populate(['integerProperty' => 4]);
        $this->assertSame(4, $object->getIntegerProperty());
        $this->assertNull($object->getStringProperty());

        // test an invalid change (both properties valid)
        try {
            $object->populate(['stringProperty' => 'Hello']);
            $this->fail('Exception not thrown');
        } catch (ErrorRegistryException | OneOfException $exception) {
            $this->assertStringContainsString($exceptionMessageBothValid, $exception->getMessage());
        }

        // test an invalid change (both properties invalid)
        try {
            $object->populate(['integerProperty' => null]);
            $this->fail('Exception not thrown');
        } catch (ErrorRegistryException | OneOfException $exception) {
            $this->assertStringContainsString($exceptionMessageBothInvalid, $exception->getMessage());
        }

        // make sure the internal state of the object hasn't changed after invalid accesses
        $this->assertSame(4, $object->getIntegerProperty());
        $this->assertNull($object->getStringProperty());

        // test valid changes again to make sure the internal validation state is correct after invalid accesses
        $object->populate(['integerProperty' => 6]);
        $this->assertSame(6, $object->getIntegerProperty());
        $this->assertNull($object->getStringProperty());

        $object->populate(['stringProperty' => null]);
        $this->assertSame(6, $object->getIntegerProperty());
        $this->assertNull($object->getStringProperty());

        // test an invalid change (both properties valid in a single call)
        try {
            $object->populate(['stringProperty' => 'Hello', 'integerProperty' => 10]);
            $this->fail('Exception not thrown');
        } catch (ErrorRegistryException | OneOfException $exception) {
            $this->assertStringContainsString($exceptionMessageBothValid, $exception->getMessage());
        }

        // test updating both values of the composition in a single populate call
        $object->populate(['stringProperty' => 'Hello', 'integerProperty' => null]);
        $this->assertNull($object->getIntegerProperty());
        $this->assertSame('Hello', $object->getStringProperty());
    }

    public function compositionValidationInPopulateDataProvider(): array
    {
        return [
            'Exception Collection' => [
                (new GeneratorConfiguration())->setCollectErrors(true),
                <<<ERROR
declined by composition constraint.
  Requires to match one composition element but matched 2 elements.
  - Composition element #1: Valid
  - Composition element #2: Valid
ERROR
                ,
                <<<ERROR
declined by composition constraint.
  Requires to match one composition element but matched 0 elements.
  - Composition element #1: Failed
    * Invalid type for stringProperty. Requires string, got integer
  - Composition element #2: Failed
    * Invalid type for integerProperty. Requires int, got NULL
ERROR
            ],
            'Direct Exception' => [
                (new GeneratorConfiguration())->setCollectErrors(false),
                <<<ERROR
declined by composition constraint.
  Requires to match one composition element but matched 2 elements.
ERROR
                ,
                <<<ERROR
declined by composition constraint.
  Requires to match one composition element but matched 0 elements.
ERROR
            ],
        ];
    }
}
