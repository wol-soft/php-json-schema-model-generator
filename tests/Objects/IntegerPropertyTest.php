<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;
use Override;
use stdClass;

#[ApplicableDrafts]
class IntegerPropertyTest extends AbstractNumericPropertyTestCase
{
    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalIntPropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('IntegerProperty.json');

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    /**
     * @param int $input
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validInputProvider')]
    public function testProvidedIntPropertyIsValid(?int $input): void
    {
        $className = $this->generateClassFromFile('IntegerProperty.json');

        $object = new $className(['property' => $input]);
        $this->assertSame($input, $object->getProperty());
    }

    public static function validInputProvider(): array
    {
        return [
            'Positive int' => [1],
            'Zero' => [0],
            'Negative int' => [-1],
            'Null' => [null],
        ];
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidPropertyTypeDataProvider')]
    public function testInvalidPropertyTypeThrowsAnException(mixed $propertyValue): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(
            "Invalid type for 'property': requires 'int', got '" .
                (is_object($propertyValue) ? $propertyValue::class : gettype($propertyValue)) . "'",
        );

        $className = $this->generateClassFromFile('IntegerProperty.json');

        new $className(['property' => $propertyValue]);
    }

    public static function invalidPropertyTypeDataProvider(): array
    {
        return [
            'bool' => [true],
            'float' => [0.92],
            // an integral float which doesn't fit into an int
            'float beyond the int range' => [1.0E19],
            'infinity' => [INF],
            'not a number' => [NAN],
            'array' => [[]],
            'object' => [new stdClass()],
            'string' => ['1']
        ];
    }

    /**
     * An integral float is an integer (Draft 6 on, an integer is a number with a zero fractional part): it is accepted
     * and held as int. Rejecting it was rejected: data decoded from JSON which notes the number as `1.0` failed the
     * type check of every integer property.
     */
    #[DataProvider('integralFloatDataProvider')]
    public function testIntegralFloatIsAcceptedAsInt(float $input, int $expected): void
    {
        $className = $this->generateClassFromFile('IntegerProperty.json');

        $this->assertSame($expected, (new $className(['property' => $input]))->getProperty());
    }

    public static function integralFloatDataProvider(): array
    {
        return [
            'one' => [1.0, 1],
            'zero' => [0.0, 0],
            'negative zero' => [-0.0, 0],
            'negative' => [-3.0, -3],
            // the largest integer a float holds exactly
            'two to the power of 53' => [9007199254740992.0, 9007199254740992],
        ];
    }

