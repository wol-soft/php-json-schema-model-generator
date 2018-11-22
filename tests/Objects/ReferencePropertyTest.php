<?php

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use stdClass;

/**
 * Class ReferencePropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class ReferencePropertyTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotResolvedReferenceThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Unresolved Reference: #/definitions/person');

        $this->generateObjectFromFile('NotResolvedReference.json');
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalReferenceObjectPropertyIsValid(): void
    {
        $className = $this->generateObjectFromFile('ObjectReference.json');

        $object = new $className([]);
        $this->assertNull($object->getPerson());
    }

    /**
     * @dataProvider validReferenceObjectInputProvider
     *
     * @param array  $input
     * @param string $typeCheck
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedReferenceObjectPropertyIsValid(?array $input, string $typeCheck): void
    {
        $className = $this->generateObjectFromFile('ObjectReference.json');

        $object = new $className(['person' => $input]);
        $this->assertTrue(('is_' . $typeCheck)($object->getPerson()));

        if ($object->getPerson() !== null) {
            $this->assertSame($input['name'] ?? null, ($object->getPerson()->getName()));
            $this->assertSame($input['age'] ?? null, ($object->getPerson()->getAge()));
            $this->assertSame($input, ($object->getPerson()->getRawModelDataInput()));
        }
    }

    public function validReferenceObjectInputProvider(): array
    {
        return [
            'Empty object' => [[], 'object'],
            'Object with property' => [['name' => 'Hannes', 'age' => 42, 'stringProperty' => 'Hello'], 'object'],
            'Null' => [null, 'null'],
        ];
    }

    /**
     * @dataProvider invalidReferenceObjectPropertyTypeDataProvider
     *
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidReferenceObjectPropertyTypeThrowsAnException($propertyValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid type for person');

        $className = $this->generateObjectFromFile('ObjectReference.json');

        new $className(['person' => $propertyValue]);
    }

    public function invalidReferenceObjectPropertyTypeDataProvider(): array
    {
        return [
            'bool' => [true],
            'float' => [0.92],
            'int' => [2],
            'object' => [new stdClass()],
            'string' => ['1']
        ];
    }

    /**
     * @dataProvider validReferenceIntInputProvider
     *
     * @param int|null $input
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedReferenceIntPropertyIsValid(?int $input): void
    {
        $className = $this->generateObjectFromFile('IntReference.json');

        $object = new $className(['year' => $input]);
        $this->assertSame($input, $object->getYear());
    }

    public function validReferenceIntInputProvider(): array
    {
        return [
            'Null' => [null],
            'Upper limit' => [2000],
            'Lower limit' => [1900],
        ];
    }

    /**
     * @dataProvider invalidReferenceIntPropertyTypeDataProvider
     *
     * @param        $propertyValue
     * @param string $message
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidReferenceIntPropertyTypeThrowsAnException($propertyValue, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $className = $this->generateObjectFromFile('IntReference.json');

        new $className(['year' => $propertyValue]);
    }

    public function invalidReferenceIntPropertyTypeDataProvider(): array
    {
        return [
            'bool' => [true, 'invalid type for year'],
            'float' => [0.92, 'invalid type for year'],
            'array' => [[2], 'invalid type for year'],
            'object' => [new stdClass(), 'invalid type for year'],
            'string' => ['1', 'invalid type for year'],
            'int too low' => [1899, 'Value for year must not be smaller than 1900'],
            'int too high' => [2001, 'Value for year must not be larger than 2000'],
        ];
    }

    public function testNotProvidedOptionalRecursiveReferenceObjectPropertyIsValid()
    {
        $className = $this->generateObjectFromFile('RecursiveObjectReference.json');

        $object = new $className(['person' => ['name' => 'Hannes']]);
        $this->assertSame('Hannes', $object->getPerson()->getName());
        $this->assertEmpty($object->getPerson()->getChildren());
    }

    public function testProvidedRecursiveReferenceObjectPropertyIsValid()
    {
        $className = $this->generateObjectFromFile('RecursiveObjectReference.json');

        $object = new $className([
            'person' => [
                'name' => 'Hannes',
                'children' => [
                    ['name' => 'Louis'],
                    ['name' => 'Karl']
                ]
            ]
        ]);

        $this->assertSame('Hannes', $object->getPerson()->getName());
        $this->assertSame(2, count($object->getPerson()->getChildren()));
        $this->assertSame('Louis', $object->getPerson()->getChildren()[0]->getName());
        $this->assertEmpty($object->getPerson()->getChildren()[0]->getChildren());
        $this->assertSame('Karl', $object->getPerson()->getChildren()[1]->getName());
        $this->assertEmpty($object->getPerson()->getChildren()[1]->getChildren());
    }

    /**
     * @dataProvider invalidReferenceObjectPropertyTypeDataProvider
     *
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidProvidedRecursiveReferenceObjectPropertyValueThrowsAnException($propertyValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid type for person');

        $className = $this->generateObjectFromFile('RecursiveObjectReference.json');

        new $className([
            'person' => [
                'name' => 'Hannes',
                'children' => [$propertyValue]
            ]
        ]);
    }
}
