<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\Arrays\AdditionalTupleItemsException;
use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\Exception\Arrays\InvalidTupleException;
use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\Generic\EnumException;
use PHPModelGenerator\Exception\Generic\InvalidConstException;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\Object\InvalidAdditionalPropertiesException;
use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\AdditionalPropertiesAccessorPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\BuilderClassPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\UnevaluatedPropertiesAccessorPostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;
use TypeError;

/**
 * Properties which only accept null: declared by a null const, a null enum or the null type. PHP rejects the
 * nullable shorthand of the null type ("?null"), so the generated declarations of such a property must not
 * contain it, whatever makes the property nullable (optional, implicit null).
 */
#[ApplicableDrafts]
class NullOnlyPropertyTest extends AbstractPHPModelGeneratorTestCase
{
    /** The required properties of NullOnlyProperties.json */
    private const array REQUIRED_PROPERTIES = [
        'requiredConstNull' => null,
        'requiredEnumNull' => null,
        'requiredTypeNull' => null,
    ];

    /**
     * Exception and message a non-null value is rejected with, keyed by the property of NullOnlyProperties.json.
     * The type check of a property runs before the const/enum check.
     */
    private const array NON_NULL_REJECTIONS = [
        'constNull' => [InvalidConstException::class, "Value for 'constNull' must be null, got \"text\""],
        'enumNull' => [EnumException::class, "Value for 'enumNull' must be one of [null], got \"text\""],
        'typeNull' => [InvalidTypeException::class, "Invalid type for 'typeNull': requires 'null', got 'string'"],
        'typeNullConstNull' => [
            InvalidTypeException::class,
            "Invalid type for 'typeNullConstNull': requires 'null', got 'string'",
        ],
        'typeNullEnumNull' => [
            InvalidTypeException::class,
            "Invalid type for 'typeNullEnumNull': requires 'null', got 'string'",
        ],
        'multiTypeConstNull' => [
            InvalidConstException::class,
            "Value for 'multiTypeConstNull' must be null, got \"text\"",
        ],
        'requiredConstNull' => [
            InvalidConstException::class,
            "Value for 'requiredConstNull' must be null, got \"text\"",
        ],
        'requiredEnumNull' => [
            EnumException::class,
            "Value for 'requiredEnumNull' must be one of [null], got \"text\"",
        ],
        'requiredTypeNull' => [
            InvalidTypeException::class,
            "Invalid type for 'requiredTypeNull': requires 'null', got 'string'",
        ],
    ];

    /** The properties of NullOnlyProperties.json which are declared with the null type of PHP */
    private const array NULL_TYPED_PROPERTIES = [
        'constNull',
        'enumNull',
        'typeNullConstNull',
        'typeNullEnumNull',
        'multiTypeConstNull',
        'requiredConstNull',
        'requiredEnumNull',
    ];

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testNullOnlyPropertiesOfAMutableClass(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'NullOnlyProperties.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true)->setCollectErrors(false),
            implicitNull: $implicitNull,
        );

        $propertyNames = array_keys(self::NON_NULL_REJECTIONS);

        // The class loads, optional properties may be omitted, the getters return null
        $object = new $className(self::REQUIRED_PROPERTIES);
        foreach ($propertyNames as $propertyName) {
            $this->assertNull($object->{'get' . ucfirst($propertyName)}(), $propertyName);
        }

        // The declarations of the property: the null type of PHP without the nullable shorthand
        foreach (self::NULL_TYPED_PROPERTIES as $propertyName) {
            $getter = 'get' . ucfirst($propertyName);
            $setter = 'set' . ucfirst($propertyName);

            $this->assertSame(['null'], $this->getReturnTypeNames($object, $getter), $propertyName);
            $this->assertSame(['null'], $this->getParameterTypeNames($object, $setter), $propertyName);

            // The annotations of the multi type property list the types declared by the schema, the const
            // narrows the native declarations only
            if ($propertyName === 'multiTypeConstNull') {
                continue;
            }

            $this->assertSame('null', $this->getReturnTypeAnnotation($object, $getter), $propertyName);
            $this->assertSame('null', $this->getParameterTypeAnnotation($object, $setter), $propertyName);
            $this->assertSame('null', $this->getPropertyTypeAnnotation($object, $propertyName), $propertyName);
        }

