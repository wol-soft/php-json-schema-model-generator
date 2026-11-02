<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\Exception\Arrays\InvalidTupleException;
use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\Generic\EnumException;
use PHPModelGenerator\Exception\Generic\InvalidConstException;
use PHPModelGenerator\Exception\Object\InvalidAdditionalPropertiesException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * An enum and a const compare the provided JSON value with the allowed values: structures are equal if they hold the
 * same keys with equal values (the order of the keys of an object doesn't matter), a number inside of a structure
 * equals a number of the other type with the same value, 0, false and null are different values. The value of an
 * object property is the data which was provided for the object, not the model which is set up from the data.
 */
#[ApplicableDrafts]
class EnumConstValueEqualityTest extends AbstractPHPModelGeneratorTestCase
{
    private const array REQUIRED_OBJECTS = [
        'requiredEnumObject' => ['x' => 'y'],
        'requiredConstObject' => ['x' => 'y'],
    ];

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testEnumAndConstOfObjectValues(GeneratorConfiguration $configuration): void
    {
        $configuration = (clone $configuration)->setImmutable(false);
        $className = $this->generateClassFromFile('ObjectValues.json', $configuration);

        $assertRejected = fn(array $input, string $exceptionClass, string $message) => $this->assertRejected(
            $configuration,
            $className,
            $input + self::REQUIRED_OBJECTS,
            $exceptionClass,
            $message,
        );

        // an object property: the model is set up from the matching data
        $object = new $className(self::REQUIRED_OBJECTS + [
            'enumObject' => ['x' => 'y'],
            'constObject' => ['x' => 'y'],
        ]);
        $enumObjectClass = $this->getReturnType($object, 'getEnumObject')->getName();
        $constObjectClass = $this->getReturnType($object, 'getConstObject')->getName();

        // the value of the const doesn't change the type of the property which holds the model
        $this->assertNotSame('array', $enumObjectClass);
        $this->assertNotSame('array', $constObjectClass);
        $this->assertSame('y', $object->getEnumObject()->getX());
        $this->assertSame('y', $object->getConstObject()->getX());
        $this->assertSame('y', $object->getRequiredEnumObject()->getX());
        $this->assertSame('y', $object->getRequiredConstObject()->getX());

        $object = new $className(self::REQUIRED_OBJECTS);
        $this->assertNull($object->getEnumObject());
        $this->assertNull($object->getConstObject());

        $object = new $className(self::REQUIRED_OBJECTS + ['enumObject' => null, 'constObject' => null]);
        $this->assertNull($object->getEnumObject());
        $this->assertNull($object->getConstObject());

        $assertRejected(
            ['enumObject' => ['x' => 'z']],
            EnumException::class,
            <<<'MSG'
            Value for 'enumObject' must be one of [{"x":"y"},null], got {"x":"z"}
            MSG,
        );
        $assertRejected(
            ['enumObject' => []],
            EnumException::class,
            <<<'MSG'
            Value for 'enumObject' must be one of [{"x":"y"},null], got []
            MSG,
        );
        $assertRejected(
            ['enumObject' => ['x' => 'y', 'extra' => 1]],
            EnumException::class,
            <<<'MSG'
            Value for 'enumObject' must be one of [{"x":"y"},null], got {"x":"y","extra":1}
            MSG,
        );
        $assertRejected(
            ['constObject' => ['x' => 'z']],
            InvalidConstException::class,
            <<<'MSG'
            Value for 'constObject' must be {"x":"y"}, got {"x":"z"}
            MSG,
        );
        $assertRejected(
            ['constObject' => ['x' => 'y', 'extra' => 1]],
            InvalidConstException::class,
            <<<'MSG'
            Value for 'constObject' must be {"x":"y"}, got {"x":"y","extra":1}
            MSG,
        );
        $assertRejected(
            ['requiredEnumObject' => ['x' => 'z']],
            EnumException::class,
            <<<'MSG'
            Value for 'requiredEnumObject' must be one of [{"x":"y"}], got {"x":"z"}
            MSG,
        );
        $assertRejected(
            ['requiredConstObject' => ['x' => 'z']],
            InvalidConstException::class,
            <<<'MSG'
            Value for 'requiredConstObject' must be {"x":"y"}, got {"x":"z"}
            MSG,
        );
        // the setter validates the data of the provided model
        $object = new $className(self::REQUIRED_OBJECTS);
        $matchingModel = new $enumObjectClass(['x' => 'y']);
        $this->assertSame($object, $object->setEnumObject($matchingModel));
        $this->assertSame($matchingModel, $object->getEnumObject());
        $this->assertSame($object, $object->setConstObject(new $constObjectClass(['x' => 'y'])));
        $this->assertSame('y', $object->getConstObject()->getX());

        $exception = $this->catchException(static fn() => $object->setEnumObject(new $enumObjectClass(['x' => 'z'])));
        $this->assertInstanceOf($this->getExpectedExceptionClass($configuration, EnumException::class), $exception);
        $this->assertSame(
            <<<'MSG'
            Value for 'enumObject' must be one of [{"x":"y"},null], got {"x":"z"}
            MSG,
            $exception->getMessage(),
        );
        $this->assertSame($matchingModel, $object->getEnumObject());

        $exception = $this->catchException(
            static fn() => $object->setConstObject(new $constObjectClass(['x' => 'z'])),
        );
        $this->assertInstanceOf(
            $this->getExpectedExceptionClass($configuration, InvalidConstException::class),
            $exception,
        );
        $this->assertSame(
            <<<'MSG'
            Value for 'constObject' must be {"x":"y"}, got {"x":"z"}
            MSG,
            $exception->getMessage(),
        );
        $this->assertSame('y', $object->getConstObject()->getX());

        // several allowed objects, the order of the keys of an object is no part of the value
        foreach ([['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1], ['a' => 3, 'b' => 4], ['b' => 4, 'a' => 3]] as $value) {
            $object = new $className(self::REQUIRED_OBJECTS + ['enumObjects' => $value]);
            $this->assertSame($value['a'], $object->getEnumObjects()->getA());
            $this->assertSame($value['b'], $object->getEnumObjects()->getB());
        }
        $assertRejected(
            ['enumObjects' => ['a' => 1, 'b' => 4]],
            EnumException::class,
            <<<'MSG'
            Value for 'enumObjects' must be one of [{"a":1,"b":2},{"a":3,"b":4},null], got {"a":1,"b":4}
            MSG,
        );

        // an object which contains an object
        foreach ([['inner' => ['value' => 1], 'tag' => 't'], ['tag' => 't', 'inner' => ['value' => 1]]] as $value) {
            $object = new $className(self::REQUIRED_OBJECTS + ['enumNestedObject' => $value, 'constNestedObject' => $value]);
            $this->assertSame(1, $object->getEnumNestedObject()->getInner()->getValue());
            $this->assertSame(1, $object->getConstNestedObject()->getInner()->getValue());
        }
        $assertRejected(
            ['enumNestedObject' => ['tag' => 't', 'inner' => ['value' => 2]]],
            EnumException::class,
            <<<'MSG'
            Value for 'enumNestedObject' must be one of [{"inner":{"value":1},"tag":"t"},null], got {"tag":"t","inner":{"value":2}}
            MSG,
        );
        $assertRejected(
            ['constNestedObject' => ['tag' => 'u', 'inner' => ['value' => 1]]],
            InvalidConstException::class,
            <<<'MSG'
            Value for 'constNestedObject' must be {"inner":{"value":1},"tag":"t"}, got {"tag":"u","inner":{"value":1}}
            MSG,
        );

        // an array of objects: the order of the items matters
        foreach ([[['x' => 'y']], [['x' => 'z'], ['x' => 'y']]] as $value) {
            $object = new $className(self::REQUIRED_OBJECTS + ['enumObjectArray' => $value]);
            $this->assertCount(count($value), $object->getEnumObjectArray());
            $this->assertSame($value[0]['x'], $object->getEnumObjectArray()[0]->getX());
        }
        $object = new $className(self::REQUIRED_OBJECTS + ['constObjectArray' => [['x' => 'y']]]);
        $this->assertSame('y', $object->getConstObjectArray()[0]->getX());

        $assertRejected(
            ['enumObjectArray' => [['x' => 'y'], ['x' => 'z']]],
            EnumException::class,
            <<<'MSG'
            Value for 'enumObjectArray' must be one of [[{"x":"y"}],[{"x":"z"},{"x":"y"}],null], got [{"x":"y"},{"x":"z"}]
            MSG,
        );
        $assertRejected(
            ['enumObjectArray' => []],
            EnumException::class,
            <<<'MSG'
            Value for 'enumObjectArray' must be one of [[{"x":"y"}],[{"x":"z"},{"x":"y"}],null], got []
            MSG,
        );
        $assertRejected(
            ['constObjectArray' => [['x' => 'z']]],
            InvalidConstException::class,
            <<<'MSG'
            Value for 'constObjectArray' must be [{"x":"y"}], got [{"x":"z"}]
            MSG,
        );

        // the setter of an array receives the models of the items
        $object = new $className(self::REQUIRED_OBJECTS + ['enumObjectArray' => [['x' => 'y']]]);
        $itemClass = $object->getEnumObjectArray()[0]::class;
        $this->assertSame($object, $object->setEnumObjectArray([new $itemClass(['x' => 'y'])]));
        $exception = $this->catchException(
            static fn() => $object->setEnumObjectArray([new $itemClass(['x' => 'z'])]),
        );
        $this->assertInstanceOf($this->getExpectedExceptionClass($configuration, EnumException::class), $exception);
        $this->assertSame(
            <<<'MSG'
            Value for 'enumObjectArray' must be one of [[{"x":"y"}],[{"x":"z"},{"x":"y"}],null], got [{"x":"z"}]
            MSG,
            $exception->getMessage(),
        );
        $this->assertSame('y', $object->getEnumObjectArray()[0]->getX());

        // a property without a type compares the provided data
        foreach ([['x' => 'y', 'n' => 1], ['n' => 1, 'x' => 'y'], ['x' => 'y', 'n' => 1.0], 'text', null] as $value) {
            $object = new $className(self::REQUIRED_OBJECTS + ['untypedEnumObject' => $value]);
            $this->assertSame($value, $object->getUntypedEnumObject());
        }
        foreach ([['n' => 1, 'x' => 'y'], ['x' => 'y', 'n' => 1.0]] as $value) {
            $object = new $className(self::REQUIRED_OBJECTS + ['untypedConstObject' => $value]);
            $this->assertSame($value, $object->getUntypedConstObject());
        }
        $assertRejected(
            ['untypedEnumObject' => ['x' => 'y']],
            EnumException::class,
            <<<'MSG'
            Value for 'untypedEnumObject' must be one of [{"x":"y","n":1},"text",null], got {"x":"y"}
            MSG,
        );
        $assertRejected(
            ['untypedEnumObject' => ['x' => 'y', 'n' => 1.5]],
            EnumException::class,
            <<<'MSG'
            Value for 'untypedEnumObject' must be one of [{"x":"y","n":1},"text",null], got {"x":"y","n":1.5}
            MSG,
        );
        $assertRejected(
            ['untypedConstObject' => ['x' => 'y']],
            InvalidConstException::class,
            <<<'MSG'
            Value for 'untypedConstObject' must be {"x":"y","n":1}, got {"x":"y"}
            MSG,
        );

        // an array: the order of the items matters, no allowed value is dropped
        foreach ([[1, 2], [3], [], [1.0, 2.0]] as $value) {
            $object = new $className(self::REQUIRED_OBJECTS + ['enumArray' => $value]);
            $this->assertSame($value, $object->getEnumArray());
        }
        foreach ([[2, 1], [1], [1, 2, 3], [[1, 2]]] as $value) {
            $assertRejected(
                ['enumArray' => $value],
                EnumException::class,
                sprintf(
                    "Value for 'enumArray' must be one of [[1,2],[3],[],null], got %s",
                    json_encode($value),
                ),
            );
        }
        foreach ([[1, [2, 3]], [1.0, [2, 3.0]]] as $value) {
            $object = new $className(self::REQUIRED_OBJECTS + ['constArray' => $value]);
            $this->assertSame($value, $object->getConstArray());
        }
        $assertRejected(
            ['constArray' => [1, [3, 2]]],
            InvalidConstException::class,
            <<<'MSG'
            Value for 'constArray' must be [1,[2,3]], got [1,[3,2]]
            MSG,
        );

        // a number inside of a structure equals a number of the other type with the same value
        $equalNumbers = [['count' => 1, 'list' => [1, 2.5]], ['list' => [1.0, 2.5], 'count' => 1.0]];
        foreach ($equalNumbers as $value) {
            $object = new $className(self::REQUIRED_OBJECTS + ['enumNumbers' => $value]);
            $this->assertSame($value, $object->getEnumNumbers());
        }
        $assertRejected(
            ['enumNumbers' => ['count' => 1, 'list' => [1, 2]]],
            EnumException::class,
            <<<'MSG'
            Value for 'enumNumbers' must be one of [{"count":1,"list":[1,2.5]},null], got {"count":1,"list":[1,2]}
            MSG,
        );

        // 0, false and null are different values
        foreach ([[0], [0.0], ['value' => 0], ['value' => 0.0]] as $value) {
            $object = new $className(self::REQUIRED_OBJECTS + ['enumFalsyValues' => $value]);
            $this->assertSame($value, $object->getEnumFalsyValues());
        }
        $differentValues = [[false], [null], [''], ['0'], [], ['value' => false], ['value' => null], ['value' => '0']];
        foreach ($differentValues as $value) {
            $assertRejected(
                ['enumFalsyValues' => $value],
                EnumException::class,
                sprintf(
                    "Value for 'enumFalsyValues' must be one of [[0],{\"value\":0},null], got %s",
                    json_encode($value),
                ),
            );
        }
        $object = new $className(self::REQUIRED_OBJECTS + ['constFalsyValues' => [false, null]]);
        $this->assertSame([false, null], $object->getConstFalsyValues());
        foreach ([[0, null], [false, 0], [null, false], [false, ''], [false], [false, null, null]] as $value) {
            $assertRejected(
                ['constFalsyValues' => $value],
                InvalidConstException::class,
                sprintf("Value for 'constFalsyValues' must be [false,null], got %s", json_encode($value)),
            );
        }
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testEnumOfObjectValuesWithoutImplicitNullRejectsNull(): void
    {
        $className = $this->generateClassFromFile(
            'ObjectValues.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
            implicitNull: false,
        );

        $object = new $className(self::REQUIRED_OBJECTS + ['enumObject' => ['x' => 'y'], 'enumArray' => [3]]);
        $this->assertSame('y', $object->getEnumObject()->getX());
        $this->assertSame([3], $object->getEnumArray());

        $this->assertInstantiationFailsWithMessage(
            $className,
            self::REQUIRED_OBJECTS + ['untypedEnumObject' => null],
            EnumException::class,
            <<<'MSG'
            Value for 'untypedEnumObject' must be one of [{"x":"y","n":1},"text"], got null
            MSG,
        );
        $this->assertInstantiationFailsWithMessage(
            $className,
            self::REQUIRED_OBJECTS + ['enumArray' => [2, 1]],
            EnumException::class,
            <<<'MSG'
            Value for 'enumArray' must be one of [[1,2],[3],[]], got [2,1]
            MSG,
        );
        $this->assertInstantiationFailsWithMessage(
            $className,
            self::REQUIRED_OBJECTS + ['constObject' => ['x' => 'z']],
            InvalidConstException::class,
            <<<'MSG'
            Value for 'constObject' must be {"x":"y"}, got {"x":"z"}
            MSG,
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testEnumAndConstOfNumbersAreComparedWithTheTypeOfTheProperty(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('NumberValues.json', $configuration);

        // a number property casts an integer to a float
        $object = new $className(['numberEnum' => 1, 'numberConst' => 1, 'numberEnumWithDefault' => 2]);
        $this->assertSame(1.0, $object->getNumberEnum());
        $this->assertSame(1.0, $object->getNumberConst());
        $this->assertSame(2.0, $object->getNumberEnumWithDefault());

        $object = new $className(['numberEnum' => 2.5, 'numberConst' => 1.0]);
        $this->assertSame(2.5, $object->getNumberEnum());
        $this->assertSame(1.0, $object->getNumberConst());
        $this->assertSame(1.0, (new $className([]))->getNumberEnumWithDefault());

        $this->assertRejected(
            $configuration,
            $className,
            ['numberEnum' => 2],
            EnumException::class,
            'Value for \'numberEnum\' must be one of [1,2.5,null], got 2',
        );
        $this->assertRejected(
            $configuration,
            $className,
            ['numberConst' => 2],
            InvalidConstException::class,
            'Value for \'numberConst\' must be 1, got 2',
        );

        // an integer property compares an integer, also if the schema notes the allowed value as float
        $object = new $className(['integerEnum' => 1, 'integerConst' => 2]);
        $this->assertSame(1, $object->getIntegerEnum());
        $this->assertSame(2, $object->getIntegerConst());
        $this->assertSame(2, (new $className(['integerEnum' => 2]))->getIntegerEnum());

        $this->assertRejected(
            $configuration,
            $className,
            ['integerEnum' => 3],
            EnumException::class,
            'Value for \'integerEnum\' must be one of [1,2,null], got 3',
        );
        $this->assertRejected(
            $configuration,
            $className,
            ['integerConst' => 1],
            InvalidConstException::class,
            'Value for \'integerConst\' must be 2, got 1',
        );
        // the type of a property without a type is inferred from the allowed values: the integer property of an
        // enum of integers can't hold a float, a float which equals an allowed integer is rejected
        $this->assertSame(2, (new $className(['untypedIntegerEnum' => 2]))->getUntypedIntegerEnum());
        $this->assertRejected(
            $configuration,
            $className,
            ['untypedIntegerEnum' => 2.0],
            EnumException::class,
            'Value for \'untypedIntegerEnum\' must be one of [1,2,null], got 2',
        );

        $this->assertSame('a', (new $className(['numberOrStringEnum' => 'a']))->getNumberOrStringEnum());
        $this->assertSame(1.0, (new $className(['numberOrStringEnum' => 1]))->getNumberOrStringEnum());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testEnumOfAnObjectSchemaValidatesTheDataOfTheObject(GeneratorConfiguration $configuration): void
    {
        $configuration = (clone $configuration)->setImmutable(false);
        $className = $this->generateClassFromFile('RootEnum.json', $configuration);

        $object = new $className(['x' => 'y']);
        $this->assertSame('y', $object->getX());
        $this->assertSame('z', (new $className(['x' => 'z']))->getX());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['x' => 'q'],
            $this->getExpectedExceptionClass($configuration, EnumException::class),
            <<<'MSG'
            Value for '%class%' must be one of [{"x":"y"},{"x":"z"}], got {"x":"q"}
            MSG,
        );
        $this->assertInstantiationFailsWithMessage(
            $className,
            [],
            $this->getExpectedExceptionClass($configuration, EnumException::class),
            <<<'MSG'
            Value for '%class%' must be one of [{"x":"y"},{"x":"z"}], got []
            MSG,
        );
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['x' => 'y', 'additional' => true],
            $this->getExpectedExceptionClass($configuration, EnumException::class),
            <<<'MSG'
            Value for '%class%' must be one of [{"x":"y"},{"x":"z"}], got {"x":"y","additional":true}
            MSG,
        );

        // an update of the object is validated, a rejected update leaves the object untouched
        $this->assertSame($object, $object->setX('z'));
        $this->assertSame('z', $object->getX());

        $exception = $this->catchException(static fn() => $object->setX('q'));
        $this->assertInstanceOf($this->getExpectedExceptionClass($configuration, EnumException::class), $exception);
        $this->assertMessageMatchesTemplate(
            <<<'MSG'
            Value for '%class%' must be one of [{"x":"y"},{"x":"z"}], got {"x":"q"}
            MSG,
            $exception->getMessage(),
        );
        $this->assertSame('z', $object->getX());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testConstOfAnObjectSchemaValidatesTheDataOfTheObject(GeneratorConfiguration $configuration): void
    {
        $configuration = (clone $configuration)->setImmutable(false);
        $className = $this->generateClassFromFile('RootConst.json', $configuration);

        // the order of the keys is no part of the value
        foreach ([['x' => 'y', 'n' => 1], ['n' => 1, 'x' => 'y'], ['n' => 1, 'x' => 'y']] as $value) {
            $object = new $className($value);
            $this->assertSame('y', $object->getX());
            $this->assertSame(1, $object->getN());
        }

        foreach ([[], ['x' => 'y'], ['x' => 'y', 'n' => 2], ['x' => 'y', 'n' => 1, 'additional' => true]] as $value) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                $value,
                $this->getExpectedExceptionClass($configuration, InvalidConstException::class),
                sprintf("Value for '%%class%%' must be {\"n\":1,\"x\":\"y\"}, got %s", json_encode($value)),
            );
        }

        // every update which changes the object is rejected
        $object = new $className(['x' => 'y', 'n' => 1]);
        $this->assertSame($object, $object->setX('y'));

        $exception = $this->catchException(static fn() => $object->setX('z'));
        $this->assertInstanceOf(
            $this->getExpectedExceptionClass($configuration, InvalidConstException::class),
            $exception,
        );
        $this->assertMessageMatchesTemplate(
            <<<'MSG'
            Value for '%class%' must be {"n":1,"x":"y"}, got {"x":"z","n":1}
            MSG,
            $exception->getMessage(),
        );
        $this->assertSame('y', $object->getX());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testEnumAndConstOfObjectValuesInReferencesAndCompositions(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('ObjectValuesReferencedAndComposed.json', $configuration);

        $object = new $className([
            'referencedEnumObject' => ['x' => 'y'],
            'referencedConstObject' => ['x' => 'y'],
            'oneOfConstObjects' => ['kind' => 'b'],
            'anyOfEnumObject' => ['kind' => 'a'],
        ]);
        $this->assertSame('y', $object->getReferencedEnumObject()->getX());
        $this->assertSame('y', $object->getReferencedConstObject()->getX());
        $this->assertSame('b', $object->getOneOfConstObjects()->getKind());
        $this->assertSame('a', $object->getAnyOfEnumObject()->getKind());
        $this->assertSame('text', (new $className(['anyOfEnumObject' => 'text']))->getAnyOfEnumObject());

        $this->assertRejected(
            $configuration,
            $className,
            ['referencedEnumObject' => ['x' => 'z']],
            EnumException::class,
            <<<'MSG'
            Value for 'referencedEnumObject' must be one of [{"x":"y"},null], got {"x":"z"}
            MSG,
        );
        $this->assertRejected(
            $configuration,
            $className,
            ['referencedConstObject' => ['x' => 'z']],
            InvalidConstException::class,
            <<<'MSG'
            Value for 'referencedConstObject' must be {"x":"y"}, got {"x":"z"}
            MSG,
        );
        $this->assertRejected(
            $configuration,
            $className,
            ['oneOfConstObjects' => ['kind' => 'c']],
            OneOfException::class,
            <<<'MSG'
            Invalid value for 'oneOfConstObjects' declined by composition constraint
              Requires to match one composition element but matched 0 elements
              - Composition element #1: Failed
                * Value for 'oneOfConstObjects' must be {"kind":"a"}, got {"kind":"c"}
              - Composition element #2: Failed
                * Value for 'oneOfConstObjects' must be {"kind":"b"}, got {"kind":"c"}
            MSG,
        );
        $this->assertRejected(
            $configuration,
            $className,
            ['anyOfEnumObject' => ['kind' => 'q']],
            AnyOfException::class,
            <<<'MSG'
            Invalid value for 'anyOfEnumObject' declined by composition constraint
              Requires to match at least one composition element
              - Composition element #1: Failed
                * Value for 'anyOfEnumObject' must be one of [{"kind":"a"},null], got {"kind":"q"}
              - Composition element #2: Failed
                * Invalid type for 'anyOfEnumObject': requires 'string', got 'object'
            MSG,
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testEnumAndConstOfObjectValuesOfOtherSchemaShapes(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('ObjectValuesOfOtherSchemaShapes.json', $configuration);

        // properties which hold the model of a nested class and other values
        $object = new $className([
            'untypedObjectEnum' => ['x' => 'y'],
            'untypedObjectConst' => ['x' => 'y'],
            'multiTypeEnum' => ['x' => 'y'],
            'multiTypeConst' => ['x' => 'y'],
        ]);
        $this->assertSame('y', $object->getUntypedObjectEnum()->getX());
        $this->assertSame('y', $object->getUntypedObjectConst()->getX());
        $this->assertSame('y', $object->getMultiTypeEnum()->getX());
        $this->assertSame('y', $object->getMultiTypeConst()->getX());

        $object = new $className(['untypedObjectEnum' => 'text', 'multiTypeEnum' => 'text']);
        $this->assertSame('text', $object->getUntypedObjectEnum());
        $this->assertSame('text', $object->getMultiTypeEnum());

        $enumExpectation = 'must be one of [{"x":"y"},"text",null]';
        $rejections = [
            ['untypedObjectEnum', EnumException::class, $enumExpectation, [['x' => 'z'], 5]],
            ['untypedObjectConst', InvalidConstException::class, 'must be {"x":"y"}', [['x' => 'z'], 5]],
            ['multiTypeEnum', EnumException::class, $enumExpectation, [['x' => 'z'], 'other']],
            ['multiTypeConst', InvalidConstException::class, 'must be {"x":"y"}', [['x' => 'z'], 'other']],
        ];
        foreach ($rejections as [$propertyName, $exceptionClass, $expectation, $rejectedValues]) {
            foreach ($rejectedValues as $value) {
                $this->assertRejected(
                    $configuration,
                    $className,
                    [$propertyName => $value],
                    $exceptionClass,
                    sprintf("Value for '%s' %s, got %s", $propertyName, $expectation, json_encode($value)),
                );
            }
        }

        // the items of an array
        $object = new $className(['itemsEnum' => [['x' => 'y'], ['x' => 'z']], 'tupleConst' => [['x' => 'y']]]);
        $this->assertSame('z', $object->getItemsEnum()[1]->getX());
        $this->assertSame('y', $object->getTupleConst()[0]->getX());

        $this->assertRejected(
            $configuration,
            $className,
            ['itemsEnum' => [['x' => 'y'], ['x' => 'q']]],
            InvalidItemException::class,
            <<<'MSG'
            Invalid items in array 'itemsEnum':
              - invalid item #1
                * Value for 'itemsEnum' must be one of [{"x":"y"},{"x":"z"}], got {"x":"q"}
            MSG,
        );
        $this->assertRejected(
            $configuration,
            $className,
            ['tupleConst' => [['x' => 'q']]],
            InvalidTupleException::class,
            <<<'MSG'
            Invalid tuple item in array 'tupleConst':
              - invalid tuple #1
                * Value for 'tuple item #0 of array tupleConst' must be {"x":"y"}, got {"x":"q"}
            MSG,
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testConstOfAdditionalObjectValues(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('AdditionalObjectValues.json', $configuration);

        $object = new $className(['a' => ['x' => 'y'], 'b' => ['x' => 'y']]);
        $this->assertSame(['a' => ['x' => 'y'], 'b' => ['x' => 'y']], $object->meta()->rawInput());

        $this->assertRejected(
            $configuration,
            $className,
            ['a' => ['x' => 'y'], 'b' => ['x' => 'q']],
            InvalidAdditionalPropertiesException::class,
            <<<'MSG'
            Provided JSON for '%class%' contains invalid additional properties
              - invalid additional property 'b'
                * Value for 'additional property' must be {"x":"y"}, got {"x":"q"}
            MSG,
        );
    }

    /**
     * The object level validation of an enum and a const also runs for the other ways to update an object.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testConstOfAnObjectSchemaValidatesTheDataOfAPopulatedObject(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            'RootConst.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className(['x' => 'y', 'n' => 1]);
        $this->assertSame($object, $object->populate(['n' => 1]));
        $this->assertSame($object, $object->populate(['x' => 'y']));

        $exception = $this->catchException(static fn() => $object->populate(['n' => 2]));
        $this->assertInstanceOf(InvalidConstException::class, $exception);
        $this->assertMessageMatchesTemplate(
            <<<'MSG'
            Value for '%class%' must be {"n":1,"x":"y"}, got {"x":"y","n":2}
            MSG,
            $exception->getMessage(),
        );
        $this->assertSame(1, $object->getN());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testDefaultValueOfAnEnumIsComparedWithTheAllowedValuesAsJsonValue(): void
    {
        $className = $this->generateClassFromFile('ObjectEnumWithDefault.json');

        // the default is an allowed value although the order of the keys and the type of a number differ
        $this->assertSame(['b' => 2.0, 'a' => 1], (new $className([]))->getProperty());
        $this->assertSame(['a' => 3], (new $className(['property' => ['a' => 3]]))->getProperty());
    }

    public function testDefaultValueWhichIsNoAllowedValueOfAnEnumOfObjectsThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Invalid default value array \(\n  'a' => 1,\n\) for enum property property in file .*\.json/",
        );

        $this->generateClassFromFile('ObjectEnumWithInvalidDefault.json');
    }

    /**
     * Instantiating the class with the input must fail with the exception of the configuration and the message.
     *
     * @param class-string $exceptionClass
     */
    private function assertRejected(
        GeneratorConfiguration $configuration,
        string $className,
        array $input,
        string $exceptionClass,
        string $message,
    ): void {
        $this->assertInstantiationFailsWithMessage(
            $className,
            $input,
            $this->getExpectedExceptionClass($configuration, $exceptionClass),
            $message,
        );
    }
}
