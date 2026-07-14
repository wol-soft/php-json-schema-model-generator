<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Utils\Json;

use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Utils\Json\JsonPointerLocator;
use PHPUnit\Framework\TestCase;

class JsonPointerLocatorTest extends TestCase
{
    public function testLocatesValueThroughNestedObjectPointer(): void
    {
        $json = <<<'JSON'
        {
          "properties": {
            "age": {
              "minimum": 5
            }
          }
        }
        JSON;

        $position = JsonPointerLocator::locate($json, '/properties/age/minimum');

        $this->assertSame(4, $position->line);
        $this->assertSame(18, $position->column);
    }

    public function testLocatesValueThroughArrayIndexPointer(): void
    {
        $position = JsonPointerLocator::locate('{"items": [10, 20, 30]}', '/items/2');

        $this->assertSame(1, $position->line);
        $this->assertSame(20, $position->column);
    }

    public function testEmptyPointerLocatesTheDocumentRoot(): void
    {
        $position = JsonPointerLocator::locate('  {"a": 1}', '');

        $this->assertSame(1, $position->line);
        $this->assertSame(3, $position->column);
    }

    public function testDuplicateObjectKeysResolveToTheLastOccurrence(): void
    {
        $json = <<<'JSON'
        {
          "a": 1,
          "a": 2
        }
        JSON;

        $position = JsonPointerLocator::locate($json, '/a');

        $this->assertSame(3, $position->line);
    }

    public function testPatternPropertiesKeyContainingTildeAndSlashResolvesViaPointerEscaping(): void
    {
        $regexKey = '^S~/';
        $json = '{"patternProperties": {"' . $regexKey . '": {"type": "string"}}}';
        $pointer = '/patternProperties/' . JsonSchema::encodePointer($regexKey);

        $position = JsonPointerLocator::locate($json, $pointer);

        $this->assertSame(strpos($json, '{"type"'), $position->offset);
    }

    public function testUnresolvedPointerReturnsNullInsteadOfThrowing(): void
    {
        $this->assertNull(JsonPointerLocator::locate('{"a": 1}', '/b'));
        $this->assertNull(JsonPointerLocator::locate('{"a": 1}', '/a/b'));
        $this->assertNull(JsonPointerLocator::locate('{"items": [1, 2]}', '/items/5'));
        $this->assertNull(JsonPointerLocator::locate('{"a": 1}', '/-'));
    }

    public function testUnicodeEscapedKeyMatchesTheDecodedPointerSegment(): void
    {
        $position = JsonPointerLocator::locate('{"caf\\u00e9": 1}', '/café');

        $this->assertSame(14, $position->offset);
        $this->assertSame(15, $position->column);
    }

    public function testCrlfLineEndingsAreCountedAsASingleLineBreak(): void
    {
        $json = "{\r\n  \"a\": {\r\n    \"b\": 1\r\n  }\r\n}";

        $position = JsonPointerLocator::locate($json, '/a/b');

        $this->assertSame(3, $position->line);
    }
}