        // The null type of a schema is not declared natively
        foreach (['typeNull', 'requiredTypeNull'] as $propertyName) {
            $this->assertSame('mixed', $this->getReturnType($object, 'get' . ucfirst($propertyName))->getName());
            $this->assertSame(
                'mixed',
                $this->getParameterType($object, 'set' . ucfirst($propertyName))->getName(),
            );
            $this->assertSame('null', $this->getReturnTypeAnnotation($object, 'get' . ucfirst($propertyName)));
        }

        // An explicitly provided null is valid for each property, also in combination with other properties
        $allNull = array_fill_keys($propertyNames, null);
        $object = new $className($allNull);
        foreach ($propertyNames as $propertyName) {
            $this->assertNull($object->{'get' . ucfirst($propertyName)}(), $propertyName);
        }

        // The setters accept null
        foreach ($propertyNames as $propertyName) {
            $this->assertSame($object, $object->{'set' . ucfirst($propertyName)}(null), $propertyName);
            $this->assertNull($object->{'get' . ucfirst($propertyName)}(), $propertyName);
        }

        // Null is serialized as null for each property, the serialized value can be consumed again
        $this->assertSame($allNull, $object->toArray());
        $this->assertSame($allNull, (new $className($object->toArray()))->toArray());

        // An omitted optional property is serialized as null only if null is a valid value for an omitted property
        $this->assertSame(
            $implicitNull ? $allNull : self::REQUIRED_PROPERTIES,
            (new $className(self::REQUIRED_PROPERTIES))->toArray(),
        );

        // A different value is rejected by the constructor
        foreach ($propertyNames as $propertyName) {
            [$exceptionClass, $message] = self::NON_NULL_REJECTIONS[$propertyName];

            $this->assertInstantiationFailsWithMessage(
                $className,
                array_merge(self::REQUIRED_PROPERTIES, [$propertyName => 'text']),
                $exceptionClass,
                $message,
            );
        }

        // A different value is rejected by the setter: the null type of PHP rejects the value before any generated
        // validation runs
        foreach (self::NULL_TYPED_PROPERTIES as $propertyName) {
            $setter = 'set' . ucfirst($propertyName);

            $exception = $this->catchException(static fn() => $object->$setter('text'));

            $this->assertInstanceOf(TypeError::class, $exception, $propertyName);
            $this->assertMatchesRegularExpression(
                '/^' . preg_quote(
                    "$className::$setter(): Argument #1 (\$$propertyName) must be of type null, string given, "
                        . 'called in ',
                    '/',
                ) . '.+ on line \d+$/',
                $exception->getMessage(),
            );
        }

        // The setters of the properties without a native declaration validate the value
        foreach (['typeNull', 'requiredTypeNull'] as $propertyName) {
            $setter = 'set' . ucfirst($propertyName);

            $exception = $this->catchException(static fn() => $object->$setter('text'));

            $this->assertInstanceOf(InvalidTypeException::class, $exception, $propertyName);
            $this->assertSame(self::NON_NULL_REJECTIONS[$propertyName][1], $exception->getMessage());
        }

        // The rejected values didn't change the state of the object
        foreach ($propertyNames as $propertyName) {
            $this->assertNull($object->{'get' . ucfirst($propertyName)}(), $propertyName);
        }
        $this->assertSame($allNull, $object->toArray());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testNullOnlyPropertiesOfAnImmutableClass(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'NullOnlyProperties.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
            implicitNull: $implicitNull,
        );

        $object = new $className(self::REQUIRED_PROPERTIES);

        foreach (array_keys(self::NON_NULL_REJECTIONS) as $propertyName) {
            $this->assertNull($object->{'get' . ucfirst($propertyName)}(), $propertyName);
            $this->assertFalse(is_callable([$object, 'set' . ucfirst($propertyName)]), $propertyName);

            [$exceptionClass, $message] = self::NON_NULL_REJECTIONS[$propertyName];
            $this->assertInstantiationFailsWithMessage(
                $className,
                array_merge(self::REQUIRED_PROPERTIES, [$propertyName => 'text']),
                $exceptionClass,
                $message,
            );
        }

