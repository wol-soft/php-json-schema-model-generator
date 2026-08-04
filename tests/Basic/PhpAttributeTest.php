<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Attributes\Deprecated;
use PHPModelGenerator\Attributes\JsonPointer;
use PHPModelGenerator\Attributes\JsonSchema;
use PHPModelGenerator\Attributes\ReadOnlyProperty;
use PHPModelGenerator\Attributes\Required;
use PHPModelGenerator\Attributes\SchemaName;
use PHPModelGenerator\Attributes\Source;
use PHPModelGenerator\Attributes\WriteOnlyProperty;
use PHPModelGenerator\Model\Attributes\PhpAttribute;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;

/**
 * Class PhpAttributeTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
#[ApplicableDrafts]
class PhpAttributeTest extends AbstractPHPModelGeneratorTestCase
{
    public function testDefaultAttributes(): void
    {
        $object = $this->generateClassFromFile('BasicSchema.json');

        $classAttributes = new ReflectionClass($object)->getAttributes();
        $this->assertCount(2, $classAttributes);
        $this->assertSame(JsonPointer::class, $classAttributes[0]->getName());
        $this->assertSame('', $classAttributes[0]->getArguments()[0]);
        $this->assertSame(Deprecated::class, $classAttributes[1]->getName());
        $this->assertEmpty($classAttributes[1]->getArguments());

        $propertyAttributes = new ReflectionClass($object)->getProperties()[0]->getAttributes();
        $this->assertCount(4, $propertyAttributes);
        $this->assertSame(JsonPointer::class, $propertyAttributes[0]->getName());
        $this->assertSame('/properties/my property', $propertyAttributes[0]->getArguments()[0]);
        $this->assertSame(SchemaName::class, $propertyAttributes[1]->getName());
        $this->assertSame('my property', $propertyAttributes[1]->getArguments()[0]);
        $this->assertSame(Required::class, $propertyAttributes[2]->getName());
        $this->assertEmpty($propertyAttributes[2]->getArguments());
        $this->assertSame(ReadOnlyProperty::class, $propertyAttributes[3]->getName());
        $this->assertEmpty($propertyAttributes[3]->getArguments());

        $propertyAttributes = new ReflectionClass($object)->getProperties()[1]->getAttributes();
        // pointer, schema name, deprecated
        $this->assertCount(3, $propertyAttributes);
        $this->assertSame(Deprecated::class, $propertyAttributes[2]->getName());
        $this->assertEmpty($propertyAttributes[2]->getArguments());

        $propertyAttributes = new ReflectionClass($object)->getProperties()[2]->getAttributes();
        // pointer, schema name, writeOnly
        $this->assertCount(3, $propertyAttributes);
        $this->assertSame(WriteOnlyProperty::class, $propertyAttributes[2]->getName());
        $this->assertEmpty($propertyAttributes[2]->getArguments());

        $propertyAttributes = new ReflectionClass($object)->getProperties()[3]->getAttributes();
        $this->assertCount(2, $propertyAttributes);
        $this->assertSame(JsonPointer::class, $propertyAttributes[0]->getName());
        $this->assertSame('/properties/123name', $propertyAttributes[0]->getArguments()[0]);
        $this->assertSame(SchemaName::class, $propertyAttributes[1]->getName());
        $this->assertSame('123name', $propertyAttributes[1]->getArguments()[0]);

        // Verify JSON Pointer RFC 6901 encoding: '/' encodes to '~1', '~' encodes to '~0'
        $instance = new $object(['my property' => 'Hello World']);
        $this->assertPropertyHasJsonPointer($instance, 'slashProperty', '/properties/slash~1property');
        $this->assertPropertyHasJsonPointer($instance, 'tildeProperty', '/properties/tilde~0property');
    }

    public function testBuiltinAttributes(): void
    {
        $configuration = (new GeneratorConfiguration())
            ->disableAttributes(
                PhpAttribute::SCHEMA_NAME
                    | PhpAttribute::READ_WRITE_ONLY
                    | PhpAttribute::REQUIRED,
            )
            ->enableAttributes(PhpAttribute::JSON_SCHEMA);

        $this->assertSame(
            PhpAttribute::JSON_SCHEMA
                | PhpAttribute::DEPRECATED
                | PhpAttribute::SCHEMA_NAME
                | PhpAttribute::JSON_POINTER,
            $configuration->getEnabledAttributes(),
        );

        $configuration->setEnabledAttributes(PhpAttribute::JSON_SCHEMA | PhpAttribute::SOURCE);

        $object = $this->generateClassFromFile('BasicSchema.json', $configuration);

        $classAttributes = new ReflectionClass($object)->getAttributes();
        $this->assertCount(3, $classAttributes);

        $this->assertSame(JsonPointer::class, $classAttributes[0]->getName());
        $this->assertSame('', $classAttributes[0]->getArguments()[0]);
        $this->assertSame(JsonSchema::class, $classAttributes[1]->getName());
        // json_encode escapes '/' as '\/' by default; verify the embedded schema contains all properties
        $jsonSchemaArg = $classAttributes[1]->getArguments()[0];
        $this->assertStringContainsString('"type":"object"', $jsonSchemaArg);
        $this->assertStringContainsString('"my property":{"type":"string"', $jsonSchemaArg);
        $this->assertStringContainsString('"tilde~property":{"type":"string"', $jsonSchemaArg);
        $this->assertMatchesRegularExpression('/"title":"PhpAttributeTest_\w+"/', $jsonSchemaArg);
        $this->assertSame(Source::class, $classAttributes[2]->getName());
        $this->assertStringEndsWith('.json', $classAttributes[2]->getArguments()[0]);

        $propertyAttributes = new ReflectionClass($object)->getProperties()[0]->getAttributes();
        $this->assertCount(3, $propertyAttributes);

        $this->assertSame(JsonPointer::class, $propertyAttributes[0]->getName());
        $this->assertSame('/properties/my property', $propertyAttributes[0]->getArguments()[0]);
        $this->assertSame(SchemaName::class, $propertyAttributes[1]->getName());
        $this->assertSame('my property', $propertyAttributes[1]->getArguments()[0]);
        $this->assertSame(JsonSchema::class, $propertyAttributes[2]->getName());
        $this->assertSame(
            '{"type":"string","deprecated":false,"readOnly":true}',
            $propertyAttributes[2]->getArguments()[0],
        );
    }

    /**
     * oneOf/allOf: shared property gets two JsonPointer attributes (one per branch);
     * branch-exclusive properties get exactly one pointer pointing to their defining branch.
     * Identical branch schemas are deduplicated in the synthesised JsonSchema attribute.
     */
    #[DataProvider('compositionKeywordProvider')]
    public function testCompositionPointersAndJsonSchema(string $keyword, string $schemaFile): void
    {
        $configuration = (new GeneratorConfiguration())
            ->setEnabledAttributes(PhpAttribute::JSON_POINTER | PhpAttribute::JSON_SCHEMA);

        $object = $this->generateClassFromFile($schemaFile, $configuration);
        $rc     = new ReflectionClass($object);

        // shared: two pointers (one per branch) + deduplicated synthesised schema (both branches
        // define {type:string}, so the array collapses to a single entry)
        $sharedPointers = $rc->getProperty('shared')->getAttributes(JsonPointer::class);
        $this->assertCount(2, $sharedPointers);
        $this->assertSame("/$keyword/0/properties/shared", $sharedPointers[0]->getArguments()[0]);
        $this->assertSame("/$keyword/1/properties/shared", $sharedPointers[1]->getArguments()[0]);

        $sharedSchemas = $rc->getProperty('shared')->getAttributes(JsonSchema::class);
        $this->assertCount(1, $sharedSchemas);
        $this->assertSame(
            "{\"$keyword\":[{\"type\":\"string\"}]}",
            $sharedSchemas[0]->getArguments()[0],
        );

        // branch0Only: one pointer to branch 0 only
        $this->assertPropertyHasExactPointers(
            $rc->getProperty('branch0Only'),
            ["/$keyword/0/properties/branch0Only"],
        );
        $branch0Schemas = $rc->getProperty('branch0Only')->getAttributes(JsonSchema::class);
        $this->assertCount(1, $branch0Schemas);
        $this->assertSame("{\"$keyword\":[{\"type\":\"integer\"}]}", $branch0Schemas[0]->getArguments()[0]);

        // branch1Only: one pointer to branch 1 only
        $this->assertPropertyHasExactPointers(
            $rc->getProperty('branch1Only'),
            ["/$keyword/1/properties/branch1Only"],
        );
        $branch1Schemas = $rc->getProperty('branch1Only')->getAttributes(JsonSchema::class);
        $this->assertCount(1, $branch1Schemas);
        $this->assertSame("{\"$keyword\":[{\"type\":\"integer\"}]}", $branch1Schemas[0]->getArguments()[0]);
    }

    public static function compositionKeywordProvider(): array
    {
        return [
            'oneOf' => ['oneOf', 'OneOfCompositionPointers.json'],
            'allOf' => ['allOf', 'AllOfCompositionPointers.json'],
        ];
    }

    /**
     * if/then/else: mode (if-only property) gets the /if/ pointer; shared property gets
     * then and else pointers; branch-exclusive properties get their respective branch pointer.
     */
    public function testIfThenElseCompositionPointersAndJsonSchema(): void
    {
        $configuration = (new GeneratorConfiguration())
            ->setEnabledAttributes(PhpAttribute::JSON_POINTER | PhpAttribute::JSON_SCHEMA);

        $object = $this->generateClassFromFile('IfThenElseCompositionPointers.json', $configuration);
        $rc     = new ReflectionClass($object);

        // mode: if-branch-only property → single pointer under /if/
        $this->assertPropertyHasExactPointers(
            $rc->getProperty('mode'),
            ['/if/properties/mode'],
        );
        $modeSchemas = $rc->getProperty('mode')->getAttributes(JsonSchema::class);
        $this->assertCount(1, $modeSchemas);
        $this->assertSame('{"if":{"type":"string","const":"active"}}', $modeSchemas[0]->getArguments()[0]);

        // shared: then + else pointers
        $sharedPointers = $rc->getProperty('shared')->getAttributes(JsonPointer::class);
        $this->assertCount(2, $sharedPointers);
        $this->assertSame('/then/properties/shared', $sharedPointers[0]->getArguments()[0]);
        $this->assertSame('/else/properties/shared', $sharedPointers[1]->getArguments()[0]);

        $sharedSchemas = $rc->getProperty('shared')->getAttributes(JsonSchema::class);
        $this->assertCount(1, $sharedSchemas);
        $this->assertSame(
            '{"then":{"type":"string"},"else":{"type":"string"}}',
            $sharedSchemas[0]->getArguments()[0],
        );

        // thenOnly: pointer under /then/ only
        $this->assertPropertyHasExactPointers(
            $rc->getProperty('thenOnly'),
            ['/then/properties/thenOnly'],
        );
        $thenSchemas = $rc->getProperty('thenOnly')->getAttributes(JsonSchema::class);
        $this->assertCount(1, $thenSchemas);
        $this->assertSame('{"then":{"type":"integer"}}', $thenSchemas[0]->getArguments()[0]);

        // elseOnly: pointer under /else/ only
        $this->assertPropertyHasExactPointers(
            $rc->getProperty('elseOnly'),
            ['/else/properties/elseOnly'],
        );
        $elseSchemas = $rc->getProperty('elseOnly')->getAttributes(JsonSchema::class);
        $this->assertCount(1, $elseSchemas);
        $this->assertSame('{"else":{"type":"integer"}}', $elseSchemas[0]->getArguments()[0]);
    }

    /**
     * Top-level property combined with oneOf: shared property gets three pointers — root
     * definition plus one per oneOf branch — and a synthesised schema merging root-level
     * constraints with the oneOf array.
     */
    public function testTopLevelPlusCompositionPointersAndJsonSchema(): void
    {
        $configuration = (new GeneratorConfiguration())
            ->setEnabledAttributes(PhpAttribute::JSON_POINTER | PhpAttribute::JSON_SCHEMA);

        $object = $this->generateClassFromFile('TopLevelPlusCompositionPointers.json', $configuration);
        $rc     = new ReflectionClass($object);

        // shared: root pointer + two branch pointers
        $sharedPointers = $rc->getProperty('shared')->getAttributes(JsonPointer::class);
        $this->assertCount(3, $sharedPointers);
        $this->assertSame('/properties/shared', $sharedPointers[0]->getArguments()[0]);
        $this->assertSame('/oneOf/0/properties/shared', $sharedPointers[1]->getArguments()[0]);
        $this->assertSame('/oneOf/1/properties/shared', $sharedPointers[2]->getArguments()[0]);

        $sharedSchemas = $rc->getProperty('shared')->getAttributes(JsonSchema::class);
        $this->assertCount(1, $sharedSchemas);
        $this->assertSame(
            '{"type":"string","minLength":1,"oneOf":[{"type":"string","maxLength":10},{"type":"string","maxLength":64}]}',
            $sharedSchemas[0]->getArguments()[0],
        );
    }

    /**
     * @param string[] $expectedPointers
     */
    private function assertPropertyHasExactPointers(ReflectionProperty $property, array $expectedPointers): void
    {
        $attributes = $property->getAttributes(JsonPointer::class);
        $this->assertCount(count($expectedPointers), $attributes);

        foreach ($expectedPointers as $index => $expectedPointer) {
            $this->assertSame($expectedPointer, $attributes[$index]->getArguments()[0]);
        }
    }
}
