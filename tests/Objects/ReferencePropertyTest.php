<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Attributes\SchemaName;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Model\Attributes\PhpAttribute;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use stdClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;

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

        // The outer property always carries the pointer to where $ref appears in the schema,
        // regardless of where the definition lives (inline, path-ref, or external file).
        $this->assertPropertyHasJsonPointer($object, 'person', '/properties/person');

        if ($object->getPerson() !== null) {
            $this->assertSame($input['name'] ?? null, ($object->getPerson()->getName()));
            $this->assertSame($input['age'] ?? null, ($object->getPerson()->getAge()));
            $this->assertSame($input, ($object->getPerson()->meta()->rawInput()));

            // The nested class pointer reflects WHERE THE DEFINITION IS in the schema:
            // at the external file root ('') or at '/definitions/person' for internal refs.
            $person = $object->getPerson();
            if (str_ends_with($reference, 'person.json')) {
                $this->assertClassHasJsonPointer($person, '');
                $this->assertPropertyHasJsonPointer($person, 'name', '/properties/name');
            } else {
                $this->assertClassHasJsonPointer($person, '/definitions/person');
                $this->assertPropertyHasJsonPointer($person, 'name', '/definitions/person/properties/name');
            }
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
                '/Invalid class for person. Requires ReferencePropertyTest_.*, got stdClass/',
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

        // The $ref property's JsonPointer always reflects the reference site, not the definition.
        $this->assertPropertyHasJsonPointer($object, 'year', '/properties/year');
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

    public static function recursivePathRefSchemaProvider(): array
    {
        return [
            // definitions keyword (Draft-07): self-ref via path only, no $id anchor
            'definitions keyword' => ['RecursivePathDefinitionsRef.json'],
            // $defs keyword (Draft 2019-09): self-ref via path only, no $id anchor
            '$defs keyword' => ['RecursivePathDefsRef.json'],
        ];
    }

    /**
     * A definition that refers to itself by path (no $id anchor) must be generated correctly.
     * This covers both the definitions (Draft-07) and $defs (Draft 2019-09) container keywords.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('recursivePathRefSchemaProvider')]
    public function testRecursivePathRefGeneratesAndWorksCorrectly(string $schemaFile): void
    {
        $className = $this->generateClassFromFile($schemaFile);

        // No root provided — optional property is null
        $object = new $className([]);
        $this->assertNull($object->getRoot());

        // Flat usage: root present, no child
        $object = new $className(['root' => ['value' => 'hello']]);
        $this->assertSame('hello', $object->getRoot()->getValue());
        $this->assertNull($object->getRoot()->getChild());

        // Nested usage: one level of recursion
        $object = new $className([
            'root' => [
                'value'  => 'parent',
                'child'  => ['value' => 'child'],
            ],
        ]);
        $this->assertSame('parent', $object->getRoot()->getValue());
        $this->assertSame('child', $object->getRoot()->getChild()->getValue());
        $this->assertNull($object->getRoot()->getChild()->getChild());
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('recursivePathRefSchemaProvider')]
    public function testRecursivePathRefWithInvalidTypeThrowsException(string $schemaFile): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid type for root. Requires object, got integer');

        $className = $this->generateClassFromFile($schemaFile);
        new $className(['root' => 42]);
    }

    // =========================================================================
    // $defs keyword parity (Draft 2019-09)
    // =========================================================================

    public static function defsObjectReferenceProvider(): array
    {
        return [
            // Internal path ref via $defs container
            '$defs internal path ref' => ['#/$defs/person'],
            // External $defs-only library file: no top-level type:object, so it becomes an
            // ExternalSchema; the path ref navigates into its $defs section
            '$defs external path ref (defs-only library)' => [
                '../ReferencePropertyTest_external/defsLibrary.json#/$defs/person',
            ],
        ];
    }

    /**
     * A property whose $ref targets a $defs entry (internal or external) must behave identically
     * to one targeting a definitions entry: null when not provided, correct values when provided,
     * and JsonPointer attributes reflecting /$defs/... path conventions.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('defsObjectReferenceProvider')]
    public function testDefsObjectRefPropertyBehavesCorrectly(string $reference): void
    {
        $className = $this->generateClassFromFileTemplate('DefsObjectReference.json', [$reference]);

        // Not provided — optional property is null
        $object = new $className([]);
        $this->assertNull($object->getPerson());

        // Valid: full object with all properties
        $object = new $className(['person' => ['name' => 'Alice', 'age' => 30]]);
        $this->assertSame('Alice', $object->getPerson()->getName());
        $this->assertSame(30, $object->getPerson()->getAge());
        $this->assertSame(['name' => 'Alice', 'age' => 30], $object->getPerson()->meta()->rawInput());

        // Ref site pointer is always /properties/person regardless of where the definition lives
        $this->assertPropertyHasJsonPointer($object, 'person', '/properties/person');
        // Class pointer reflects /$defs/... for both internal and external $defs refs
        $this->assertClassHasJsonPointer($object->getPerson(), '/$defs/person');
        $this->assertPropertyHasJsonPointer($object->getPerson(), 'name', '/$defs/person/properties/name');
    }

    /**
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('defsObjectReferenceProvider')]
    public function testDefsObjectRefInvalidTypeThrowsException(string $reference): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid type for person. Requires object, got integer');

        $className = $this->generateClassFromFileTemplate('DefsObjectReference.json', [$reference]);
        new $className(['person' => 42]);
    }

    public static function defsRecursiveExternalReferenceProvider(): array
    {
        return [
            // Direct-id recursion: $ref uses the $id anchor (#personDirect)
            '$defs external path ref to direct recursion' => [
                '../ReferencePropertyTest_external/defsRecursiveLibrary.json#/$defs/personDirect',
            ],
            '$defs external id ref to direct recursion' => [
                '../ReferencePropertyTest_external/defsRecursiveLibrary.json#personDirect',
            ],
            // Path recursion: $ref uses the /$defs/personPath path; $id anchor also present
            '$defs external path ref to path recursion' => [
                '../ReferencePropertyTest_external/defsRecursiveLibrary.json#/$defs/personPath',
            ],
            '$defs external id ref to path recursion' => [
                '../ReferencePropertyTest_external/defsRecursiveLibrary.json#personPath',
            ],
        ];
    }

    /**
     * Recursive definitions under $defs with $id anchors must behave identically to equivalent
     * recursive definitions under the definitions keyword.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('defsRecursiveExternalReferenceProvider')]
    public function testDefsExternalRecursiveRefWithIdAnchorBehavesCorrectly(string $reference): void
    {
        $className = $this->generateClassFromFileTemplate('RecursiveObjectReference.json', [$reference, $reference]);

        // No children provided
        $object = new $className(['person' => ['name' => 'Alice']]);
        $this->assertSame('Alice', $object->getPerson()->getName());
        $this->assertEmpty($object->getPerson()->getChildren());

        // One level of recursion
        $object = new $className([
            'person' => [
                'name' => 'Alice',
                'children' => [
                    ['name' => 'Bob', 'children' => []],
                    ['name' => 'Carol'],
                ],
            ],
        ]);
        $this->assertSame('Alice', $object->getPerson()->getName());
        $this->assertCount(2, $object->getPerson()->getChildren());
        $this->assertSame('Bob', $object->getPerson()->getChildren()[0]->getName());
        $this->assertSame('Carol', $object->getPerson()->getChildren()[1]->getName());
    }

    public static function defsBaseReferenceProvider(): array
    {
        return [
            // Path ref into $defs
            '$defs internal path ref' => ['#/$defs/person'],
            // Id ref: $id anchor inside $defs is registered the same way as one inside definitions
            '$defs internal id ref' => ['#person'],
        ];
    }

    /**
     * A root-level $ref targeting a $defs entry must merge the referenced object's properties
     * into the generated class, identical to a definitions-based base reference.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('defsBaseReferenceProvider')]
    public function testDefsBaseReferenceGeneratesCorrectly(string $reference): void
    {
        $className = $this->generateClassFromFileTemplate('DefsBaseReference.json', [$reference]);

        $object = new $className(['name' => 'Alice', 'age' => 30]);
        $this->assertSame('Alice', $object->getName());
        $this->assertSame(30, $object->getAge());
        $this->assertSame(['name' => 'Alice', 'age' => 30], $object->meta()->rawInput());
    }

    /**
     * A schema containing both definitions (Draft-07) and $defs (Draft 2019-09) must resolve
     * refs from each container independently. Properties from definitions carry /definitions/...
     * pointers; properties from $defs carry /$defs/... pointers.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testMixedDefsAndDefinitionsGeneratesCorrectly(): void
    {
        $className = $this->generateClassFromFile('MixedDefsAndDefinitions.json');

        $object = new $className([
            'contact' => ['name' => 'Alice'],
            'user'    => ['email' => 'alice@example.com'],
        ]);

        $this->assertSame('Alice', $object->getContact()->getName());
        $this->assertSame('alice@example.com', $object->getUser()->getEmail());

        // Ref site pointers reflect where each $ref appears in the root schema
        $this->assertPropertyHasJsonPointer($object, 'contact', '/properties/contact');
        $this->assertPropertyHasJsonPointer($object, 'user', '/properties/user');
        // Definition pointers reflect which container keyword was used
        $this->assertClassHasJsonPointer($object->getContact(), '/definitions/legacyPerson');
        $this->assertClassHasJsonPointer($object->getUser(), '/$defs/modernPerson');
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
    #[WithoutErrorHandler]
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
        $this->assertSame($input, ($object->meta()->rawInput()));
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
            ->setLogger(new NullLogger())
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

        // Each property sharing the same $ref definition must have its own reference site pointer.
        // The proxy mechanism must not leak one property's pointer into the other.
        $this->assertPropertyHasJsonPointer($object, 'personA', '/properties/personA');
        $this->assertPropertyHasJsonPointer($object, 'personB', '/properties/personB');
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
                ERROR,
            ],
            'Invalid names for personB' => [
                ['personA' => ['name' => 'A'], 'personB' => ['name' => 10]],
                <<<ERROR
                Invalid nested object for property personA:
                  - Value for name must not be shorter than 3
                Invalid nested object for property personB:
                  - Invalid type for name. Requires string, got integer
                ERROR,
            ],
            'Combined top level validation error and nested error' => [
                ['personA' => ['name' => 'A'], 'personB' => 10],
                <<<ERROR
                Invalid nested object for property personA:
                  - Value for name must not be shorter than 3
                Invalid type for personB. Requires object, got integer
                ERROR,
            ],
        ];
    }

    /**
     * When two properties share a $ref to the same definition, the second property is resolved
     * as a PropertyProxy. The SchemaName attribute must reflect the proxy's own property name,
     * not the name from the underlying shared definition (which belongs to the first property).
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testProxiedPropertyHasOwnSchemaNameAttribute(): void
    {
        $configuration = (new GeneratorConfiguration())
            ->setEnabledAttributes(PhpAttribute::SCHEMA_NAME);

        $className = $this->generateClassFromFile('multiplePropertiesIdenticalReference.json', $configuration);
        $rc = new ReflectionClass($className);

        foreach (['personA', 'personB'] as $propertyName) {
            $attributes = $rc->getProperty($propertyName)->getAttributes(SchemaName::class);
            $this->assertCount(1, $attributes, "Expected one SchemaName attribute on $propertyName");
            $this->assertSame(
                $propertyName,
                $attributes[0]->getArguments()[0],
                "SchemaName for $propertyName should be '$propertyName'",
            );
        }
    }

    /**
     * When a property uses $ref to point to a definition that carries $comment and examples
     * annotations, the PropertyProxy delegates getComment() and getExamples() to the
     * underlying property. The template calls both methods on every rendered property, so
     * generation successfully produces a class and the proxy delegation is exercised.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testRefPropertyWithCommentAndExamplesAnnotationsGeneratesSuccessfully(): void
    {
        $className = $this->generateClassFromFile('AnnotatedDefinitionRef.json');

        $object = new $className([]);
        $this->assertNull($object->getLabel());

        $object = new $className(['label' => 'hello']);
        $this->assertSame('hello', $object->getLabel());
    }

    /**
     * When the root schema carries an $id and a property uses $ref to that $id, the
     * resolved definition's pointer is '' (the document root). The property's JsonPointer
     * must still be the reference site pointer, not the empty string.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testRootIdRefPropertyJsonPointerIsReferencePointer(): void
    {
        $className = $this->generateClassFromFile('RootIdSelfRef.json');

        $object = new $className([]);
        $this->assertPropertyHasJsonPointer($object, 'label', '/properties/label');
        $this->assertPropertyHasJsonPointer($object, 'child', '/properties/child');
    }
}
