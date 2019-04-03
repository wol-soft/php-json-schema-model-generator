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
     * @dataProvider referenceProvider
     *
     * @param string $reference
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotResolvedReferenceThrowsAnException(string $reference): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage("Unresolved Reference: $reference");

        $this->generateObjectFromFileTemplate('NotResolvedReference.json', [$reference]);
    }

    public function referenceProvider(): array
    {
        return [
            'Internal path reference' => ['#/definitions/person'],
            'Internal direct reference' => ['#person'],
        ];
    }

    /**
     * @dataProvider referenceProvider
     *
     * @param string $reference
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalReferenceObjectPropertyIsValid(string $reference): void
    {
        $className = $this->generateObjectFromFileTemplate('ObjectReference.json', [$reference]);

        $object = new $className([]);
        $this->assertNull($object->getPerson());
    }

    /**
     * @dataProvider validReferenceObjectInputProvider
     *
     * @param string $reference
     * @param array  $input
     * @param string $typeCheck
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedReferenceObjectPropertyIsValid(
        string $reference,
        ?array $input,
        string $typeCheck
    ): void {
        $className = $this->generateObjectFromFileTemplate('ObjectReference.json', [$reference]);

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
        return $this->combineDataProvider(
            $this->referenceProvider(),
            [
                'Empty object' => [[], 'object'],
                'Object with property' => [['name' => 'Hannes', 'age' => 42, 'stringProperty' => 'Hello'], 'object'],
                'Null' => [null, 'null'],
            ]
        );
    }

    /**
     * @dataProvider invalidReferenceObjectPropertyTypeDataProvider
     *
     * @param string $reference
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidReferenceObjectPropertyTypeThrowsAnException(string $reference, $propertyValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid type for person');

        $className = $this->generateObjectFromFileTemplate('ObjectReference.json', [$reference]);

        new $className(['person' => $propertyValue]);
    }

    public function invalidReferenceObjectPropertyTypeDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->referenceProvider(),
            [
                'bool' => [true],
                'float' => [0.92],
                'int' => [2],
                'object' => [new stdClass()],
                'string' => ['1']
            ]
        );
    }

    /**
     * @dataProvider validReferenceIntInputProvider
     *
     * @param string   $reference
     * @param int|null $input
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedReferenceIntPropertyIsValid(string $reference, ?int $input): void
    {
        $className = $this->generateObjectFromFileTemplate('IntReference.json', [$reference]);

        $object = new $className(['year' => $input]);
        $this->assertSame($input, $object->getYear());
    }

    public function intReferenceProvider(): array
    {
        return [
            'Internal path reference' => ['#/definitions/yearBetween1900and2000'],
            'Internal direct reference' => ['#yearBetween1900and2000'],
        ];
    }

    public function validReferenceIntInputProvider(): array
    {
        return $this->combineDataProvider(
            $this->intReferenceProvider(),
            [
                'Null' => [null],
                'Upper limit' => [2000],
                'Lower limit' => [1900],
            ]
        );
    }

    /**
     * @dataProvider invalidReferenceIntPropertyTypeDataProvider
     *
     * @param string $reference
     * @param        $propertyValue
     * @param string $message
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidReferenceIntPropertyTypeThrowsAnException(
        string $reference,
        $propertyValue,
        string $message
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $className = $this->generateObjectFromFileTemplate('IntReference.json', [$reference]);

        new $className(['year' => $propertyValue]);
    }

    public function invalidReferenceIntPropertyTypeDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->intReferenceProvider(),
            [
                'bool' => [true, 'invalid type for year'],
                'float' => [0.92, 'invalid type for year'],
                'array' => [[2], 'invalid type for year'],
                'object' => [new stdClass(), 'invalid type for year'],
                'string' => ['1', 'invalid type for year'],
                'int too low' => [1899, 'Value for year must not be smaller than 1900'],
                'int too high' => [2001, 'Value for year must not be larger than 2000'],
            ]
        );
    }

    public function combinedReferenceProvider(): array
    {
        return $this->combineDataProvider($this->referenceProvider(), $this->referenceProvider());
    }

    /**
     * @dataProvider combinedReferenceProvider
     *
     * @param string $reference1
     * @param string $reference2
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalRecursiveReferenceObjectPropertyIsValid(
        string $reference1,
        string $reference2
    ): void {
        $className = $this->generateObjectFromFileTemplate('RecursiveObjectReference.json', [$reference1, $reference2]);

        $object = new $className(['person' => ['name' => 'Hannes']]);
        $this->assertSame('Hannes', $object->getPerson()->getName());
        $this->assertEmpty($object->getPerson()->getChildren());
    }

    /**
     * @dataProvider combinedReferenceProvider
     *
     * @param string $reference1
     * @param string $reference2
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedRecursiveReferenceObjectPropertyIsValid(
        string $reference1,
        string $reference2
    ): void {
        $className = $this->generateObjectFromFileTemplate('RecursiveObjectReference.json', [$reference1, $reference2]);

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
     * @dataProvider invalidCombinedReferenceObjectPropertyTypeDataProvider
     *
     * @param string $reference1
     * @param string $reference2
     * @param $propertyValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidProvidedRecursiveReferenceObjectPropertyValueThrowsAnException(
        string $reference1,
        string $reference2,
        $propertyValue
    ): void {
        $this->expectException(InvalidArgumentException::class);

        // TODO: all tests should throw an exception "invalid arrayItem". Currently the messages may differ due to
        // TODO: PropertyProxy resolving
        $this->expectExceptionMessageRegExp('/^invalid type for (.*)$/');

        $className = $this->generateObjectFromFileTemplate('RecursiveObjectReference.json', [$reference1, $reference2]);

        new $className([
            'person' => [
                'name' => 'Hannes',
                'children' => [$propertyValue]
            ]
        ]);
    }

    public function invalidCombinedReferenceObjectPropertyTypeDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->referenceProvider(),
            $this->invalidReferenceObjectPropertyTypeDataProvider()
        );
    }

    /**
     * Combine two data providers
     *
     * @param array $dataProvider1
     * @param array $dataProvider2
     *
     * @return array
     */
    protected function combineDataProvider(array $dataProvider1, array $dataProvider2): array
    {
        $result = [];
        foreach ($dataProvider1 as $dp1Key => $dp1Value) {
            foreach ($dataProvider2 as $dp2Key => $dp2Value) {
                $result["$dp1Key - $dp2Key"] = array_merge($dp1Value, $dp2Value);
            }
        }

        return $result;
    }
}
