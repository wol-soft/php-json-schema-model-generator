<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\PostProcessor;

use DateTime;
use Exception;
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
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AdditionalPropertiesAccessorPostProcessorTest extends AbstractPHPModelGeneratorTestCase
{
    protected function addPostProcessor(bool $addForModelsWithoutAdditionalPropertiesDefinition): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator) use (
            $addForModelsWithoutAdditionalPropertiesDefinition,
        ): void {
            $generator->addPostProcessor(
                new AdditionalPropertiesAccessorPostProcessor($addForModelsWithoutAdditionalPropertiesDefinition),
            );
        };
    }

    #[DataProvider('additionalPropertiesAccessorPostProcessorConfigurationDataProvider')]
    public function testAdditionalPropertiesAccessorsAreNotGeneratedForAdditionalPropertiesFalse(
        bool $addForModelsWithoutAdditionalPropertiesDefinition,
    ): void {
        $this->addPostProcessor($addForModelsWithoutAdditionalPropertiesDefinition);

        $className = $this->generateClassFromFile('AdditionalPropertiesFalse.json');

        $object = new $className();

        $this->assertFalse(is_callable([$object, 'additionalProperties']));
    }

    #[DataProvider('additionalPropertiesAccessorPostProcessorConfigurationDataProvider')]
    public function testAdditionalPropertiesAccessorsAreNotGeneratedWhenAdditionalPropertiesAreDenied(
        bool $addForModelsWithoutAdditionalPropertiesDefinition,
    ): void {
        $this->addPostProcessor($addForModelsWithoutAdditionalPropertiesDefinition);

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesNotDefined.json',
            (new GeneratorConfiguration())->setDenyAdditionalProperties(true),
        );

        $object = new $className();

        $this->assertFalse(is_callable([$object, 'additionalProperties']));
    }

    #[DataProvider('additionalPropertiesAccessorPostProcessorConfigurationDataProvider')]
    public function testAdditionalPropertiesAccessorsDependOnConfigurationForAdditionalPropertiesNotDefined(
        bool $addForModelsWithoutAdditionalPropertiesDefinition,
    ): void {
        $this->addPostProcessor($addForModelsWithoutAdditionalPropertiesDefinition);

        $className = $this->generateClassFromFile('AdditionalPropertiesNotDefined.json');

        $object = new $className();

        $this->assertSame(
            $addForModelsWithoutAdditionalPropertiesDefinition,
            is_callable([$object, 'additionalProperties']),
        );

        if ($addForModelsWithoutAdditionalPropertiesDefinition) {
            $accessor = $object->additionalProperties();
            // No companion for untyped additional properties — check native PHP return types directly
            $returnType = $this->getReturnType($accessor, 'getAll');
            $this->assertSame('array', $returnType->getName());
            $this->assertFalse($returnType->allowsNull());

            $this->assertSame('mixed', $this->getReturnType($accessor, 'get')->getName());
        }
    }

    public function testAdditionalPropertiesSettersForMutableObjectsWithoutAdditionalPropertiesDefinition(): void
    {
        $this->addPostProcessor(true);
        $className = $this->generateClassFromFile(
            'AdditionalPropertiesNotDefined.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['property1' => 100]);
        $accessor = $object->additionalProperties();

        $this->assertTrue(is_callable([$accessor, 'set']));
        $this->assertTrue(is_callable([$accessor, 'remove']));

        $this->assertSame(100, $accessor->get('property1'));
        $this->assertEqualsCanonicalizing(['property1' => 100], $accessor->getAll());

        $accessor->set('property2', 200);
        $this->assertEqualsCanonicalizing(['property1' => 100, 'property2' => 200], $accessor->getAll());

        $accessor->set('property1', 10);
        $this->assertEqualsCanonicalizing(['property1' => 10, 'property2' => 200], $accessor->getAll());

        $accessor->remove('property1');
        $this->assertEqualsCanonicalizing(['property2' => 200], $accessor->getAll());
        $this->assertNull($accessor->get('property1'));
    }

    #[DataProvider('additionalPropertiesAccessorPostProcessorConfigurationDataProvider')]
    public function testAdditionalPropertiesAccessorsAreGeneratedForAdditionalProperties(
        bool $addForModelsWithoutAdditionalPropertiesDefinition,
    ): void {
        $this->addPostProcessor($addForModelsWithoutAdditionalPropertiesDefinition);

        $className = $this->generateClassFromFile(
            'AdditionalProperties.json',
            // make sure the deny additional properties setting doesn't affect specified additional properties
            (new GeneratorConfiguration())->setDenyAdditionalProperties(true),
        );

        $object = new $className(['property1' => 'Hello', 'property2' => 'World']);
        $accessor = $object->additionalProperties();

        $this->assertTrue(is_callable([$object, 'additionalProperties']));

        // immutable model — no set/remove on the accessor
        $this->assertFalse(is_callable([$accessor, 'set']));
        $this->assertFalse(is_callable([$accessor, 'remove']));

        $this->assertEqualsCanonicalizing(
            ['property1' => 'Hello', 'property2' => 'World'],
            $accessor->getAll(),
        );
        $this->assertSame('Hello', $accessor->get('property1'));
        $this->assertSame('World', $accessor->get('property2'));
        $this->assertNull($accessor->get('property3'));
    }

    public static function additionalPropertiesAccessorPostProcessorConfigurationDataProvider(): array
    {
        return [
            'Add also for models without additional properties definition' => [true],
            'Add only for models with additional properties definition' => [false],
        ];
    }

    public function testAdditionalPropertiesModifiersAreGeneratedForMutableObjects(): void
    {
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'AdditionalProperties.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['property1' => '  Hello  ', 'property2' => 'World']);
        $accessor = $object->additionalProperties();

        $this->assertTrue(is_callable([$object, 'additionalProperties']));
        $this->assertTrue(is_callable([$accessor, 'set']));
        $this->assertTrue(is_callable([$accessor, 'remove']));

        // test adding a new additional property
        $accessor->set('property3', '  Good night  ');
        $this->assertEqualsCanonicalizing(
            ['property1' => 'Hello', 'property2' => 'World', 'property3' => 'Good night'],
            $accessor->getAll(),
        );
        $this->assertEqualsCanonicalizing(
            ['property1' => '  Hello  ', 'property2' => 'World', 'property3' => '  Good night  '],
            $object->meta()->rawInput(),
        );
        $this->assertSame('Good night', $accessor->get('property3'));

        // test removing an additional property
        $this->assertTrue($accessor->remove('property2'));
        $this->assertFalse($accessor->remove('property2'));
        $this->assertEqualsCanonicalizing(
            ['property1' => 'Hello', 'property3' => 'Good night'],
            $accessor->getAll(),
        );
        $this->assertEqualsCanonicalizing(
            ['property1' => '  Hello  ', 'property3' => '  Good night  '],
            $object->meta()->rawInput(),
        );

        // test update an existing additional property
        $accessor->set('property3', '  !Good night!  ');
        $this->assertEqualsCanonicalizing(
            ['property1' => 'Hello', 'property3' => '!Good night!'],
            $accessor->getAll(),
        );
        $this->assertEqualsCanonicalizing(
            ['property1' => '  Hello  ', 'property3' => '  !Good night!  '],
            $object->meta()->rawInput(),
        );
        $this->assertSame('!Good night!', $accessor->get('property3'));

        // test typing on the companion class methods
        $this->assertSame('string[]', $this->getReturnTypeAnnotation($accessor, 'getAll'));
        $returnType = $this->getReturnType($accessor, 'getAll');
        $this->assertSame('array', $returnType->getName());
        $this->assertFalse($returnType->allowsNull());

        $this->assertSame('string|null', $this->getReturnTypeAnnotation($accessor, 'get'));
        $returnType = $this->getReturnType($accessor, 'get');
        $this->assertSame('string', $returnType->getName());
        $this->assertTrue($returnType->allowsNull());

        $this->assertSame('string', $this->getParameterTypeAnnotation($accessor, 'set', 1));
        $paramType = $this->getParameterType($accessor, 'set', 1);
        $this->assertSame('string', $paramType->getName());
        $this->assertFalse($paramType->allowsNull());
    }

    #[DataProvider('invalidAdditionalPropertyDataProvider')]
    public function testInvalidAdditionalPropertyThrowsAnException(
        string $expectedException,
        string $expectedExceptionMessage,
        string $action,
        array $items,
    ): void {
        $this->expectException($expectedException);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'AdditionalProperties.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className(['property1' => '  Hello  ', 'property2' => 'World']);
        $accessor = $object->additionalProperties();

        foreach ($items as $property => $value) {
            $action === 'remove'
                ? $accessor->remove($value)
                : $accessor->set($property, $value);
        }
    }

    public static function invalidAdditionalPropertyDataProvider(): array
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

    public function testMinPropertiesIsEnforcedWhenRemovingAPatternPropertyKey(): void
    {
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'PatternPropertiesWithMinProperties.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        // 'a1' matches the ^a pattern, 'extra' is a plain additional property; total = 2 = minProperties.
        $object = new $className(['a1' => 'hello', 'extra' => 'world']);
        $accessor = $object->additionalProperties();

        // Removing the pattern-property key 'a1' would drop the count below minProperties=2 — must throw.
        $this->expectException(MinPropertiesException::class);
        $this->expectExceptionMessage('must not contain less than 2 properties');

        $accessor->remove('a1');
    }

    public function testSetterSchemaHooksAreResolvedInSetAdditionalProperties(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $modelGenerator): void {
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
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['property1' => 'Hello', 'property2' => 'World']);
        $accessor = $object->additionalProperties();
        $accessor->set('property1', 'Hello');
        $this->assertSame('Hello', $accessor->get('property1'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('SetterBeforeValidationHook');
        $accessor->set('property1', 'Goodbye');
    }

    #[DataProvider('implicitNullDataProvider')]
    public function testAdditionalPropertiesAreSerialized(bool $implicitNull): void
    {
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesTransformingFilter.json',
            (new GeneratorConfiguration())->setSerialization(true)->setImmutable(false),
            false,
            $implicitNull,
        );

        $object = new $className(['name' => 'Late autumn', 'start' => '2020-10-10']);
        $accessor = $object->additionalProperties();

        $this->assertInstanceOf(DateTime::class, $accessor->get('start'));
        $this->assertInstanceOf(DateTime::class, $accessor->getAll()['start']);

        $accessor->set('end', '2020-12-12');
        $this->assertInstanceOf(DateTime::class, $accessor->get('end'));
        $this->assertInstanceOf(DateTime::class, $accessor->getAll()['end']);

        $this->assertEqualsCanonicalizing(
            ['name' => 'Late autumn', 'start' => '20201010', 'end' => '20201212'],
            $object->toArray(),
        );

        // test adding a transformed value
        $accessor->set('now', new DateTime());
        $this->assertInstanceOf(DateTime::class, $accessor->get('now'));

        // test typing on the companion class methods
        $this->assertSame('(DateTime|null)[]', $this->getReturnTypeAnnotation($accessor, 'getAll'));
        $returnType = $this->getReturnType($accessor, 'getAll');
        $this->assertSame('array', $returnType->getName());
        $this->assertFalse($returnType->allowsNull());

        $this->assertSame('DateTime|null', $this->getReturnTypeAnnotation($accessor, 'get'));
        $returnType = $this->getReturnType($accessor, 'get');
        $this->assertSame('DateTime', $returnType->getName());
        $this->assertTrue($returnType->allowsNull());

        $this->assertSame(
            'string|DateTime|null',
            $this->getParameterTypeAnnotation($accessor, 'set', 1),
        );
        $this->assertEqualsCanonicalizing(
            ['string', 'DateTime', 'null'],
            $this->getParameterTypeNames($accessor, 'set', 1),
        );
    }


    public function testAdditionalPropertiesAreSerializedWithoutAdditionalPropertiesAccessorPostProcessor(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesTransformingFilter.json',
            (new GeneratorConfiguration())->setSerialization(true),
        );

        $object = new $className(['name' => 'Late autumn', 'start' => '2020-10-10']);
        $this->assertEqualsCanonicalizing(['name' => 'Late autumn', 'start' => '20201010'], $object->toArray());

        $object->populate(['end' => '20201212']);
        $this->assertEqualsCanonicalizing(
            ['name' => 'Late autumn', 'start' => '20201010', 'end' => '20201212'],
            $object->toArray(),
        );
    }

    public function testAdditionalPropertiesAreNotSerializedWhenNotDefinedWithoutExplicitAccessorMethods(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesNotDefined.json',
            (new GeneratorConfiguration())->setSerialization(true),
        );

        $object = new $className(['a' => 1, 'b' => 2]);
        $this->assertSame([], $object->toArray());

        $object->populate(['a' => 3, 'c' => 4]);
        $this->assertSame([], $object->toArray());
    }

    public function testAdditionalPropertiesAreSerializedWhenNotDefinedWithExplicitAccessorMethods(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator
                ->addPostProcessor(new PopulatePostProcessor())
                ->addPostProcessor(new AdditionalPropertiesAccessorPostProcessor(true));
        };

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesNotDefined.json',
            (new GeneratorConfiguration())->setSerialization(true),
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
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className(['property1' => 'Hello', 'property2' => null]);
        $accessor = $object->additionalProperties();

        $this->assertSame('Hello', $accessor->get('property1'));
        $this->assertNull($accessor->get('property2'));

        $accessor->set('property1', null);
        $this->assertNull($accessor->get('property1'));
        $accessor->set('property1', 5);
        $this->assertSame(5, $accessor->get('property1'));
        $accessor->set('property1', 'Goodbye');
        $this->assertSame('Goodbye', $accessor->get('property1'));

        // test typing on the companion class methods
        $this->assertSame(
            '(string|int|null)[]',
            $this->getReturnTypeAnnotation($accessor, 'getAll'),
        );
        $returnType = $this->getReturnType($accessor, 'getAll');
        $this->assertSame('array', $returnType->getName());
        $this->assertFalse($returnType->allowsNull());

        $this->assertSame(
            'string|int|null',
            $this->getReturnTypeAnnotation($accessor, 'get'),
        );
        $this->assertEqualsCanonicalizing(
            ['string', 'int', 'null'],
            $this->getReturnTypeNames($accessor, 'get'),
        );

        $this->assertSame(
            'string|int|null',
            $this->getParameterTypeAnnotation($accessor, 'set', 1),
        );
        $this->assertEqualsCanonicalizing(
            ['string', 'int', 'null'],
            $this->getParameterTypeNames($accessor, 'set', 1),
        );
    }

    public function testComposedAdditionalProperties(): void
    {
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesComposition.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['property1' => 'Hello', 'property2' => 12345]);
        $accessor = $object->additionalProperties();

        $this->assertSame('Hello', $accessor->get('property1'));
        $this->assertSame(12345, $accessor->get('property2'));

        $accessor->set('property1', 5);
        $this->assertSame(5, $accessor->get('property1'));
        $accessor->set('property1', 'Goodbye');
        $this->assertSame('Goodbye', $accessor->get('property1'));

        // test typing on the companion class methods
        $this->assertSame(
            '(string|int)[]',
            $this->getReturnTypeAnnotation($accessor, 'getAll'),
        );
        $returnType = $this->getReturnType($accessor, 'getAll');
        $this->assertSame('array', $returnType->getName());
        $this->assertFalse($returnType->allowsNull());

        $this->assertSame(
            'string|int|null',
            $this->getReturnTypeAnnotation($accessor, 'get'),
        );
        $this->assertEqualsCanonicalizing(
            ['string', 'int', 'null'],
            $this->getReturnTypeNames($accessor, 'get'),
        );

        $this->assertSame(
            'string|int',
            $this->getParameterTypeAnnotation($accessor, 'set', 1),
        );
        $this->assertEqualsCanonicalizing(
            ['string', 'int'],
            $this->getParameterTypeNames($accessor, 'set', 1),
        );
    }
}
