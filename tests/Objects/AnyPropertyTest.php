<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\Arrays\MinItemsException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\String\MinLengthException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use stdClass;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class AnyPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
#[ApplicableDrafts]
class AnyPropertyTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testNotProvidedOptionalAnyPropertyIsValid(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('AnyProperty.json', $configuration);

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testNotProvidedRequiredAnyPropertyThrowsAnException(GeneratorConfiguration $configuration): void
    {
        $this->expectValidationError($configuration, 'Missing required value for property');
        $className = $this->generateClassFromFile('RequiredAnyProperty.json', $configuration);

        new $className([]);
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validPropertyTypeDataProvider')]
    public function testAnyProvidedTypeIsValid(GeneratorConfiguration $configuration, mixed $propertyValue): void
    {
        $className = $this->generateClassFromFile('AnyProperty.json', $configuration);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public static function validPropertyTypeDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'int' => [0],
                'float' => [0.92],
                'bool' => [true],
                'array' => [[]],
                'object' => [new stdClass()],
                'string' => ['null'],
                'null' => [null],
            ],
        );
    }

    /**
     * A string applicator (minLength) declared without an explicit `type: string` still applies —
     * JSON Schema applicators are not gated on a type declaration. The emitted validator self-gates
     * on `is_string`, so a value of any other type imposes no length constraint and passes through,
     * while a string violating the constraint is rejected.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testUntypedStringApplicatorSelfGates(): void
    {
        $className = $this->generateClassFromFile('AnyPropertyStringConstraint.json');

        // Non-string values impose no length constraint and pass through unchanged.
        $this->assertSame(42, (new $className(['property' => 42]))->getProperty());
        $this->assertSame([1], (new $className(['property' => [1]]))->getProperty());
        $this->assertNull((new $className([]))->getProperty());
        // A string satisfying minLength is accepted.
        $this->assertSame('abcd', (new $className(['property' => 'abcd']))->getProperty());

        // A string shorter than minLength is rejected — the applicator applies without `type: string`.
        try {
            new $className(['property' => 'ab']);
            $this->fail('A string shorter than minLength must be rejected on an untyped property');
        } catch (MinLengthException $exception) {
            $this->assertSame('Value for property must not be shorter than 3', $exception->getMessage());
            $this->assertSame('/properties/property/minLength', $exception->getJsonPointer()->pointer);
        }
    }

    /**
     * An array applicator (minItems) declared without an explicit `type: array` still applies. The
     * emitted validator self-gates on `is_array`, so a value of any other type passes through while
     * an array violating the constraint is rejected.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testUntypedArrayApplicatorSelfGates(): void
    {
        $className = $this->generateClassFromFile('AnyPropertyArrayConstraint.json');

        // Non-array values impose no size constraint and pass through unchanged.
        $this->assertSame('x', (new $className(['property' => 'x']))->getProperty());
        $this->assertSame(5, (new $className(['property' => 5]))->getProperty());
        $this->assertNull((new $className([]))->getProperty());
        // An array satisfying minItems is accepted.
        $this->assertSame([1, 2], (new $className(['property' => [1, 2]]))->getProperty());

        // An array shorter than minItems is rejected.
        try {
            new $className(['property' => [1]]);
            $this->fail('An array shorter than minItems must be rejected on an untyped property');
        } catch (MinItemsException $exception) {
            $this->assertSame(
                'Array property must not contain less than 2 items, 1 items provided',
                $exception->getMessage(),
            );
            $this->assertSame('/properties/property/minItems', $exception->getJsonPointer()->pointer);
        }
    }

    /**
     * An untyped subschema may mix object and scalar applicators. Each self-gates independently: an
     * object value is wrapped in the generated nested class and validated against the object
     * applicators; a string value is validated against minLength; any other value is accepted. The
     * getter stays permissive (`<NestedClass>|mixed`) because the slot may hold the nested object OR
     * any other type the untyped schema also accepts — it is not typed as the nested class alone.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testUntypedMixedObjectAndScalarApplicatorsEachSelfGate(): void
    {
        $className = $this->generateClassFromFile('AnyPropertyObjectAndStringConstraint.json');

        // Object value: the object applicators apply — the declared key is exposed through the
        // generated nested class.
        $object = (new $className(['property' => ['known' => 'a']]))->getProperty();
        $this->assertIsObject($object);
        $this->assertSame('a', $object->getKnown());

        // Non-object, non-string values impose no constraint and pass through.
        $this->assertSame(42, (new $className(['property' => 42]))->getProperty());
        // A string satisfying minLength passes through unchanged (it is not wrapped as an object).
        $this->assertSame('abcd', (new $className(['property' => 'abcd']))->getProperty());

        // The getter is permissive: `<NestedClass>|mixed` annotation over a `mixed` native type.
        $this->assertMatchesRegularExpression(
            '/^\w+\|mixed$/',
            $this->getReturnTypeAnnotation($className, 'getProperty'),
        );

        // String applicator gates independently: a short string is rejected by minLength.
        try {
            new $className(['property' => 'ab']);
            $this->fail('minLength must reject a short string even alongside object applicators');
        } catch (MinLengthException $exception) {
            $this->assertSame('Value for property must not be shorter than 3', $exception->getMessage());
            $this->assertSame('/properties/property/minLength', $exception->getJsonPointer()->pointer);
        }

        // Object applicator gates independently: an undeclared key is rejected by additionalProperties.
        try {
            new $className(['property' => ['known' => 'a', 'extra' => 1]]);
            $this->fail('additionalProperties:false must reject an undeclared key on the nested object');
        } catch (NestedObjectException $exception) {
            $this->assertMatchesRegularExpression(
                <<<'REGEX'
                /^Invalid nested object for property property:
                  - Provided JSON for .+ contains not allowed additional properties \[extra\]$/
                REGEX,
                $exception->getMessage(),
            );
        }
    }

    /**
     * A bare untyped schema (`{}`) carries no applicators, so no nested class is generated and the
     * property stays fully permissive — the annotated return type is `mixed`, not a class union.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testUntypedEmptySchemaRemainsMixedWithoutNestedClass(): void
    {
        $className = $this->generateClassFromFile('AnyProperty.json');

        $this->assertSame('mixed', $this->getReturnTypeAnnotation($className, 'getProperty'));
    }
}
