<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Draft\Modifier;

use PHPModelGenerator\Draft\Modifier\DefaultValueModifier;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\SchemaProcessor\RenderQueue;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\SchemaProvider\RecursiveDirectoryProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DefaultValueModifierTest extends TestCase
{
    private SchemaProcessor $schemaProcessor;
    private Schema $schema;

    protected function setUp(): void
    {
        $this->schemaProcessor = new SchemaProcessor(
            new RecursiveDirectoryProvider(__DIR__),
            '',
            new GeneratorConfiguration(),
            new RenderQueue(),
        );

        $this->schema = new Schema('', '', '', new JsonSchema('', []));
    }

    private function modifier(): DefaultValueModifier
    {
        return new DefaultValueModifier();
    }

    public function testSetsDefaultValueWhenPresent(): void
    {
        $jsonSchema = new JsonSchema('', ['type' => 'string', 'default' => 'hello']);
        $property = new Property('name', new PropertyType('string'), $jsonSchema);

        $this->modifier()->modify($this->schemaProcessor, $this->schema, $property, $jsonSchema);

        $this->assertSame("'hello'", $property->getDefaultValue());
    }

    public function testDoesNothingWhenNoDefaultPresent(): void
    {
        $jsonSchema = new JsonSchema('', ['type' => 'string']);
        $property = new Property('name', new PropertyType('string'), $jsonSchema);

        $this->modifier()->modify($this->schemaProcessor, $this->schema, $property, $jsonSchema);

        $this->assertNull($property->getDefaultValue());
    }

    public function testThrowsForInvalidDefaultType(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Invalid type for default value of property name');

        $jsonSchema = new JsonSchema('test.json', ['type' => 'string', 'default' => 42]);
        $property = new Property('name', new PropertyType('string'), $jsonSchema);

        $this->modifier()->modify($this->schemaProcessor, $this->schema, $property, $jsonSchema);
    }

    public function testCoercesIntegerDefaultToFloatForNumberType(): void
    {
        $jsonSchema = new JsonSchema('', ['type' => 'number', 'default' => 5]);
        $property = new Property('price', new PropertyType('float'), $jsonSchema);

        $this->modifier()->modify($this->schemaProcessor, $this->schema, $property, $jsonSchema);

        $this->assertSame('5.0', $property->getDefaultValue());
    }

    public function testThrowsForNonNumericDefaultOnNumberType(): void
    {
        $this->expectException(SchemaException::class);

        $jsonSchema = new JsonSchema('test.json', ['type' => 'number', 'default' => 'not-a-number']);
        $property = new Property('price', new PropertyType('float'), $jsonSchema);

        $this->modifier()->modify($this->schemaProcessor, $this->schema, $property, $jsonSchema);
    }

    public function testIntegerDefaultValue(): void
    {
        $jsonSchema = new JsonSchema('', ['type' => 'integer', 'default' => 42]);
        $property = new Property('count', new PropertyType('integer'), $jsonSchema);

        $this->modifier()->modify($this->schemaProcessor, $this->schema, $property, $jsonSchema);

        $this->assertSame('42', $property->getDefaultValue());
    }

    public function testBooleanDefaultValue(): void
    {
        $jsonSchema = new JsonSchema('', ['type' => 'boolean', 'default' => true]);
        $property = new Property('flag', new PropertyType('boolean'), $jsonSchema);

        $this->modifier()->modify($this->schemaProcessor, $this->schema, $property, $jsonSchema);

        $this->assertSame('true', $property->getDefaultValue());
    }

    public function testSetsDefaultValueWhenNoTypeInSchema(): void
    {
        $jsonSchema = new JsonSchema('', ['default' => 'anything']);
        $property = new Property('val', new PropertyType('string'), $jsonSchema);

        $this->modifier()->modify($this->schemaProcessor, $this->schema, $property, $jsonSchema);

        $this->assertSame("'anything'", $property->getDefaultValue());
    }

    public static function scalarCompositionBranchPointerProvider(): array
    {
        return [
            // allOf/anyOf/oneOf: scalar branch schema at the branch index level
            'oneOf branch'  => ['/oneOf/0'],
            'anyOf branch'  => ['/anyOf/1'],
            'allOf branch'  => ['/allOf/0'],
            // if/then/else: named branch keyword
            'then branch'   => ['/then'],
            'else branch'   => ['/else'],
            'if branch'     => ['/if'],
            // Composition nested under a root property (not a false positive)
            'oneOf inside root object property' => ['/properties/outer/oneOf/2'],
        ];
    }

    #[DataProvider('scalarCompositionBranchPointerProvider')]
    public function testScalarBranchDefaultEmitsWarningAndDropsDefault(string $pointer): void
    {
        $jsonSchema = new JsonSchema('test.json', ['type' => 'boolean', 'default' => true], $pointer);
        $property = new Property('flag', new PropertyType('boolean'), $jsonSchema);

        ob_start();
        $this->modifier()->modify($this->schemaProcessor, $this->schema, $property, $jsonSchema);
        $output = ob_get_clean();

        $this->assertSame(
            "Warning: property 'flag' declares a default value inside a composition branch"
                . " in file 'test.json'. Scalar branch defaults are unreachable and will be ignored.\n",
            $output,
        );
        $this->assertNull($property->getDefaultValue(), 'Default must not be applied for scalar branch schemas.');
    }

    public function testNamedPropertyInsideObjectBranchIsNotWarnedAbout(): void
    {
        // A named property inside an object-typed branch (pointer ends in /properties/<name>)
        // is handled by the per-branch runtime mechanism, not warned about here.
        $jsonSchema = new JsonSchema(
            'test.json',
            ['type' => 'boolean', 'default' => true],
            '/oneOf/1/properties/sandbox',
        );
        $property = new Property('sandbox', new PropertyType('boolean'), $jsonSchema);

        ob_start();
        $this->modifier()->modify($this->schemaProcessor, $this->schema, $property, $jsonSchema);
        $output = ob_get_clean();

        $this->assertSame('', $output, 'No warning for named properties inside object branches.');
        $this->assertSame('true', $property->getDefaultValue(), 'Default must apply for object-branch properties.');
    }

    public function testScalarBranchDefaultIsDroppedSilentlyWhenOutputDisabled(): void
    {
        $silentProcessor = new SchemaProcessor(
            new RecursiveDirectoryProvider(__DIR__),
            '',
            (new GeneratorConfiguration())->setOutputEnabled(false),
            new RenderQueue(),
        );

        $jsonSchema = new JsonSchema('test.json', ['type' => 'string', 'default' => 'hello'], '/oneOf/0');
        $property = new Property('name', new PropertyType('string'), $jsonSchema);

        ob_start();
        $this->modifier()->modify($silentProcessor, $this->schema, $property, $jsonSchema);
        $output = ob_get_clean();

        $this->assertSame('', $output, 'No output must be emitted when output is disabled.');
        $this->assertNull($property->getDefaultValue(), 'Default must still be dropped even when output is disabled.');
    }
}
