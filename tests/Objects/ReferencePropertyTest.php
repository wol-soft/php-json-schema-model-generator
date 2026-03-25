<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use stdClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class ReferencePropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class ReferencePropertyTest extends AbstractPHPModelGeneratorTestCase
{
    protected const EXTERNAL_JSON_DIRECTORIES = ['../ReferencePropertyTest_external'];

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('internalReferenceProvider')]
    public function testNotResolvedInternalReferenceThrowsAnException(string $reference): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            sprintf('/Unresolved Reference %s in file .*\.json/', str_replace('/', '\/', $reference)),
        );

        $this->generateClassFromFileTemplate('NotResolvedReference.json', [$reference]);
    }

    public static function internalReferenceProvider(): array
    {
        return [
            'Internal path reference' => ['#/definitions/person'],
            'Internal direct reference' => ['#person'],
        ];
    }

    public static function externalReferenceProvider(): array
    {
        return [
            'external path reference' => ['../ReferencePropertyTest_external/library.json#/definitions/person'],
            'external direct reference' => ['../ReferencePropertyTest_external/library.json#person'],
            'external file' => ['../ReferencePropertyTest_external/person.json']
        ];
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('internalReferenceProvider')]
    #[DataProvider('externalReferenceProvider')]
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
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validReferenceObjectInputProvider')]
    public function testProvidedReferenceObjectPropertyIsValid(
        string $reference,
        ?array $input,
        string $typeCheck,
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

    public static function validReferenceObjectInputProvider(): array
    {
        return self::combineDataProvider(
            array_merge(self::internalReferenceProvider(), self::externalReferenceProvider()),
            [
                'Empty object' => [[], 'object'],
                'Object with property' => [['name' => 'Hannes', 'age' => 42, 'stringProperty' => 'Hello'], 'object'],
                'Null' => [null, 'null'],
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidInternalReferenceObjectPropertyTypeDataProvider')]
    #[DataProvider('invalidExternalReferenceObjectPropertyTypeDataProvider')]
    public function testInvalidReferenceObjectPropertyTypeThrowsAnException(
        string $reference,
        mixed $propertyValue,
    ): void {
        $this->expectException(ValidationException::class);
        if ($propertyValue instanceof stdClass) {
            $this->expectExceptionMessageMatches(
                '/Invalid class for person. Requires .+, got stdClass/',
            );
        } else {
            $this->expectExceptionMessage('Invalid type for person. Requires object, got ' . gettype($propertyValue));
        }

        $className = $this->generateClassFromFileTemplate('ObjectReference.json', [$reference]);

        new $className(['person' => $propertyValue]);
    }

    public static function invalidInternalReferenceObjectPropertyTypeDataProvider(): array
    {
        return self::combineDataProvider(
            self::internalReferenceProvider(),
            static::invalidObjectPropertyTypeDataProvider(),
        );
    }

    public static function invalidExternalReferenceObjectPropertyTypeDataProvider(): array
    {
        return self::combineDataProvider(
            self::externalReferenceProvider(),
            static::invalidObjectPropertyTypeDataProvider(),
        );
    }

    public static function invalidObjectPropertyTypeDataProvider(): array
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
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validReferenceIntInputProvider')]
    public function testProvidedReferenceIntPropertyIsValid(string $reference, ?int $input): void
    {
        $className = $this->generateClassFromFileTemplate('IntReference.json', [$reference]);

        $object = new $className(['year' => $input]);
        $this->assertSame($input, $object->getYear());
    }

    public static function intReferenceProvider(): array
    {
        return [
            'Internal path reference' => ['#/definitions/yearBetween1900and2000'],
            'Internal direct reference' => ['#yearBetween1900and2000'],
            'External path reference' => ['../ReferencePropertyTest_external/library.json#/definitions/yearBetween1900and2000'],
            'External direct reference' => ['../ReferencePropertyTest_external/library.json#yearBetween1900and2000'],
        ];
    }

    public static function validReferenceIntInputProvider(): array
    {
        return self::combineDataProvider(
            static::intReferenceProvider(),
            [
                'Null' => [null],
                'Upper limit' => [2000],
                'Lower limit' => [1900],
            ],
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidReferenceIntPropertyTypeDataProvider')]
    public function testInvalidReferenceIntPropertyTypeThrowsAnException(
        string $reference,
        mixed $propertyValue,
        string $message,
    ): void {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($message);

        $className = $this->generateClassFromFileTemplate('IntReference.json', [$reference]);

        new $className(['year' => $propertyValue]);
    }

    public static function invalidReferenceIntPropertyTypeDataProvider(): array
    {
        return self::combineDataProvider(
            static::intReferenceProvider(),
            [
                'bool' => [true, 'Invalid type for year'],
                'float' => [0.92, 'Invalid type for year'],
                'array' => [[2], 'Invalid type for year'],
                'object' => [new stdClass(), 'Invalid type for year'],
                'string' => ['1', 'Invalid type for year'],
                'int too low' => [1899, 'Value for year must not be smaller than 1900'],
                'int too high' => [2001, 'Value for year must not be larger than 2000'],
            ],
        );
    }

    public static function recursiveExternalReferenceProvider(): array
    {
        return [
            'external path reference to direct recursion' => ['../ReferencePropertyTest_external/recursiveLibrary.json#/definitions/personDirect'],
            'external direct reference to direct recursion' => ['../ReferencePropertyTest_external/recursiveLibrary.json#personDirect'],
            'external path reference to path recursion' => ['../ReferencePropertyTest_external/recursiveLibrary.json#/definitions/personPath'],
            'external direct reference to path recursion' => ['../ReferencePropertyTest_external/recursiveLibrary.json#personPath'],
        ];
    }

    public static function combinedReferenceProvider(): array
    {
        return array_merge(
            self::combineDataProvider(self::internalReferenceProvider(), self::internalReferenceProvider()),
            self::combineDataProvider(static::recursiveExternalReferenceProvider(), self::internalReferenceProvider()),
            self::combineDataProvider(static::recursiveExternalReferenceProvider(), static::recursiveExternalReferenceProvider()),
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('combinedReferenceProvider')]
    public function testNotProvidedOptionalRecursiveReferenceObjectPropertyIsValid(
        string $reference1,
        string $reference2,
    ): void {
        $className = $this->generateClassFromFileTemplate('RecursiveObjectReference.json', [$reference1, $reference2]);

        $object = new $className(['person' => ['name' => 'Hannes']]);
        $this->assertSame('Hannes', $object->getPerson()->getName());
        $this->assertEmpty($object->getPerson()->getChildren());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('combinedReferenceProvider')]
    public function testProvidedRecursiveReferenceObjectPropertyIsValid(
        string $reference1,
        string $reference2,
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
        $this->assertCount(2, $object->getPerson()->getChildren());
        $this->assertSame('Louis', $object->getPerson()->getChildren()[0]->getName());
        $this->assertEmpty($object->getPerson()->getChildren()[0]->getChildren());
        $this->assertSame('Karl', $object->getPerson()->getChildren()[1]->getName());
        $this->assertCount(1, $object->getPerson()->getChildren()[1]->getChildren());
        $this->assertSame('Yoshi', $object->getPerson()->getChildren()[1]->getChildren()[0]->getName());
        $this->assertEmpty($object->getPerson()->getChildren()[1]->getChildren()[0]->getChildren());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('invalidCombinedReferenceObjectPropertyTypeDataProvider')]
    public function testInvalidProvidedRecursiveReferenceObjectPropertyValueThrowsAnException(
        string $reference1,
        string $reference2,
        mixed $propertyValue,
    ): void {
        $this->expectException(ValidationException::class);

        // TODO: all tests should throw an exception "invalid array item". Currently the messages may differ due to
        // TODO: PropertyProxy resolving
        $this->expectExceptionMessageMatches(
            $propertyValue instanceof stdClass
                ? '/Invalid class for .*/'
                : '/Invalid type for .*/',
        );

        $className = $this->generateClassFromFileTemplate('RecursiveObjectReference.json', [$reference1, $reference2]);

        new $className([
            'person' => [
                'name' => 'Hannes',
                'children' => [$propertyValue]
            ]
        ]);
    }

    public static function invalidCombinedReferenceObjectPropertyTypeDataProvider(): array
    {
        // the combination external reference - external reference must'nt be tested. If the internal person definition
        // from the RecursiveObjectReference.json maps to an external definition and the object reference maps to an
        // external definition the internal definition is never used and thus can be ignored
        return array_merge(
            self::combineDataProvider(
                self::internalReferenceProvider(),
                self::invalidInternalReferenceObjectPropertyTypeDataProvider(),
            ),
            self::combineDataProvider(
                self::externalReferenceProvider(),
                self::invalidInternalReferenceObjectPropertyTypeDataProvider(),
            ),
            self::combineDataProvider(
                self::internalReferenceProvider(),
                self::combineDataProvider(
                    static::recursiveExternalReferenceProvider(),
                    static::invalidObjectPropertyTypeDataProvider(),
                )
            ),
        );
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('nestedReferenceProvider')]
    public function testNestedExternalReference(string $id, string $reference): void
    {
        $className = $this->generateClassFromFileTemplate('NestedExternalReference.json', [$id, $reference]);

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

        $this->assertCount(2, $object->getFamily()->getMember());
        $this->assertSame('Hannes', $object->getFamily()->getMember()[0]->getName());
        $this->assertCount(1, $object->getFamily()->getMember()[0]->getChildren());
        $this->assertSame('Louis', $object->getFamily()->getMember()[0]->getChildren()[0]->getName());
        $this->assertEmpty($object->getFamily()->getMember()[0]->getChildren()[0]->getChildren());
        $this->assertSame('Anette', $object->getFamily()->getMember()[1]->getName());
        $this->assertEmpty($object->getFamily()->getMember()[1]->getChildren());
    }

    public static function nestedReferenceProvider(): array
    {
        $baseURL = 'https://raw.githubusercontent.com/wol-soft/php-json-schema-model-generator/master/tests/Schema/';

        return [
            'local reference - relative' => [
                'NestedExternalReference.json',
                '../ReferencePropertyTest_external/library.json',
            ],
            'local reference - context absolute' => [
                'NestedExternalReference.json',
                '/ReferencePropertyTest_external/library.json',
            ],
            'network reference - full URL' => [
                'NestedExternalReference.json',
                $baseURL . 'ReferencePropertyTest_external/library.json',
            ],
            'network reference - relative path to full URL $id' => [
                $baseURL . 'ReferencePropertyTest/NestedExternalReference.json',
                '../ReferencePropertyTest_external/library.json',
            ],
            'network reference - absolute path to full URL $id' => [
                $baseURL . 'ReferencePropertyTest/NestedExternalReference.json',
                '/wol-soft/php-json-schema-model-generator/master/tests/Schema/ReferencePropertyTest_external/library.json',
            ],
        ];
    }

    #[DataProvider('nonResolvableExternalReferenceProvider')]
    public function testNonResolvableExternalReference(string $id, string $reference): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            sprintf('/Unresolved Reference %s#\/definitions\/family in file .*\.json/', str_replace('/', '\/', $reference)),
        );

        $this->generateClassFromFileTemplate('NestedExternalReference.json', [$id, $reference]);
    }

    public static function nonResolvableExternalReferenceProvider(): array
    {
        $baseURL = 'https://raw.githubusercontent.com/wol-soft/php-json-schema-model-generator/master/tests/Schema/';

        return [
            'local reference - relative' => [
                'NestedExternalReference.json',
                '../ReferencePropertyTest_external/nonexistent.json',
            ],
            'local reference - context absolute' => [
                'NestedExternalReference.json',
                '/ReferencePropertyTest_external/nonexistent.json',
            ],
            'network reference - full URL' => [
                'NestedExternalReference.json',
                $baseURL . 'ReferencePropertyTest_external/nonexistent.json',
            ],
            'network reference - relative path to full URL $id' => [
                $baseURL . 'ReferencePropertyTest/NestedExternalReference.json',
                '../ReferencePropertyTest_external/nonexistent.json',
            ],
            'network reference - absolute path to full URL $id' => [
                $baseURL . 'ReferencePropertyTest/NestedExternalReference.json',
                '/wol-soft/php-json-schema-model-generator/master/tests/Schema/ReferencePropertyTest_external/nonexistent.json',
            ],
        ];
    }

    public function testInvalidBaseReferenceThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('A referenced schema on base level must provide an object definition');

        $this->generateClassFromFile('InvalidBaseReference.json');
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validBaseReferenceObjectInputProvider')]
    public function testValidBaseReference(
        string $reference,
        ?array $input,
    ): void {
        $className = $this->generateClassFromFileTemplate('BaseReference.json', [$reference]);

        $object = new $className($input);

        $this->assertSame($input['name'] ?? null, ($object->getName()));
        $this->assertSame($input['age'] ?? null, ($object->getAge()));
        $this->assertSame($input, ($object->getRawModelDataInput()));
    }

    public static function validBaseReferenceObjectInputProvider(): array
    {
        return self::combineDataProvider(
            array_merge(self::internalReferenceProvider(), self::externalReferenceProvider()),
            [
                'Empty object' => [[]],
                'Object with one property' => [['name' => 'Hannes']],
                'Object with nulled property' => [['name' => 'Hannes', 'age' => null]],
                'Object with additional property' => [['name' => 'Hannes', 'age' => 42, 'stringProperty' => 'Hello']],
            ],
        );
    }

    // -------------------------------------------------------------------------
    // T1: Two schemas in the same base dir both use an out-of-base-dir fragment
    //     ref pointing to the same file. The second schema must reuse the
    //     ExternalSchema from the global processedFileSchemas dedup without
    //     re-parsing the file or producing a duplicate class.
    // -------------------------------------------------------------------------

    public function testOutOfBaseDirFragmentRefReusedAcrossMultipleReferrers(): void
    {
        $namespace = 'T1OutOfBaseDirRef';
        $this->generateDirectory('OutOfBaseDirFragmentRefMultipleReferrers', $this->directoryConfig($namespace));

        $schemaAClass = "\\{$namespace}\\SchemaA";
        $schemaBClass = "\\{$namespace}\\SchemaB";

        $objectA = new $schemaAClass(['person' => ['name' => 'Alice', 'age' => 30]]);
        $objectB = new $schemaBClass(['person' => ['name' => 'Bob', 'age' => 25]]);

        $this->assertSame('Alice', $objectA->getPerson()->getName());
        $this->assertSame('Bob', $objectB->getPerson()->getName());

        // Both schemas resolve the same ref; the person objects must share one class
        $this->assertSame(
            $objectA->getPerson()::class,
            $objectB->getPerson()::class,
            'Both schemas must resolve the out-of-base-dir ref to the same class',
        );
    }

    // -------------------------------------------------------------------------
    // T2: Two schemas in the same base dir both reference the same in-base-dir
    //     file. The second schema's $ref must reuse the already-registered
    //     canonical class via processedFileSchemas.
    // -------------------------------------------------------------------------

    public function testMultipleReferrersToSharedInBaseDirFileProduceSingleClass(): void
    {
        $namespace = 'T2SharedInBaseDirRef';
        $this->generateDirectory('MultipleReferrersSharedInBaseDirFile', $this->directoryConfig($namespace));

        $schemaAClass = "\\{$namespace}\\SchemaA";
        $schemaBClass = "\\{$namespace}\\SchemaB";
        $personClass  = "\\{$namespace}\\Person";

        $objectA = new $schemaAClass(['person' => ['name' => 'Alice']]);
        $objectB = new $schemaBClass(['person' => ['name' => 'Bob']]);

        $this->assertInstanceOf($personClass, $objectA->getPerson());
        $this->assertInstanceOf($personClass, $objectB->getPerson());

        $this->assertSame(
            $objectA->getPerson()::class,
            $objectB->getPerson()::class,
            'Both schemas must share the same canonical Person class',
        );
    }

    // -------------------------------------------------------------------------
    // T3: A file has both a top-level type:object definition and a definitions
    //     section. One schema refs the file top-level, another refs a fragment.
    //     Both must resolve to correct classes.
    // -------------------------------------------------------------------------

    public function testTopLevelAndFragmentRefToBothWorkCorrectly(): void
    {
        $namespace = 'T3TopLevelAndFragment';
        $this->generateDirectory('TopLevelAndFragmentRef', $this->directoryConfig($namespace));

        $employeeClass = "\\{$namespace}\\Employee";
        $personClass   = "\\{$namespace}\\PersonWithHistory";

        $employee = new $employeeClass([
            'profile' => ['name' => 'Alice', 'age' => 30],
            'home'    => ['street' => '123 Main St', 'city' => 'Springfield'],
        ]);

        // Top-level ref resolves to canonical PersonWithHistory class
        $this->assertInstanceOf($personClass, $employee->getProfile());
        $this->assertSame('Alice', $employee->getProfile()->getName());

        // Fragment ref resolves to the definitions/address inline schema
        $this->assertNotNull($employee->getHome());
        $this->assertSame('123 Main St', $employee->getHome()->getStreet());
    }

    // -------------------------------------------------------------------------
    // T5: BasereferenceProcessor with an in-base-dir external file. A schema
    //     with a root-level $ref to another in-base-dir file must merge the
    //     referenced schema's properties into itself.
    // -------------------------------------------------------------------------

    public function testBaseRefToInBaseDirFileMergesProperties(): void
    {
        $namespace = 'T5BaseDirBaseRef';
        $this->generateDirectory('BaseDirBaseRef', $this->directoryConfig($namespace));

        $locationClass = "\\{$namespace}\\Location";

        $location = new $locationClass(['street' => '42 Elm St', 'city' => 'Shelbyville']);

        // Properties from Address.json must be merged directly into Location
        $this->assertSame('42 Elm St', $location->getStreet());
        $this->assertSame('Shelbyville', $location->getCity());
    }

    public function testBaseRefToInBaseDirFileEnforcesRequiredProperty(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Missing required value for street');

        $namespace = 'T5BaseDirBaseRefRequired';
        $this->generateDirectory('BaseDirBaseRef', $this->directoryConfig($namespace));

        $locationClass = "\\{$namespace}\\Location";
        new $locationClass(['city' => 'Shelbyville']);
    }

    // -------------------------------------------------------------------------
    // T6: $ref inside allOf/anyOf/oneOf pointing to an in-base-dir external
    //     file. The referenced file must resolve to the canonical class.
    // -------------------------------------------------------------------------

    public function testAllOfRefToInBaseDirFile(): void
    {
        $namespace = 'T6AllOfRef';
        $this->generateDirectory('CompositionInBaseDirRef', $this->directoryConfig($namespace));

        $allOfClass = "\\{$namespace}\\AllOfRef";
        $object = new $allOfClass(['label' => 'urgent']);

        // allOf with $ref Tag.json: label property must be merged in and required
        $this->assertSame('urgent', $object->getLabel());
    }

    public function testAllOfRefToInBaseDirFileEnforcesTagValidation(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/declined by composition constraint/');

        $namespace = 'T6AllOfRefValidation';
        $this->generateDirectory('CompositionInBaseDirRef', $this->directoryConfig($namespace));

        $allOfClass = "\\{$namespace}\\AllOfRef";
        new $allOfClass([]);
    }

    public function testAnyOfRefToInBaseDirFileAcceptsTagInstance(): void
    {
        $namespace = 'T6AnyOfRef';
        $this->generateDirectory('CompositionInBaseDirRef', $this->directoryConfig($namespace));

        $anyOfClass = "\\{$namespace}\\AnyOfRef";
        $tagClass   = "\\{$namespace}\\Tag";

        $object = new $anyOfClass(['tag' => ['label' => 'feature']]);

        $this->assertInstanceOf($tagClass, $object->getTag());
        $this->assertSame('feature', $object->getTag()->getLabel());
    }

    public function testOneOfRefToInBaseDirFileAcceptsTagInstance(): void
    {
        $namespace = 'T6OneOfRef';
        $this->generateDirectory('CompositionInBaseDirRef', $this->directoryConfig($namespace));

        $oneOfClass = "\\{$namespace}\\OneOfRef";
        $tagClass   = "\\{$namespace}\\Tag";

        $object = new $oneOfClass(['tag' => ['label' => 'bugfix']]);

        $this->assertInstanceOf($tagClass, $object->getTag());
        $this->assertSame('bugfix', $object->getTag()->getLabel());
    }

    private function directoryConfig(string $namespace): GeneratorConfiguration
    {
        return (new GeneratorConfiguration())
            ->setNamespacePrefix($namespace)
            ->setOutputEnabled(false)
            ->setCollectErrors(false);
    }

    public function testMultiplePropertiesWithIdenticalReference(): void
    {
        $className = $this->generateClassFromFile('multiplePropertiesIdenticalReference.json');

        $object = new $className([
            'personA' => ['name' => 'Hannes'],
            'personB' => ['name' => 'Susi']],);

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

    #[DataProvider('invalidValuesForMultiplePropertiesWithIdenticalReferenceDataProvider')]
    public function testInvalidValuesForMultiplePropertiesWithIdenticalReferenceThrowsAnException(
        array $input,
        string $exceptionMessage,
    ): void {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $className = $this->generateClassFromFile(
            'multiplePropertiesIdenticalReference.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        new $className($input);
    }

    public static function invalidValuesForMultiplePropertiesWithIdenticalReferenceDataProvider(): array
    {
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
