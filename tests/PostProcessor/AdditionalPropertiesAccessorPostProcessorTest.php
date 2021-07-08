<?php

namespace PHPModelGenerator\Tests\PostProcessor;

use DateTime;
use Exception;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Object\InvalidAdditionalPropertiesException;
use PHPModelGenerator\Exception\Object\InvalidPropertyNamesException;
use PHPModelGenerator\Exception\Object\MaxPropertiesException;
use PHPModelGenerator\Exception\Object\MinPropertiesException;
use PHPModelGenerator\Exception\Object\RegularPropertyAsAdditionalPropertyException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\Hook\SetterBeforeValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\PostProcessor\AdditionalPropertiesAccessorPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

/**
 * Class AdditionalPropertiesAccessorPostProcessorTest
 *
 * @package PHPModelGenerator\Tests\PostProcessor
 */
class AdditionalPropertiesAccessorPostProcessorTest extends AbstractPHPModelGeneratorTest
{
    protected function addPostProcessor(bool $addForModelsWithoutAdditionalPropertiesDefinition)
    {
        $this->modifyModelGenerator = function (ModelGenerator $generator) use (
            $addForModelsWithoutAdditionalPropertiesDefinition
        ) {
            $generator->addPostProcessor(
                new AdditionalPropertiesAccessorPostProcessor($addForModelsWithoutAdditionalPropertiesDefinition)
            );
        };
    }

    /**
     * @dataProvider additionalPropertiesAccessorPostProcessorConfigurationDataProvider
     *
     * @param bool $addForModelsWithoutAdditionalPropertiesDefinition
     */
    public function testAdditionalPropertiesAccessorsAreNotGeneratedForAdditionalPropertiesFalse(
        bool $addForModelsWithoutAdditionalPropertiesDefinition
    ): void {
        $this->addPostProcessor($addForModelsWithoutAdditionalPropertiesDefinition);

        $className = $this->generateClassFromFile('AdditionalPropertiesFalse.json');

        $object = new $className();

        $this->assertFalse(is_callable([$object, 'getAdditionalProperties']));
        $this->assertFalse(is_callable([$object, 'getAdditionalProperty']));
        $this->assertFalse(is_callable([$object, 'setAdditionalProperty']));
        $this->assertFalse(is_callable([$object, 'removeAdditionalProperty']));
    }

    /**
     * @dataProvider additionalPropertiesAccessorPostProcessorConfigurationDataProvider
     *
     * @param bool $addForModelsWithoutAdditionalPropertiesDefinition
     */
    public function testAdditionalPropertiesAccessorsAreNotGeneratedWhenAdditionalPropertiesAreDenied(
        bool $addForModelsWithoutAdditionalPropertiesDefinition
    ): void {
        $this->addPostProcessor($addForModelsWithoutAdditionalPropertiesDefinition);

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesNotDefined.json',
            (new GeneratorConfiguration())->setDenyAdditionalProperties(true)
        );

        $object = new $className();

