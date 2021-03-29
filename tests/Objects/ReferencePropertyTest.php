<?php

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use stdClass;

/**
 * Class ReferencePropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class ReferencePropertyTest extends AbstractPHPModelGeneratorTest
{
    protected const EXTERNAL_JSON_DIRECTORIES = ['../ReferencePropertyTest_external'];

    /**
     * @dataProvider internalReferenceProvider
     * @dataProvider notResolvedExternalReferenceProvider
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
        $this->expectExceptionMessageMatches(
            sprintf('/Unresolved Reference %s in file .*\.json/', str_replace('/', '\/', $reference))
        );

        $this->generateClassFromFileTemplate('NotResolvedReference.json', [$reference]);
    }

    public function internalReferenceProvider(): array
    {
        return [
            'Internal path reference' => ['#/definitions/person'],
            'Internal direct reference' => ['#person'],
        ];
    }

    public function externalReferenceProvider(): array
    {
        return [
            'external path reference' => ['../ReferencePropertyTest_external/library.json#/definitions/person'],
            'external direct reference' => ['../ReferencePropertyTest_external/library.json#person'],
            'external file' => ['../ReferencePropertyTest_external/person.json']
        ];
    }

    public function notResolvedExternalReferenceProvider(): array
    {
        return [
            'External non existing file' => ['../ReferencePropertyTest_external/notExisting'],
            'External path reference in non existing file' => ['../ReferencePropertyTest_external/notExisting#person'],
            'External non existing path reference' => ['../ReferencePropertyTest_external/library.json#/definitions/animal'],
            'External non existing direct reference' => ['../ReferencePropertyTest_external/library.json#animal'],
        ];
    }

    /**
     * @dataProvider internalReferenceProvider
     * @dataProvider externalReferenceProvider
     *
     * @param string $reference
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNotProvidedOptionalReferenceObjectPropertyIsValid(string $reference): void
    {
        $className = $this->generateClassFromFileTemplate('ObjectReference.json', [$reference]);

        $object = new $className([]);
        $this->assertNull($object->getPerson());
    }

    public function testIdWithoutHashSymbolIsResolved(): void
    {
        $className = $this->generateClassFromFile('IdWithoutHashSymbolReference.json');

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
        $className = $this->generateClassFromFileTemplate('ObjectReference.json', [$reference]);

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
            array_merge($this->internalReferenceProvider(), $this->externalReferenceProvider()),
            [
                'Empty object' => [[], 'object'],
                'Object with property' => [['name' => 'Hannes', 'age' => 42, 'stringProperty' => 'Hello'], 'object'],
                'Null' => [null, 'null'],
            ]
        );
    }

    /**
     * @dataProvider invalidInternalReferenceObjectPropertyTypeDataProvider
     * @dataProvider invalidExternalReferenceObjectPropertyTypeDataProvider
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
        $this->expectException(ValidationException::class);
        if ($propertyValue instanceof stdClass) {
            $this->expectExceptionMessageMatches(
                '/Invalid class for person. Requires ReferencePropertyTest_.*, got stdClass/'
            );
        } else {
            $this->expectExceptionMessage('Invalid type for person. Requires object, got ' . gettype($propertyValue));
        }

        $className = $this->generateClassFromFileTemplate('ObjectReference.json', [$reference]);

        new $className(['person' => $propertyValue]);
    }

    public function invalidInternalReferenceObjectPropertyTypeDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->internalReferenceProvider(),
            $this->invalidObjectPropertyTypeDataProvider()
        );
    }

    public function invalidExternalReferenceObjectPropertyTypeDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->externalReferenceProvider(),
            $this->invalidObjectPropertyTypeDataProvider()
        );
    }

    public function invalidObjectPropertyTypeDataProvider(): array
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
     * @param string   $reference
     * @param int|null $input
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProvidedReferenceIntPropertyIsValid(string $reference, ?int $input): void
    {
        $className = $this->generateClassFromFileTemplate('IntReference.json', [$reference]);

        $object = new $className(['year' => $input]);
        $this->assertSame($input, $object->getYear());
    }

    public function intReferenceProvider(): array
    {
        return [
            'Internal path reference' => ['#/definitions/yearBetween1900and2000'],
            'Internal direct reference' => ['#yearBetween1900and2000'],
            'External path reference' => ['../ReferencePropertyTest_external/library.json#/definitions/yearBetween1900and2000'],
            'External direct reference' => ['../ReferencePropertyTest_external/library.json#yearBetween1900and2000'],
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
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($message);

        $className = $this->generateClassFromFileTemplate('IntReference.json', [$reference]);

        new $className(['year' => $propertyValue]);
    }

    public function invalidReferenceIntPropertyTypeDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->intReferenceProvider(),
            [
                'bool' => [true, 'Invalid type for year'],
                'float' => [0.92, 'Invalid type for year'],
                'array' => [[2], 'Invalid type for year'],
                'object' => [new stdClass(), 'Invalid type for year'],
                'string' => ['1', 'Invalid type for year'],
                'int too low' => [1899, 'Value for year must not be smaller than 1900'],
                'int too high' => [2001, 'Value for year must not be larger than 2000'],
            ]
        );
    }

    public function recursiveExternalReferenceProvider(): array
    {
        return [
            'external path reference to direct recursion' => ['../ReferencePropertyTest_external/recursiveLibrary.json#/definitions/personDirect'],
            'external direct reference to direct recursion' => ['../ReferencePropertyTest_external/recursiveLibrary.json#personDirect'],
            'external path reference to path recursion' => ['../ReferencePropertyTest_external/recursiveLibrary.json#/definitions/personPath'],
            'external direct reference to path recursion' => ['../ReferencePropertyTest_external/recursiveLibrary.json#personPath'],
        ];
    }

    public function combinedReferenceProvider(): array
    {
        return array_merge(
            $this->combineDataProvider($this->internalReferenceProvider(), $this->internalReferenceProvider()),
            $this->combineDataProvider($this->recursiveExternalReferenceProvider(), $this->internalReferenceProvider()),
            $this->combineDataProvider($this->recursiveExternalReferenceProvider(), $this->recursiveExternalReferenceProvider())
        );
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
        $className = $this->generateClassFromFileTemplate('RecursiveObjectReference.json', [$reference1, $reference2]);

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
        $className = $this->generateClassFromFileTemplate('RecursiveObjectReference.json', [$reference1, $reference2]);

        $object = new $className([
            'person' => [
                'name' => 'Hannes',
                'children' => [
                    [
                        'name' => 'Louis'
                    ],
                    [
                        'name' => 'Karl',
                        'children' => [
                            [
                                'name' => 'Yoshi'
                            ],
                        ],
                    ],
                ]
            ]
        ]);

        $this->assertSame('Hannes', $object->getPerson()->getName());
        $this->assertSame(2, count($object->getPerson()->getChildren()));
        $this->assertSame('Louis', $object->getPerson()->getChildren()[0]->getName());
        $this->assertEmpty($object->getPerson()->getChildren()[0]->getChildren());
        $this->assertSame('Karl', $object->getPerson()->getChildren()[1]->getName());
        $this->assertSame(1, count($object->getPerson()->getChildren()[1]->getChildren()));
        $this->assertSame('Yoshi', $object->getPerson()->getChildren()[1]->getChildren()[0]->getName());
        $this->assertEmpty($object->getPerson()->getChildren()[1]->getChildren()[0]->getChildren());
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
        $this->expectException(ValidationException::class);

        // TODO: all tests should throw an exception "invalid array item". Currently the messages may differ due to
        // TODO: PropertyProxy resolving
        $this->expectExceptionMessageMatches(
            $propertyValue instanceof stdClass
                ? '/Invalid class for .*/'
                : '/Invalid type for .*/'
        );

        $className = $this->generateClassFromFileTemplate('RecursiveObjectReference.json', [$reference1, $reference2]);

        new $className([
            'person' => [
                'name' => 'Hannes',
                'children' => [$propertyValue]
            ]
        ]);
    }

    public function invalidCombinedReferenceObjectPropertyTypeDataProvider(): array
    {
        // the combination external reference - external reference must'nt be tested. If the internal person definition
        // from the RecursiveObjectReference.json maps to an external definition and the object reference maps to an
        // external definition the internal definition is never used and thus can be ignored
        return array_merge(
            $this->combineDataProvider(
                $this->internalReferenceProvider(),
                $this->invalidInternalReferenceObjectPropertyTypeDataProvider()
            ),
            $this->combineDataProvider(
                $this->externalReferenceProvider(),
                $this->invalidInternalReferenceObjectPropertyTypeDataProvider()
            ),
            $this->combineDataProvider(
                $this->internalReferenceProvider(),
                $this->combineDataProvider(
                    $this->recursiveExternalReferenceProvider(),
                    $this->invalidObjectPropertyTypeDataProvider()
                )
            )
        );
    }

    /**
     * @dataProvider nestedReferenceProvider
     *
     * @param string $reference
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testNestedExternalReference(string $reference): void
    {
        $className = $this->generateClassFromFileTemplate('NestedExternalReference.json', [$reference]);

        $object = new $className([
            'family' => [
                'member' => [
                    [
                        'name' => 'Hannes',
                        'children' => [
                            ['name' => 'Louis'],
                        ],
                    ],
                    [
                        'name' => 'Anette',
                    ],
                ],
            ]
        ]);

        $this->assertSame(2, count($object->getFamily()->getMember()));
        $this->assertSame('Hannes', $object->getFamily()->getMember()[0]->getName());
        $this->assertSame(1, count($object->getFamily()->getMember()[0]->getChildren()));
        $this->assertSame('Louis', $object->getFamily()->getMember()[0]->getChildren()[0]->getName());
        $this->assertEmpty($object->getFamily()->getMember()[0]->getChildren()[0]->getChildren());
        $this->assertSame('Anette', $object->getFamily()->getMember()[1]->getName());
        $this->assertEmpty($object->getFamily()->getMember()[1]->getChildren());
    }

    public function nestedReferenceProvider(): array
    {
        return [
            'Local reference' => ['../ReferencePropertyTest_external/library.json'],
            'Network reference' => ['https://raw.githubusercontent.com/wol-soft/php-json-schema-model-generator/master/tests/Schema/ReferencePropertyTest_external/library.json'],
        ];
    }

    public function testInvalidBaseReferenceThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('A referenced schema on base level must provide an object definition');

        $this->generateClassFromFile('InvalidBaseReference.json');
    }

    /**
     * @dataProvider validBaseReferenceObjectInputProvider
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testValidBaseReference(
        string $reference,
        ?array $input
    ): void {
        $className = $this->generateClassFromFileTemplate('BaseReference.json', [$reference]);

        $object = new $className($input);

        $this->assertSame($input['name'] ?? null, ($object->getName()));
        $this->assertSame($input['age'] ?? null, ($object->getAge()));
        $this->assertSame($input, ($object->getRawModelDataInput()));
    }

    public function validBaseReferenceObjectInputProvider(): array
    {
        return $this->combineDataProvider(
            array_merge($this->internalReferenceProvider(), $this->externalReferenceProvider()),
            [
                'Empty object' => [[]],
                'Object with one property' => [['name' => 'Hannes']],
                'Object with nulled property' => [['name' => 'Hannes', 'age' => null]],
                'Object with additional property' => [['name' => 'Hannes', 'age' => 42, 'stringProperty' => 'Hello']],
            ]
        );
    }

    public function testMultiplePropertiesWithIdenticalReference(): void
    {
        $className = $this->generateClassFromFile('multiplePropertiesIdenticalReference.json');

        $object = new $className([
            'personA' => ['name' => 'Hannes'],
            'personB' => ['name' => 'Susi']]
        );

        $this->assertTrue(is_callable([$object, 'getPersonA']));
        $this->assertTrue(is_callable([$object, 'getPersonB']));

        // test if the properties are typed correctly
        $returnTypePersonA = $this->getReturnType($object, 'getPersonA');
        $returnTypePersonB = $this->getReturnType($object, 'getPersonB');
        $this->assertSame($returnTypePersonA->getName(), $returnTypePersonB->getName());
        // as the property is optional they may contain an initial null value
        $this->assertTrue($returnTypePersonA->allowsNull());
        $this->assertTrue($returnTypePersonB->allowsNull());

        $this->assertSame('Hannes', $object->getPersonA()->getName());
        $this->assertSame('Susi', $object->getPersonB()->getName());
    }

    /**
     * @dataProvider invalidValuesForMultiplePropertiesWithIdenticalReferenceDataProvider
     *
     * @param array $input
     * @param string $exceptionMessage
     */
    public function testInvalidValuesForMultiplePropertiesWithIdenticalReferenceThrowsAnException(
        array $input,
        string $exceptionMessage
    ): void {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $className = $this->generateClassFromFile(
            'multiplePropertiesIdenticalReference.json',
            (new GeneratorConfiguration())->setCollectErrors(true)
        );

        new $className($input);
    }

    public function invalidValuesForMultiplePropertiesWithIdenticalReferenceDataProvider(): array {
        return [
            'Invalid value for personA' => [
                ['personA' => 10],
                'Invalid type for personA. Requires object, got integer',
            ],
            'Invalid value for both persons' => [
                ['personA' => 10, 'personB' => false],
                <<<ERROR
Invalid type for personA. Requires object, got integer
Invalid type for personB. Requires object, got boolean
ERROR
            ],
            'Invalid names for personB' => [
                ['personA' => ['name' => 'A'], 'personB' => ['name' => 10]],
                <<<ERROR
Invalid nested object for property personA:
  - Value for name must not be shorter than 3
Invalid nested object for property personB:
  - Invalid type for name. Requires string, got integer
ERROR
            ],
            'Combined top level validation error and nested error' => [
                ['personA' => ['name' => 'A'], 'personB' => 10],
                <<<ERROR
Invalid nested object for property personA:
  - Value for name must not be shorter than 3
Invalid type for personB. Requires object, got integer
ERROR
            ],
        ];
    }
}
