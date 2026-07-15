<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Exception;

use Exception;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPUnit\Framework\TestCase;

class SchemaExceptionTest extends TestCase
{
    public function testWithoutAJsonSchemaTheMessageIsUnchangedAndAllLocationAccessorsAreNull(): void
    {
        $exception = new SchemaException('Something went wrong');

        $this->assertSame('Something went wrong', $exception->getMessage());
        $this->assertNull($exception->getSchemaFile());
        $this->assertNull($exception->getSourceLine());
        $this->assertNull($exception->getSourceColumn());
    }

    public function testAJsonSchemaWithoutRawSourcePopulatesTheFileButNotTheLocation(): void
    {
        $jsonSchema = new JsonSchema('/path/to/schema.json', ['type' => 'object']);

        $exception = new SchemaException('Something went wrong', $jsonSchema);

        $this->assertSame('Something went wrong', $exception->getMessage());
        $this->assertSame('/path/to/schema.json', $exception->getSchemaFile());
        $this->assertNull($exception->getSourceLine());
        $this->assertNull($exception->getSourceColumn());
    }

    public function testAResolvablePointerAppendsTheLocationToTheMessageAndPopulatesTheAccessors(): void
    {
        $rawSource = <<<'JSON'
        {
          "properties": {
            "age": {
              "minimum": 5
            }
          }
        }
        JSON;

        $jsonSchema = new JsonSchema('/path/to/schema.json', [], '/properties/age/minimum', $rawSource);

        $exception = new SchemaException('Value too small', $jsonSchema);

        $this->assertSame('Value too small at line 4, column 18', $exception->getMessage());
        $this->assertSame('/path/to/schema.json', $exception->getSchemaFile());
        $this->assertSame(4, $exception->getSourceLine());
        $this->assertSame(18, $exception->getSourceColumn());
    }

    /**
     * A pointer that can't be located against the raw source (should not normally happen, since
     * pointers are derived from navigating the same decoded structure) must not crash or produce
     * a misleading location - it silently degrades to file-only reporting, the same as when no
     * raw source is available at all.
     */
    public function testAnUnresolvablePointerLeavesTheMessageUnchangedAndTheLocationNull(): void
    {
        $jsonSchema = (new JsonSchema('/path/to/schema.json', ['type' => 'object'], '', '{"type": "object"}'))
            ->withPointer('/does/not/exist');

        $exception = new SchemaException('Something went wrong', $jsonSchema);

        $this->assertSame('Something went wrong', $exception->getMessage());
        $this->assertSame('/path/to/schema.json', $exception->getSchemaFile());
        $this->assertNull($exception->getSourceLine());
        $this->assertNull($exception->getSourceColumn());
    }

    public function testInvalidJsonAppendsTheLocationWhenTheSyntaxErrorLocatorFindsOne(): void
    {
        $exception = SchemaException::invalidJson('/path/to/schema.json', '{"a": 1,}');

        $this->assertSame(
            'Invalid JSON-Schema file /path/to/schema.json at line 1, column 9',
            $exception->getMessage(),
        );
        $this->assertSame('/path/to/schema.json', $exception->getSchemaFile());
        $this->assertSame(1, $exception->getSourceLine());
        $this->assertSame(9, $exception->getSourceColumn());
    }

    /**
     * Malformed UTF-8 is rejected by json_decode() but the syntax locator's simplified grammar
     * walk can't pinpoint it (see JsonSyntaxErrorLocatorTest). invalidJson() must still report
     * the file, just without a line/column suffix, rather than crashing or fabricating a position.
     */
    public function testInvalidJsonLeavesTheMessageUnchangedWhenTheSyntaxErrorLocatorFindsNoPosition(): void
    {
        $exception = SchemaException::invalidJson('/path/to/schema.json', "{\"a\": \"bad\x80byte\"}");

        $this->assertSame('Invalid JSON-Schema file /path/to/schema.json', $exception->getMessage());
        $this->assertSame('/path/to/schema.json', $exception->getSchemaFile());
        $this->assertNull($exception->getSourceLine());
        $this->assertNull($exception->getSourceColumn());
    }

    /**
     * A generic wrapper exception (e.g. PropertyFactory's "Unresolved Reference ..." around a
     * caught exception) constructed without its own JsonSchema must not hide a more specific
     * location that a wrapped SchemaException already resolved.
     */
    public function testLocationAccessorsFallBackToAWrappedSchemaExceptionWhenThisExceptionHasNoneOfItsOwn(): void
    {
        $inner = SchemaException::invalidJson('/path/to/inner.json', '{"a": 1,}');

        $outer = new SchemaException('Unresolved Reference ../inner.json in file /path/to/outer.json', null, 0, $inner);

        $this->assertSame('/path/to/inner.json', $outer->getSchemaFile());
        $this->assertSame(1, $outer->getSourceLine());
        $this->assertSame(9, $outer->getSourceColumn());
    }

    /**
     * When the outer exception resolves its own location, that location wins - it must not be
     * overridden by whatever a wrapped previous exception happens to carry.
     */
    public function testLocationAccessorsPreferTheExceptionsOwnValuesOverAWrappedPrevious(): void
    {
        $inner = SchemaException::invalidJson('/path/to/inner.json', '{"a": 1,}');

        $outerJsonSchema = new JsonSchema('/path/to/outer.json', ['type' => 'object'], '', '{"type": "object"}');
        $outer = new SchemaException('Outer problem', $outerJsonSchema, 0, $inner);

        $this->assertSame('/path/to/outer.json', $outer->getSchemaFile());
    }

    public function testLocationAccessorsStayNullWhenTheWrappedPreviousIsNotASchemaException(): void
    {
        $inner = new Exception('some unrelated failure');

        $outer = new SchemaException('Unresolved Reference ../inner.json in file /path/to/outer.json', null, 0, $inner);

        $this->assertNull($outer->getSchemaFile());
        $this->assertNull($outer->getSourceLine());
        $this->assertNull($outer->getSourceColumn());
    }

    public function testCodeAndPreviousArePassedThroughToTheBaseException(): void
    {
        $previous = new Exception('cause');

        $exception = new SchemaException('wrapped', null, 42, $previous);

        $this->assertSame(42, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