    /**
     * The integral float is held as int before the keywords validate it: the range of an integer, an integer variant
     * of a type list and the int type derived from an enum or a const. A type list with integer and number holds a
     * number as float.
     */
    public function testIntegralFloatIsValidatedAsInt(): void
    {
        $className = $this->generateClassFromFile('IntegralFloats.json');

        $object = new $className([
            'count' => 3.0,
            'countOrString' => 2.0,
            'countOrNumber' => 2.0,
            'fixed' => 1.0,
            'choice' => 2.0,
            'mixedChoice' => 1.0,
        ]);
        $this->assertSame(3, $object->getCount());
        $this->assertSame(2, $object->getCountOrString());
        $this->assertSame(2.0, $object->getCountOrNumber());
        $this->assertSame(1, $object->getFixed());
        $this->assertSame(2, $object->getChoice());
        $this->assertSame(1, $object->getMixedChoice());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['count' => 1.0],
            ValidationException::class,
            "Value for 'count' must not be smaller than 2",
        );
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['countOrString' => 2.5],
            ValidationException::class,
            "Invalid type for 'countOrString': requires ['int', 'string'], got 'double'",
        );
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['choice' => 3.0],
            ValidationException::class,
            "Value for 'choice' must be one of [1,2,null], got 3",
        );
    }

    /**
     * A property which holds integers and floats compares an integral float with the allowed values like the integer
     * it equals: JSON Schema compares numbers by value. The property holds the number as before: derived from an enum
     * of both types as it is provided, with a type list of integer and number as float. Comparing by the type was
     * rejected: the float 1.0 didn't equal the allowed 1, and an allowed value noted as 1.0 didn't equal the provided
     * 1 (held as float by the type list).
     */
    public function testIntegralFloatEqualsAnAllowedIntOfAPropertyHoldingIntAndFloat(): void
    {
        $className = $this->generateClassFromFile('IntegralFloats.json');

        $object = new $className([
            'numberChoice' => 1.0,
            'countOrNumberConst' => 1.0,
            'countOrNumberChoice' => 1,
        ]);
        $this->assertSame(1.0, $object->getNumberChoice());
        $this->assertSame(1.0, $object->getCountOrNumberConst());
        $this->assertSame(1.0, $object->getCountOrNumberChoice());

        $object = new $className([
            'numberChoice' => 2.5,
            'countOrNumberConst' => 1,
            'countOrNumberChoice' => 1.0,
        ]);
        $this->assertSame(2.5, $object->getNumberChoice());
        $this->assertSame(1.0, $object->getCountOrNumberConst());
        $this->assertSame(1.0, $object->getCountOrNumberChoice());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['numberChoice' => 2.0],
            ValidationException::class,
            "Value for 'numberChoice' must be one of [1,2.5,null], got 2",
        );
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['countOrNumberConst' => 1.5],
            ValidationException::class,
            "Value for 'countOrNumberConst' must be 1, got 1.5",
        );
    }

    #[Override]
    protected function getMultipleOfFile(): string
    {
        return 'IntegerPropertyMultipleOf.json';
    }

    #[Override]
    public static function validMultipleOfDataProvider(): iterable
    {
        return [
            '30 is multiple of 5' => [5, 30],
            '-6 is multiple of 2' => [2, -6],
            '33 is multiple of -11' => [-11, 33],
            '-8 is multiple of -4' => [-4, -8],
            '0 is multiple of 0' => [0, 0],
            'Null is multiple of 2' => [2, null],
        ];
    }

    #[Override]
    public static function invalidMultipleOfDataProvider(): iterable
    {
        return [
            '30 is multiple of 7' => [7, 30],
            '-5 is multiple of 2' => [2, -5],
            '33 is multiple of -10' => [-10, 33],
            '-8 is multiple of -3' => [-3, -8],
            '1 is multiple of 0' => [0, 1],
            '-1 is multiple of 0' => [0, -1],
        ];
    }

    #[Override]
    protected function getRangeFile(bool $exclusive): string
    {
        return $exclusive ? 'IntegerPropertyRangeExclusive.json' : 'IntegerPropertyRange.json';
    }

    #[Override]
    public static function validRangeDataProvider(): iterable
    {
        return [
            'Upper limit' => [1],
            'Zero' => [0],
            'Lower limit' => [-1],
            'Null' => [null],
        ];
    }

    #[Override]
    public static function invalidRangeDataProvider(): iterable
    {
        return [
            'Too large number' => [2, "Value for 'property' must not be larger than 1", 'maximum'],
            'Too small number' => [-2, "Value for 'property' must not be smaller than -1", 'minimum'],
        ];
    }

    #[Override]
    public static function validExclusiveRangeDataProvider(): iterable
    {
        return [
            'Zero' => [0],
            'Null' => [null],
        ];
    }

    #[Override]
    public static function invalidExclusiveRangeDataProvider(): iterable
    {
        return [
            'Too large number 1' => [2, "Value for 'property' must be smaller than 1", 'exclusiveMaximum'],
            'Too large number 2' => [1, "Value for 'property' must be smaller than 1", 'exclusiveMaximum'],
            'Too small number 1' => [-1, "Value for 'property' must be larger than -1", 'exclusiveMinimum'],
            'Too small number 2' => [-2, "Value for 'property' must be larger than -1", 'exclusiveMinimum'],
        ];
    }
}
