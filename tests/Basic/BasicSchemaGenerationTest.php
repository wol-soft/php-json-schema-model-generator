<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use Exception;
use JsonSerializable;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Interfaces\JSONModelInterface;
use PHPModelGenerator\Interfaces\SerializationInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\Hook\SetterBeforeValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;

#[ApplicableDrafts]
class BasicSchemaGenerationTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * @param bool $nullable
     */
    #[DataProvider('implicitNullDataProvider')]
    public function testGetterAndSetterAreGeneratedForMutableObjects(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull,
        );

        $object = new $className(['property' => 'Hello']);

        $this->assertTrue(is_callable([$object, 'getProperty']));
        $this->assertTrue(is_callable([$object, 'setProperty']));
        $this->assertSame('Hello', $object->getProperty());

        $this->assertSame($object, $object->setProperty('Bye'));
        $this->assertSame('Bye', $object->getProperty());

        if ($implicitNull) {
            $this->assertSame($object, $object->setProperty(null));
            $this->assertNull($object->getProperty());
        }

        // test if the property is typed correctly
        $returnType = $this->getReturnType($object, 'getProperty');
        $this->assertSame('string', $returnType->getName());
        // as the property is optional it may contain an initial null value
        $this->assertTrue($returnType->allowsNull());

        $setType = $this->getParameterType($object, 'setProperty');
        $this->assertSame('string', $setType->getName());
        $this->assertSame($implicitNull, $setType->allowsNull());
    }

    public function testSetterLogicIsNotExecutedWhenValueIsIdentical(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $modelGenerator): void {
            $modelGenerator->addPostProcessor(new class () extends PostProcessor {
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
            'BasicSchema.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['property' => 'Hello']);
        $object->setProperty('Hello');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('SetterBeforeValidationHook');
        $object->setProperty('Goodbye');
    }

    public function testGetterAndSetterAreNotGeneratedByDefault(): void
    {
        $className = $this->generateClassFromFile('BasicSchema.json');

        $object = new $className([]);

        $this->assertTrue(is_callable([$object, 'getProperty']));
        $this->assertFalse(is_callable([$object, 'setProperty']));
        $this->assertNull($object->getProperty());
    }

    public function testClassInstantiationWithoutParameter(): void
    {
        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className();
        $this->assertNull($object->getProperty());

        $object->setProperty('Hello');

        $this->assertSame('Hello', $object->getProperty());
    }

    public function testReadOnlyPropertyDoesntGenerateSetter(): void
    {
        $className = $this->generateClassFromFile(
            'ReadOnly.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className([]);

        $this->assertTrue(is_callable([$object, 'getReadOnlyTrue']));
        $this->assertFalse(is_callable([$object, 'setReadOnlyTrue']));

        $this->assertTrue(is_callable([$object, 'getReadOnlyFalse']));
        $this->assertTrue(is_callable([$object, 'setReadOnlyFalse']));

        $this->assertTrue(is_callable([$object, 'getNoReadOnly']));
        $this->assertTrue(is_callable([$object, 'setNoReadOnly']));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function reservedClassNameProvider(): array
    {
        return [
            'reserved keyword readonly' => ['ReadOnly'],
            'reserved keyword class' => ['Class'],
            'reserved keyword match' => ['Match'],
            'reserved type name int' => ['Int'],
            'reserved type name mixed' => ['Mixed'],
            'reserved class name self' => ['Self'],
            'reserved constant name null' => ['Null'],
        ];
    }

    /**
     * A schema whose derived class name is a PHP reserved word (`readonly` is reserved since PHP
     * 8.1, as are all keywords and the builtin type names) can never be written as a class
     * declaration: the rendered file fails to compile. The generator must reject it at generation
     * time with a SchemaException that names the offending class name and the file, instead of
     * letting RenderJob::render() surface a ParseError from require().
     *
     * The check is case-insensitive, as PHP resolves reserved words independent of case.
     *
     * An explicit inline `title` plus `$originalClassNames: true` is required to reproduce this
     * through the test harness: `generateClassFromFile()`'s default path always writes the schema
     * to a temp file named after the *test's own* generated class name (see
     * AbstractPHPModelGeneratorTestCase::generateClass()) regardless of the flag, so only an
     * explicit `title` (which the class-name generator prioritizes over the filename) reliably
     * forces the collision.
     */
    #[DataProvider('reservedClassNameProvider')]
    public function testClassNameCollidingWithPhpReservedWordThrowsSchemaException(string $title): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/^' . preg_quote("Class name '$title' is a reserved PHP word and cannot be used for the generated"
                . " class of file ", '/')
                . '\S+: set a different \'title\' on the schema or configure a custom class name generator'
                . ' at line \d+, column \d+$/',
        );

        $this->generateClass(
            sprintf('{"title": "%s", "type": "object", "properties": {"name": {"type": "string"}}}', $title),
            null,
            true,
        );
    }

    /**
     * Control for the reserved-word check: `Enum`, `Resource` and `Numeric` look like candidates but
     * are not reserved as class names, so they must keep generating working classes.
     *
     * Restricted to one draft: the generated class carries the fixed name from the title and every
     * generation loads it into the same PHP process, so a second draft run would redeclare it. The
     * reserved-word check is independent of the draft.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2020_12)]
    #[DataProvider('nonReservedClassNameProvider')]
    public function testClassNameResemblingAReservedWordIsAccepted(string $title): void
    {
        $this->generateClass(
            sprintf('{"title": "%s", "type": "object", "properties": {"name": {"type": "string"}}}', $title),
            null,
            true,
        );

        // The harness returns its own file-based name; the generated class carries the title.
        $generatedClassName = $this->lastGeneratedNamespacePrefix . '\\' . $title;
        $this->assertTrue(class_exists($generatedClassName), "Class $generatedClassName must have been generated");
        $this->assertSame('Alice', (new $generatedClassName(['name' => 'Alice']))->getName());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonReservedClassNameProvider(): array
    {
        return [
            'enum is contextual only' => ['Enum'],
            'resource is not reserved' => ['Resource'],
            'numeric is not reserved' => ['Numeric'],
        ];
    }

    public function testSetterChangeTheInternalState(): void
    {
        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['property' => 'Hello']);

        $this->assertSame('Hello', $object->getProperty());
        $this->assertSame($object, $object->setProperty('NewValue'));
        $this->assertSame('NewValue', $object->getProperty());
    }

    public function testSerializationFunctionsAreNotGeneratedByDefault(): void
    {
        $className = '\\MyApp\\' . $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setNamespacePrefix('MyApp'),
        );

        $object = new $className(['property' => 'Hello']);

        $this->assertFalse(is_callable([$object, 'toArray']));
        $this->assertFalse(is_callable([$object, 'toJSON']));

        $this->assertNotInstanceOf(SerializationInterface::class, $object);
        $this->assertInstanceOf(JSONModelInterface::class, $object);
    }

    public function testSerializationFunctionsAreGeneratedWithEnabledSerialization(): void
    {
        $className = '\\MyApp\\' . $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setSerialization(true)->setNamespacePrefix('MyApp'),
        );

        $object = new $className(['property' => 'Hello']);

        $this->assertSame(['property' => 'Hello'], $object->toArray());
        $this->assertSame(['property' => 'Hello'], $object->jsonSerialize());
        $this->assertSame('{"property":"Hello"}', $object->toJSON());

        $this->assertInstanceOf(SerializationInterface::class, $object);
        $this->assertInstanceOf(JSONModelInterface::class, $object);
        $this->assertInstanceOf(JsonSerializable::class, $object);
    }

    public function testNestedSerializationFunctions(): void
    {
        $className = '\\MyApp\\' . $this->generateClassFromFile(
            'NestedSchema.json',
            (new GeneratorConfiguration())->setSerialization(true)->setNamespacePrefix('MyApp'),
        );

        $input = [
            'name' => 'Hannes',
            'address' => [
                'street' => 'Test-Street',
                'number' => null
            ]
        ];

        $object = new $className($input);

        $this->assertEquals($input, $object->toArray());
        $this->assertEquals($input, $object->jsonSerialize());
        $this->assertSame('{"name":"Hannes","address":{"street":"Test-Street","number":null}}', $object->toJSON());

        $this->assertEquals(['name' => 'Hannes', 'address' => null], $object->toArray([], 1));
        $this->assertSame('{"name":"Hannes","address":null}', $object->toJSON([], 0, 1));

        $this->assertSame(['name' => 'Hannes'], $object->toArray(['address']));
        $this->assertSame('{"name":"Hannes"}', $object->toJSON(['address']));

        $this->assertFalse($object->toArray([], 0));
        $this->assertFalse($object->toJSON([], 0, 0));
    }

    public function testMultidimensionalArraySerialization(): void
    {
        $className = $this->generateClassFromFile(
            'MultidimensionalArray.json',
            (new GeneratorConfiguration())->setSerialization(true),
        );

        $structure = [
            'array' => [
                [
                    ['id' => 1],
                    ['id' => 2],
                ],
                [
                    ['id' => 3],
                    ['id' => 4],
                ],
            ],
        ];

        $object = new $className($structure);
        $this->assertSame($structure, $object->toArray());
        $this->assertSame('{"array":[[{"id":1},{"id":2}],[{"id":3},{"id":4}]]}', $object->toJSON());
    }

    public function testEmptyObjectSerialization(): void
    {
        $className = $this->generateClassFromFile(
            'EmptyObjectSerialization.json',
            (new GeneratorConfiguration())->setSerialization(true),
            false,
            false,
        );

        $object = new $className([]);
        $this->assertSame([], $object->toArray());
        $this->assertSame('{}', $object->toJSON());

        $object = new $className(['property' => [], 'array' => [[]], 'nested' => ['property' => []]]);
        $this->assertSame(['property' => [], 'array' => [[]], 'nested' => ['property' => []]], $object->toArray());
        $this->assertSame('{"property":{},"array":[{}],"nested":{"property":{}}}', $object->toJSON());
    }

    #[DataProvider('invalidStringPropertyValueProvider')]
    public function testInvalidSetterThrowsAnException(
        GeneratorConfiguration $configuration,
        string $propertyValue,
        array $exceptionMessage,
    ): void {
        $this->expectValidationError($configuration, $exceptionMessage);

        $className = $this->generateClassFromFile('BasicSchema.json', $configuration->setImmutable(false));

        $object = new $className([]);
        $object->setProperty($propertyValue);
    }

    public static function invalidStringPropertyValueProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'Too long string' => [
                    'HelloMyOldFriend',
                    [
                        "Value for 'property' must not be longer than 8"
                    ]
                ],
                'Invalid pattern' => [
                    '123456789',
                    [
                        'Value for \'property\' does not match pattern \'^[a-zA-Z]*$\''
                    ]
                ],
                'Too long and invalid pattern' => [
                    'HelloMyOld1234567',
                    [
                        'Value for \'property\' does not match pattern \'^[a-zA-Z]*$\'',
                        "Value for 'property' must not be longer than 8",
                    ]
                ]
            ],
        );
    }

    public function testPropertyNamesAreNormalized(): void
    {
        $className = $this->generateClassFromFile('NameNormalization.json');
        $object = new $className([
            'underscore_property' => '___',
            'minus-property' => '---',
            'space property' => '   ',
            'numeric42' => 13,
            '1000' => 1000,
            '1000string' => '1000',
            '0' => 0,
        ]);

        $this->assertSame('___', $object->getUnderscoreProperty());
        $this->assertSame('---', $object->getMinusProperty());
        $this->assertSame('   ', $object->getSpaceProperty());
        $this->assertSame(13, $object->getNumeric42());
        $this->assertSame(1000, $object->get1000());
        $this->assertSame('1000', $object->get1000string());
        $this->assertSame(0, $object->get0());
    }

    public function testEmptyNormalizedPropertyNameThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage("Name '__ -- __' results in an empty name");

        $this->generateClassFromFile('EmptyNameNormalization.json');
    }

    public function testNamespacePrefix(): void
    {
        $className = '\\My\\Prefix\\' . $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setNamespacePrefix('My\Prefix'),
        );

        $object = new $className([]);

        $this->assertNull($object->getProperty());
    }

    public function testFolderIsGeneratedRecursively(): void
    {
        $this->generateDirectory(
            'RecursiveTest',
            (new GeneratorConfiguration())->setNamespacePrefix('Application'),
        );

        $namespacePrefix = $this->lastGeneratedNamespacePrefix;
        $mainObject = new ("\\{$namespacePrefix}\\MainClass")(['property' => 'Hello']);

        $this->assertSame('Hello', $mainObject->getProperty());

        $subObject = new ("\\{$namespacePrefix}\\SubFolder\\SubClass")(['property' => 3]);

        $this->assertSame(3, $subObject->getProperty());
    }

    public function testInvalidJsonSchemaFileThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/^Invalid JSON-Schema file (.*)\.json at line 6, column 2$/');

        $this->generateClassFromFile('InvalidJSONSchema.json');
    }

    /**
     * Malformed JSON discovered via RecursiveDirectoryProvider (the default provider used by
     * generateClassFromFile) exposes its location through SchemaException's structured accessors,
     * not just through the message text.
     */
    public function testInvalidJsonSchemaFileExposesLocationThroughAccessors(): void
    {
        try {
            $this->generateClassFromFile('InvalidJSONSchema.json');
            $this->fail('Expected a SchemaException to be thrown');
        } catch (SchemaException $exception) {
            $this->assertStringEndsWith('.json', $exception->getSchemaFile());
            $this->assertSame(6, $exception->getSourceLine());
            $this->assertSame(2, $exception->getSourceColumn());
        }
    }

    public function testJsonSchemaWithInvalidPropertyTypeThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Unsupported property type UnknownType');

        $this->generateClassFromFile('JSONSchemaWithInvalidPropertyType.json');
    }

    public function testJsonSchemaWithInvalidPropertyTypeDefinitionThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Invalid property type');

        $this->generateClassFromFile('JSONSchemaWithInvalidPropertyTypeDefinition.json');
    }

    public function testDuplicateIdThrowsAnException(): void
    {
        $this->expectException(FileSystemException::class);
        $this->expectExceptionMessageMatches('/File (.*) already exists. Make sure object IDs are unique./');

        $this->generateClassFromFile('DuplicateId.json', null, true);
    }
}