        foreach (self::NULL_TYPED_PROPERTIES as $propertyName) {
            $this->assertSame(
                ['null'],
                $this->getReturnTypeNames($object, 'get' . ucfirst($propertyName)),
                $propertyName,
            );
        }
    }

    /**
     * The builder declares the types of the properties nullable independent of the configuration.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testNullOnlyPropertiesOfABuilderClass(bool $implicitNull): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new BuilderClassPostProcessor());
        };

        $className = $this->generateClassFromFile(
            'NullOnlyProperties.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
            implicitNull: $implicitNull,
        );

        $builderClassName = $className . 'Builder';
        $builder = new $builderClassName();

        foreach (array_keys(self::NON_NULL_REJECTIONS) as $propertyName) {
            $this->assertNull($builder->{'get' . ucfirst($propertyName)}(), $propertyName);
            $this->assertSame($builder, $builder->{'set' . ucfirst($propertyName)}(null), $propertyName);
        }

        foreach (self::NULL_TYPED_PROPERTIES as $propertyName) {
            $this->assertSame(
                ['null'],
                $this->getReturnTypeNames($builder, 'get' . ucfirst($propertyName)),
                $propertyName,
            );
            $this->assertSame(
                ['null'],
                $this->getParameterTypeNames($builder, 'set' . ucfirst($propertyName)),
                $propertyName,
            );
        }

        $object = $builder->validate();
        $this->assertInstanceOf($className, $object);
        $this->assertNull($object->getConstNull());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testNullOnlyPropertiesInNestedStructures(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'NullOnlyNestedProperties.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true)->setCollectErrors(false),
            implicitNull: $implicitNull,
        );

        $required = ['requiredAllOfConstNull' => null];

        // Nothing but the required property provided
        $object = new $className($required);
        $this->assertNull($object->getNestedObject());
        $this->assertNull($object->getArrayOfConstNull());
        $this->assertNull($object->getAllOfConstNull());
        $this->assertNull($object->getAnyOfConstNull());
        $this->assertNull($object->getOneOfEnumNull());
        $this->assertNull($object->getRequiredAllOfConstNull());

        // Nested object: the properties of the nested class only accept null
        $object = new $className($required + ['nestedObject' => []]);
        $nestedObject = $object->getNestedObject();
        $this->assertNull($nestedObject->getConstNull());
        $this->assertNull($nestedObject->getEnumNull());
        $this->assertSame(['null'], $this->getReturnTypeNames($nestedObject, 'getConstNull'));
        $this->assertSame(['null'], $this->getParameterTypeNames($nestedObject, 'setConstNull'));
        $this->assertSame(['null'], $this->getReturnTypeNames($nestedObject, 'getEnumNull'));
        $this->assertSame(['null'], $this->getParameterTypeNames($nestedObject, 'setEnumNull'));

        $object = new $className($required + ['nestedObject' => ['constNull' => null, 'enumNull' => null]]);
        $nestedObject = $object->getNestedObject();
        $this->assertSame($nestedObject, $nestedObject->setConstNull(null)->setEnumNull(null));
        $this->assertSame(
            $implicitNull
                ? [
                    'nestedObject' => ['constNull' => null, 'enumNull' => null],
                    'arrayOfConstNull' => null,
                    'arrayOfEnumNull' => null,
                    'tupleWithConstNull' => null,
                    'allOfConstNull' => null,
                    'anyOfConstNull' => null,
                    'oneOfEnumNull' => null,
                    'requiredAllOfConstNull' => null,
                ]
                : ['nestedObject' => ['constNull' => null, 'enumNull' => null], 'requiredAllOfConstNull' => null],
            $object->toArray(),
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            $required + ['nestedObject' => ['constNull' => 'text']],
            NestedObjectException::class,
            <<<'MSG'
            Invalid nested object for property 'nestedObject':
              - Value for 'constNull' must be null, got "text"
            MSG,
        );
        $this->assertInstantiationFailsWithMessage(
            $className,
            $required + ['nestedObject' => ['enumNull' => 'text']],
            NestedObjectException::class,
            <<<'MSG'
            Invalid nested object for property 'nestedObject':
              - Value for 'enumNull' must be one of [null], got "text"
            MSG,
        );

        // Array items
        $object = new $className($required + [
            'arrayOfConstNull' => [null, null],
            'arrayOfEnumNull' => [null],
            'tupleWithConstNull' => [null, 'text'],
        ]);
        $this->assertSame([null, null], $object->getArrayOfConstNull());
        $this->assertSame([null], $object->getArrayOfEnumNull());
        $this->assertSame([null, 'text'], $object->getTupleWithConstNull());
        $this->assertSame(
            $implicitNull
                ? [
                    'nestedObject' => null,
                    'arrayOfConstNull' => [null, null],
                    'arrayOfEnumNull' => [null],
                    'tupleWithConstNull' => [null, 'text'],
                    'allOfConstNull' => null,
                    'anyOfConstNull' => null,
                    'oneOfEnumNull' => null,
                    'requiredAllOfConstNull' => null,
                ]
                : [
                    'arrayOfConstNull' => [null, null],
                    'arrayOfEnumNull' => [null],
                    'tupleWithConstNull' => [null, 'text'],
                    'requiredAllOfConstNull' => null,
                ],
            $object->toArray(),
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            $required + ['arrayOfConstNull' => [null, 'text']],
            InvalidItemException::class,
            <<<'MSG'
            Invalid items in array 'arrayOfConstNull':
              - invalid item #1
                * Value for 'arrayOfConstNull' must be null, got "text"
            MSG,
        );
        $this->assertInstantiationFailsWithMessage(
            $className,
            $required + ['arrayOfEnumNull' => ['text']],
            InvalidItemException::class,
            <<<'MSG'
            Invalid items in array 'arrayOfEnumNull':
              - invalid item #0
                * Value for 'arrayOfEnumNull' must be one of [null], got "text"
            MSG,
        );
        $this->assertInstantiationFailsWithMessage(
            $className,
            $required + ['tupleWithConstNull' => ['text', 'text']],
            InvalidTupleException::class,
            <<<'MSG'
            Invalid tuple item in array 'tupleWithConstNull':
              - invalid tuple #1
                * Value for 'tuple item #0 of array tupleWithConstNull' must be null, got "text"
            MSG,
        );
        $this->assertInstantiationFailsWithMessage(
            $className,
            $required + ['tupleWithConstNull' => [null, 'text', null]],
            AdditionalTupleItemsException::class,
            "Tuple array 'tupleWithConstNull' contains not allowed additional items: expected 2 items, got 3",
        );

        // Compositions
        $object = new $className(
            $required + ['allOfConstNull' => null, 'anyOfConstNull' => 'text', 'oneOfEnumNull' => 5],
        );
        $this->assertNull($object->getAllOfConstNull());
        $this->assertSame('text', $object->getAnyOfConstNull());
        $this->assertSame(5, $object->getOneOfEnumNull());
        $this->assertSame(['string', 'null'], $this->getReturnTypeNames($object, 'getAnyOfConstNull'));
        $this->assertSame(['int', 'null'], $this->getReturnTypeNames($object, 'getOneOfEnumNull'));

        $object = new $className($required + ['anyOfConstNull' => null, 'oneOfEnumNull' => null]);
        $this->assertNull($object->getAnyOfConstNull());
        $this->assertNull($object->getOneOfEnumNull());

        $this->assertInstantiationFailsWithMessage(
            $className,
            $required + ['allOfConstNull' => 'text'],
            AllOfException::class,
            <<<'MSG'
            Invalid value for 'allOfConstNull' declined by composition constraint
              Requires to match all composition elements but matched 0 elements
              - Composition element #1: Failed
                * Invalid type for 'allOfConstNull': requires 'null', got 'string'
              - Composition element #2: Failed
                * Value for 'allOfConstNull' must be null, got "text"
            MSG,
        );
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['requiredAllOfConstNull' => 'text'],
            AllOfException::class,
            <<<'MSG'
            Invalid value for 'requiredAllOfConstNull' declined by composition constraint
              Requires to match all composition elements but matched 0 elements
              - Composition element #1: Failed
                * Value for 'requiredAllOfConstNull' must be null, got "text"
            MSG,
        );
        $this->assertInstantiationFailsWithMessage(
            $className,
            $required + ['anyOfConstNull' => 5],
            AnyOfException::class,
            <<<'MSG'
            Invalid value for 'anyOfConstNull' declined by composition constraint
              Requires to match at least one composition element
              - Composition element #1: Failed
                * Value for 'anyOfConstNull' must be null, got 5
              - Composition element #2: Failed
                * Invalid type for 'anyOfConstNull': requires 'string', got 'integer'
            MSG,
        );
        $this->assertInstantiationFailsWithMessage(
            $className,
            $required + ['oneOfEnumNull' => 'text'],
            OneOfException::class,
            <<<'MSG'
            Invalid value for 'oneOfEnumNull' declined by composition constraint
              Requires to match one composition element but matched 0 elements
              - Composition element #1: Failed
                * Value for 'oneOfEnumNull' must be one of [null], got "text"
              - Composition element #2: Failed
                * Invalid type for 'oneOfEnumNull': requires 'int', got 'string'
            MSG,
        );
    }

    /**
     * Both allOf branches define the same property with a null only schema: the property of the merged class only
     * accepts null.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testNullOnlyPropertyMergedFromAllOfBranches(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'NullOnlyMergedProperty.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
            implicitNull: $implicitNull,
        );

        $object = new $className([]);
        $this->assertNull($object->getMerged());
        $this->assertSame(['null'], $this->getReturnTypeNames($object, 'getMerged'));
        $this->assertSame(['null'], $this->getParameterTypeNames($object, 'setMerged'));

        $object = new $className(['merged' => null]);
        $this->assertNull($object->getMerged());
        $this->assertSame($object, $object->setMerged(null));
        $this->assertNull($object->getMerged());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['merged' => 'text'],
            AllOfException::class,
            <<<'MSG'
            Invalid value for '%class%' declined by composition constraint
              Requires to match all composition elements but matched 0 elements
            MSG,
        );
    }

    /**
     * The accessor of the additional properties declares the type of the values nullable independent of the
     * configuration.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testNullOnlyAdditionalPropertiesAccessor(bool $implicitNull): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new AdditionalPropertiesAccessorPostProcessor());
        };

        $className = $this->generateClassFromFile(
            'NullOnlyAdditionalProperties.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
            implicitNull: $implicitNull,
        );

        $object = new $className(['name' => 'Albert', 'extra' => null]);
        $accessor = $object->additionalProperties();

        $this->assertNull($accessor->get('extra'));
        $this->assertSame(['extra' => null], $accessor->getAll());
        $this->assertSame(['null'], $this->getReturnTypeNames($accessor, 'get'));
        $this->assertSame(['null'], $this->getParameterTypeNames($accessor, 'set', 1));

        $this->assertSame($accessor, $accessor->set('other', null));
        $this->assertSame(['extra' => null, 'other' => null], $accessor->getAll());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['extra' => 'text'],
            InvalidAdditionalPropertiesException::class,
            <<<'MSG'
            Provided JSON for '%class%' contains invalid additional properties
              - invalid additional property 'extra'
                * Value for 'additional property' must be null, got "text"
            MSG,
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('implicitNullDataProvider')]
    public function testNullOnlyUnevaluatedPropertiesAccessor(bool $implicitNull): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new UnevaluatedPropertiesAccessorPostProcessor());
        };

        $className = $this->generateClassFromFile(
            'NullOnlyUnevaluatedProperties.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
            implicitNull: $implicitNull,
        );

        $object = new $className(['name' => 'Albert', 'extra' => null]);
        $accessor = $object->unevaluatedProperties();

        $this->assertNull($accessor->get('extra'));
        $this->assertSame(['extra' => null], $accessor->getAll());
        $this->assertSame(['null'], $this->getReturnTypeNames($accessor, 'get'));
        $this->assertSame(['null'], $this->getParameterTypeNames($accessor, 'set', 1));
    }
}