        $this->assertFalse(is_callable([$object, 'getAdditionalProperties']));
        $this->assertFalse(is_callable([$object, 'getAdditionalProperty']));
        $this->assertFalse(is_callable([$object, 'setAdditionalProperty']));
        $this->assertFalse(is_callable([$object, 'removeAdditionalProperty']));
    }

    /**
     * @dataProvider additionalPropertiesAccessorPostProcessorConfigurationDataProvider
     *
     * @param bool $addForModelsWithoutAdditionalPropertiesDefinition
     */
    public function testAdditionalPropertiesAccessorsDependOnConfigurationForAdditionalPropertiesNotDefined(
        bool $addForModelsWithoutAdditionalPropertiesDefinition
    ): void {
        $this->addPostProcessor($addForModelsWithoutAdditionalPropertiesDefinition);

        $className = $this->generateClassFromFile('AdditionalPropertiesNotDefined.json');

        $object = new $className();

        $this->assertSame(
            $addForModelsWithoutAdditionalPropertiesDefinition,
            is_callable([$object, 'getAdditionalProperties'])
        );
        $this->assertSame(
            $addForModelsWithoutAdditionalPropertiesDefinition,
            is_callable([$object, 'getAdditionalProperty'])
        );
        $this->assertFalse(is_callable([$object, 'setAdditionalProperty']));
        $this->assertFalse(is_callable([$object, 'removeAdditionalProperty']));

        if ($addForModelsWithoutAdditionalPropertiesDefinition) {
            $this->assertSame('array', $this->getMethodReturnTypeAnnotation($object, 'getAdditionalProperties'));
            $returnType = $this->getReturnType($object, 'getAdditionalProperties');
            $this->assertSame('array', $returnType->getName());
            $this->assertFalse($returnType->allowsNull());

            $this->assertSame('mixed', $this->getMethodReturnTypeAnnotation($object, 'getAdditionalProperty'));
            $this->assertNull($this->getReturnType($object, 'getAdditionalProperty'));
        }
    }

    public function testAdditionalPropertiesSettersForMutableObjectsWithoutAdditionalPropertiesDefinition(): void
    {
        $this->addPostProcessor(true);
        $className = $this->generateClassFromFile(
            'AdditionalPropertiesNotDefined.json',
            (new GeneratorConfiguration())->setImmutable(false)
        );

        $object = new $className(['property1' => 100]);

        $this->assertSame('mixed', $this->getMethodParameterTypeAnnotation($object, 'setAdditionalProperty', 1));
        $this->assertNull($this->getParameterType($object, 'setAdditionalProperty', 1));
        $this->assertSame('mixed', $this->getMethodReturnTypeAnnotation($object, 'getAdditionalProperty'));

        $this->assertSame(100, $object->getAdditionalProperty('property1'));
        $this->assertEqualsCanonicalizing(['property1' => 100], $object->getAdditionalProperties());

        $object->setAdditionalProperty('property2', 200);
        $this->assertEqualsCanonicalizing(['property1' => 100, 'property2' => 200], $object->getAdditionalProperties());

        $object->setAdditionalProperty('property1', 10);
        $this->assertEqualsCanonicalizing(['property1' => 10, 'property2' => 200], $object->getAdditionalProperties());

        $object->removeAdditionalProperty('property1');
        $this->assertEqualsCanonicalizing(['property2' => 200], $object->getAdditionalProperties());
        $this->assertNull($object->getAdditionalProperty('property1'));
    }

    /**
     * @dataProvider additionalPropertiesAccessorPostProcessorConfigurationDataProvider
     *
     * @param bool $addForModelsWithoutAdditionalPropertiesDefinition
     */
    public function testAdditionalPropertiesAccessorsAreGeneratedForAdditionalProperties(
        bool $addForModelsWithoutAdditionalPropertiesDefinition
    ): void {
        $this->addPostProcessor($addForModelsWithoutAdditionalPropertiesDefinition);

        $className = $this->generateClassFromFile(
            'AdditionalProperties.json',
            // make sure the deny additional properties setting doesn't affect specified additional properties
            (new GeneratorConfiguration())->setDenyAdditionalProperties(true)
        );

        $object = new $className(['property1' => 'Hello', 'property2' => 'World']);

        $this->assertTrue(is_callable([$object, 'getAdditionalProperties']));
        $this->assertTrue(is_callable([$object, 'getAdditionalProperty']));
        $this->assertFalse(is_callable([$object, 'setAdditionalProperty']));
        $this->assertFalse(is_callable([$object, 'removeAdditionalProperty']));

        $this->assertEqualsCanonicalizing(
            ['property1' => 'Hello', 'property2' => 'World'],
             $object->getAdditionalProperties()
         );
        $this->assertSame('Hello', $object->getAdditionalProperty('property1'));
        $this->assertSame('World', $object->getAdditionalProperty('property2'));
        $this->assertNull($object->getAdditionalProperty('property3'));
    }

    public function additionalPropertiesAccessorPostProcessorConfigurationDataProvider(): array
    {
        return [
            'Add also for models without additional properties definition' => [true],
            'Add only for models with additional properties definition' => [false],
        ];
    }

    public function testAdditionalPropertiesModifiersAreGeneratedForMutableObjects(): void {
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'AdditionalProperties.json',
            (new GeneratorConfiguration())->setImmutable(false)
        );

        $object = new $className(['property1' => '  Hello  ', 'property2' => 'World']);

        $this->assertTrue(is_callable([$object, 'getAdditionalProperties']));
        $this->assertTrue(is_callable([$object, 'getAdditionalProperty']));
        $this->assertTrue(is_callable([$object, 'setAdditionalProperty']));
        $this->assertTrue(is_callable([$object, 'removeAdditionalProperty']));

        // test adding a new additional property
        $object->setAdditionalProperty('property3', '  Good night  ');
        $this->assertEqualsCanonicalizing(
            ['property1' => 'Hello', 'property2' => 'World', 'property3' => 'Good night'],
            $object->getAdditionalProperties()
        );
        $this->assertEqualsCanonicalizing(
            ['property1' => '  Hello  ', 'property2' => 'World', 'property3' => '  Good night  '],
            $object->getRawModelDataInput()
        );
        $this->assertSame('Good night', $object->getAdditionalProperty('property3'));

        // test removing an additional property
        $this->assertTrue($object->removeAdditionalProperty('property2'));
        $this->assertFalse($object->removeAdditionalProperty('property2'));
        $this->assertEqualsCanonicalizing(
            ['property1' => 'Hello', 'property3' => 'Good night'],
            $object->getAdditionalProperties()
        );
        $this->assertEqualsCanonicalizing(
            ['property1' => '  Hello  ', 'property3' => '  Good night  '],
            $object->getRawModelDataInput()
        );

        // test update an existing additional property
        $object->setAdditionalProperty('property3', '  !Good night!  ');
        $this->assertEqualsCanonicalizing(
            ['property1' => 'Hello', 'property3' => '!Good night!'],
            $object->getAdditionalProperties()
        );
        $this->assertEqualsCanonicalizing(
            ['property1' => '  Hello  ', 'property3' => '  !Good night!  '],
            $object->getRawModelDataInput()
        );
        $this->assertSame('!Good night!', $object->getAdditionalProperty('property3'));

        // test typing
        $this->assertSame('string[]', $this->getMethodReturnTypeAnnotation($object, 'getAdditionalProperties'));
        $returnType = $this->getReturnType($object, 'getAdditionalProperties');
        $this->assertSame('array', $returnType->getName());
        $this->assertFalse($returnType->allowsNull());

        $this->assertSame('string|null', $this->getMethodReturnTypeAnnotation($object, 'getAdditionalProperty'));
        $returnType = $this->getReturnType($object, 'getAdditionalProperty');
        $this->assertSame('string', $returnType->getName());
        $this->assertTrue($returnType->allowsNull());

        $this->assertSame('string', $this->getMethodParameterTypeAnnotation($object, 'setAdditionalProperty', 1));
        $parameterType = $this->getParameterType($object, 'setAdditionalProperty', 1);
        $this->assertSame('string', $parameterType->getName());
        $this->assertFalse($parameterType->allowsNull());
    }

    /**
     * @dataProvider invalidAdditionalPropertyDataProvider
     *
     * @param string $expectedException
     * @param string $expectedExceptionMessage
     * @param string $action
     * @param array $items
     */
    public function testInvalidAdditionalPropertyThrowsAnException(
        string $expectedException,
        string $expectedExceptionMessage,
        string $action,
        array $items
    ): void {
        $this->expectException($expectedException);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'AdditionalProperties.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false)
        );

        $object = new $className(['property1' => '  Hello  ', 'property2' => 'World']);

        foreach ($items as $property => $value) {
            $action === 'remove'
                ? $object->removeAdditionalProperty($value)
                : $object->setAdditionalProperty($property, $value);
        }
    }

    public function invalidAdditionalPropertyDataProvider(): array
    {
        return [
            'regular object property' => [
                RegularPropertyAsAdditionalPropertyException::class,
                "Couldn't add regular property name as additional property to object ",
                'add',
                ['name' => 'Hannes'],
            ],
            'min properties violation' => [
                MinPropertiesException::class,
                'must not contain less than 2 properties',
                'remove',
                ['property1']
            ],
            'max properties violation' => [
                MaxPropertiesException::class,
                'must not contain more than 4 properties',
                'add',
                ['property3' => 'Bye', 'property4' => 'Ciao', 'property5' => 'fails']
            ],
            'Invalid property name' => [
                InvalidPropertyNamesException::class,
                "contains properties with invalid names",
                'add',
                ['property name with spaces' => 'should fail']
            ],
            'Invalid property value' => [
                InvalidAdditionalPropertiesException::class,
                "Value for additional property must not be longer than 15",
                'add',
                ['property2' => 'My much too long property value will fail the validation']
            ],
        ];
    }

    public function testSetterSchemaHooksAreResolvedInSetAdditionalProperties(): void
    {
        $this->modifyModelGenerator = function (ModelGenerator $modelGenerator): void {
            $modelGenerator
                ->addPostProcessor(new AdditionalPropertiesAccessorPostProcessor())
                ->addPostProcessor(new class () extends PostProcessor {
                    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
                    {
                        $schema->addSchemaHook(new class () implements SetterBeforeValidationHookInterface {
                            public function getCode(PropertyInterface $property, bool $batchUpdate = false): string
                            {
                                return 'throw new \Exception("SetterBeforeValidationHook");';
                            }
                        });
                    }
                });
        };

        $className = $this->generateClassFromFile(
            'AdditionalProperties.json',
            (new GeneratorConfiguration())->setImmutable(false)
        );

        $object = new $className(['property1' => 'Hello', 'property2' => 'World']);
        $object->setAdditionalProperty('property1', 'Hello');
        $this->assertSame('Hello', $object->getAdditionalProperty('property1'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('SetterBeforeValidationHook');
        $object->setAdditionalProperty('property1', 'Goodbye');
    }

    /**
     * @dataProvider implicitNullDataProvider
     *
     * @param bool $implicitNull
     */
    public function testAdditionalPropertiesAreSerialized(bool $implicitNull): void
    {
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesTransformingFilter.json',
            (new GeneratorConfiguration())->setSerialization(true)->setImmutable(false),
            false,
            $implicitNull
        );

        $object = new $className(['name' => 'Late autumn', 'start' => '2020-10-10']);
        $this->assertInstanceOf(DateTime::class, $object->getAdditionalProperty('start'));
        $this->assertInstanceOf(DateTime::class, $object->getAdditionalProperties()['start']);

        $object->setAdditionalProperty('end', '2020-12-12');
        $this->assertInstanceOf(DateTime::class, $object->getAdditionalProperty('end'));
        $this->assertInstanceOf(DateTime::class, $object->getAdditionalProperties()['end']);

        $this->assertEqualsCanonicalizing(
            ['name' => 'Late autumn', 'start' => '20201010', 'end' => '20201212'],
            $object->toArray()
        );

        // test adding a transformed value
        $object->setAdditionalProperty('now', new DateTime());
        $this->assertInstanceOf(DateTime::class, $object->getAdditionalProperty('now'));

        // test typing
        $this->assertSame('DateTime[]', $this->getMethodReturnTypeAnnotation($object, 'getAdditionalProperties'));
        $returnType = $this->getReturnType($object, 'getAdditionalProperties');
        $this->assertSame('array', $returnType->getName());
        $this->assertFalse($returnType->allowsNull());

        $this->assertSame('DateTime|null', $this->getMethodReturnTypeAnnotation($object, 'getAdditionalProperty'));
        $returnType = $this->getReturnType($object, 'getAdditionalProperty');
        $this->assertSame('DateTime', $returnType->getName());
        $this->assertTrue($returnType->allowsNull());

        $this->assertSame(
            'string|DateTime',
            $this->getMethodParameterTypeAnnotation($object, 'setAdditionalProperty', 1)
        );

        $this->assertNull($this->getParameterType($object, 'setAdditionalProperty', 1));
    }


    public function testAdditionalPropertiesAreSerializedWithoutAdditionalPropertiesAccessorPostProcessor(): void
    {
        $this->modifyModelGenerator = function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesTransformingFilter.json',
            (new GeneratorConfiguration())->setSerialization(true)
        );

        $object = new $className(['name' => 'Late autumn', 'start' => '2020-10-10']);
        $this->assertEqualsCanonicalizing(['name' => 'Late autumn', 'start' => '20201010'], $object->toArray());

        $object->populate(['end' => '20201212']);
        $this->assertEqualsCanonicalizing(
            ['name' => 'Late autumn', 'start' => '20201010', 'end' => '20201212'],
            $object->toArray()
        );
    }

    public function testAdditionalPropertiesAreNotSerializedWhenNotDefinedWithoutExplicitAccessorMethods(): void
    {
        $this->modifyModelGenerator = function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesNotDefined.json',
            (new GeneratorConfiguration())->setSerialization(true)
        );

        $object = new $className(['a' => 1, 'b' => 2]);
        $this->assertSame([], $object->toArray());

        $object->populate(['a' => 3, 'c' => 4]);
        $this->assertSame([], $object->toArray());
    }

    public function testAdditionalPropertiesAreSerializedWhenNotDefinedWithExplicitAccessorMethods(): void
    {
        $this->modifyModelGenerator = function (ModelGenerator $generator): void {
            $generator
                ->addPostProcessor(new PopulatePostProcessor())
                ->addPostProcessor(new AdditionalPropertiesAccessorPostProcessor(true));
        };

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesNotDefined.json',
            (new GeneratorConfiguration())->setSerialization(true)
        );

        $object = new $className(['a' => 1, 'b' => 2]);
        $this->assertEqualsCanonicalizing(['a' => 1, 'b' => 2], $object->toArray());

        $object->populate(['a' => 3, 'c' => 4]);
        $this->assertEqualsCanonicalizing(['a' => 3, 'b' => 2, 'c' => 4], $object->toArray());
    }

    public function testMultiTypeAdditionalProperties(): void
    {
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesMultiType.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false)
        );

        $object = new $className(['property1' => 'Hello', 'property2' => null]);
        $this->assertSame('Hello', $object->getAdditionalProperty('property1'));
        $this->assertNull($object->getAdditionalProperty('property2'));

        $object->setAdditionalProperty('property1', null);
        $this->assertNull($object->getAdditionalProperty('property1'));
        $object->setAdditionalProperty('property1', 5);
        $this->assertSame(5, $object->getAdditionalProperty('property1'));
        $object->setAdditionalProperty('property1', 'Goodbye');
        $this->assertSame('Goodbye', $object->getAdditionalProperty('property1'));

        // test typing
        $this->assertSame(
            'string[]|int[]|null[]',
            $this->getMethodReturnTypeAnnotation($object, 'getAdditionalProperties')
        );
        $returnType = $this->getReturnType($object, 'getAdditionalProperties');
        $this->assertSame('array', $returnType->getName());
        $this->assertFalse($returnType->allowsNull());

        $this->assertSame(
            'string|int|null',
            $this->getMethodReturnTypeAnnotation($object, 'getAdditionalProperty')
        );
        $this->assertNull($this->getReturnType($object, 'getAdditionalProperty'));

        $this->assertSame(
            'string|int|null',
            $this->getMethodParameterTypeAnnotation($object, 'setAdditionalProperty', 1)
        );
        $this->assertNull($this->getParameterType($object, 'setAdditionalProperty', 1));

        // test setting an invalid type for the additional property
        $this->expectException(InvalidAdditionalPropertiesException::class);
        $this->expectExceptionMessage(
            'Invalid type for additional property. Requires [string, int, null], got boolean'
        );
        $object->setAdditionalProperty('property1', false);
    }

    public function testComposedAdditionalProperties(): void
    {
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesComposition.json',
            (new GeneratorConfiguration())->setImmutable(false)
        );

        $object = new $className(['property1' => 'Hello', 'property2' => 12345]);
        $this->assertSame('Hello', $object->getAdditionalProperty('property1'));
        $this->assertSame(12345, $object->getAdditionalProperty('property2'));

        $object->setAdditionalProperty('property1', 5);
        $this->assertSame(5, $object->getAdditionalProperty('property1'));
        $object->setAdditionalProperty('property1', 'Goodbye');
        $this->assertSame('Goodbye', $object->getAdditionalProperty('property1'));

        // test typing
        $this->assertSame(
            'string[]|int[]',
            $this->getMethodReturnTypeAnnotation($object, 'getAdditionalProperties')
        );
        $returnType = $this->getReturnType($object, 'getAdditionalProperties');
        $this->assertSame('array', $returnType->getName());
        $this->assertFalse($returnType->allowsNull());

        $this->assertSame(
            'string|int|null',
            $this->getMethodReturnTypeAnnotation($object, 'getAdditionalProperty')
        );
        $this->assertNull($this->getReturnType($object, 'getAdditionalProperty'));

        $this->assertSame(
            'string|int',
            $this->getMethodParameterTypeAnnotation($object, 'setAdditionalProperty', 1)
        );
        $this->assertNull($this->getParameterType($object, 'setAdditionalProperty', 1));

        // test setting an invalid type for the additional property
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessage(<<<ERROR
- invalid additional property 'property1'
    * Invalid value for additional property declined by composition constraint.
      Requires to match one composition element but matched 0 elements.
      - Composition element #1: Failed
        * Invalid type for additional property. Requires string, got NULL
      - Composition element #2: Failed
        * Invalid type for additional property. Requires int, got NULL
ERROR
        );
        $object->setAdditionalProperty('property1', null);
    }
}
