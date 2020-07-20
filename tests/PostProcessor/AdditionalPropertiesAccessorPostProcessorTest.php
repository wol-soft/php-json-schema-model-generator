<?php

namespace PHPModelGenerator\Tests\PostProcessor;

use DateTime;
use PHPModelGenerator\Exception\Object\InvalidAdditionalPropertiesException;
use PHPModelGenerator\Exception\Object\InvalidPropertyNamesException;
use PHPModelGenerator\Exception\Object\MaxPropertiesException;
use PHPModelGenerator\Exception\Object\MinPropertiesException;
use PHPModelGenerator\Exception\Object\RegularPropertyAsAdditionalPropertyException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\AdditionalPropertiesAccessorPostProcessor;
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

            $this->assertEmpty($this->getMethodReturnTypeAnnotation($object, 'getAdditionalProperty'));
            $this->assertNull($this->getReturnType($object, 'getAdditionalProperty'));
        }
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

        $className = $this->generateClassFromFile('AdditionalProperties.json');

        $object = new $className(['property1' => 'Hello', 'property2' => 'World']);

        $this->assertTrue(is_callable([$object, 'getAdditionalProperties']));
        $this->assertTrue(is_callable([$object, 'getAdditionalProperty']));
        $this->assertFalse(is_callable([$object, 'setAdditionalProperty']));
        $this->assertFalse(is_callable([$object, 'removeAdditionalProperty']));

        $this->assertSame(['property1' => 'Hello', 'property2' => 'World'], $object->getAdditionalProperties());
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
        $this->assertSame(
            ['property1' => 'Hello', 'property2' => 'World', 'property3' => 'Good night'],
            $object->getAdditionalProperties()
        );
        $this->assertSame(
            ['property1' => '  Hello  ', 'property2' => 'World', 'property3' => '  Good night  '],
            $object->getRawModelDataInput()
        );
        $this->assertSame('Good night', $object->getAdditionalProperty('property3'));

        // test removing an additional property
        $this->assertTrue($object->removeAdditionalProperty('property2'));
        $this->assertFalse($object->removeAdditionalProperty('property2'));
        $this->assertSame(
            ['property1' => 'Hello', 'property3' => 'Good night'],
            $object->getAdditionalProperties()
        );
        $this->assertSame(
            ['property1' => '  Hello  ', 'property3' => '  Good night  '],
            $object->getRawModelDataInput()
        );

        // test update an existing additional property
        $object->setAdditionalProperty('property3', '  !Good night!  ');
        $this->assertSame(
            ['property1' => 'Hello', 'property3' => '!Good night!'],
            $object->getAdditionalProperties()
        );
        $this->assertSame(
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

    public function testAdditionalPropertiesAreSerialized(): void
    {
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesTransformingFilter.json',
            (new GeneratorConfiguration())->setSerialization(true)->setImmutable(false)
        );

        $object = new $className(['name' => 'Late autumn', 'start' => '2020-10-10']);
        $this->assertInstanceOf(DateTime::class, $object->getAdditionalProperty('start'));
        $this->assertInstanceOf(DateTime::class, $object->getAdditionalProperties()['start']);

        $object->setAdditionalProperty('end', '2020-12-12');
        $this->assertInstanceOf(DateTime::class, $object->getAdditionalProperty('end'));
        $this->assertInstanceOf(DateTime::class, $object->getAdditionalProperties()['end']);

        $this->assertSame(['name' => 'Late autumn', 'start' => '20201010', 'end' => '20201212'], $object->toArray());

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

    public function testMultiTypeAdditionalProperties(): void
    {
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesMultiType.json',
            (new GeneratorConfiguration())->setImmutable(false)
        );

        $object = new $className(['property1' => 'Hello', 'property2' => null]);
        $this->assertNull($object->getAdditionalProperty('property2'));

        $object->setAdditionalProperty('property1', null);
        $this->assertNull($object->getAdditionalProperty('property1'));

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
    }
}
