<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Utils\Json;

use PHPModelGenerator\Utils\Json\JsonSyntaxErrorLocator;
use PHPUnit\Framework\TestCase;

class JsonSyntaxErrorLocatorTest extends TestCase
{
    public function testEmptyFileIsReportedAtTheDocumentStart(): void
    {
        $position = JsonSyntaxErrorLocator::locate('');

        $this->assertSame(1, $position->line);
        $this->assertSame(1, $position->column);
        $this->assertSame(0, $position->offset);
    }

    public function testTrailingCommaIsReportedAtTheUnexpectedClosingBrace(): void
    {
        $position = JsonSyntaxErrorLocator::locate('{"a": 1,}');

        $this->assertSame(1, $position->line);
        $this->assertSame(9, $position->column);
        $this->assertSame(8, $position->offset);
    }

    public function testUnterminatedStringIsReportedAtTheOffendingRawNewline(): void
    {
        $position = JsonSyntaxErrorLocator::locate("{\n  \"a\": \"unterminated\n}");

        $this->assertSame(2, $position->line);
        $this->assertSame(21, $position->column);
        $this->assertSame(22, $position->offset);
    }

    public function testTruncatedInputIsReportedAtTheEndOfFile(): void
    {
        $position = JsonSyntaxErrorLocator::locate('{"a": 1');

        $this->assertSame(1, $position->line);
        $this->assertSame(8, $position->column);
        $this->assertSame(7, $position->offset);
    }

    public function testUnexpectedTokenIsReportedAtItsStart(): void
    {
        $position = JsonSyntaxErrorLocator::locate('{"a": tru}');

        $this->assertSame(1, $position->line);
        $this->assertSame(7, $position->column);
        $this->assertSame(6, $position->offset);
    }

    public function testTrailingGarbageAfterAValidDocumentIsReportedAtItsStart(): void
    {
        $position = JsonSyntaxErrorLocator::locate('{"a": 1} extra');

        $this->assertSame(1, $position->line);
        $this->assertSame(10, $position->column);
        $this->assertSame(9, $position->offset);
    }

    public function testLeadingZeroLeavesTheExtraDigitAsAnUnexpectedToken(): void
    {
        $position = JsonSyntaxErrorLocator::locate('{"a": 01}');

        $this->assertSame(1, $position->line);
        $this->assertSame(8, $position->column);
        $this->assertSame(7, $position->offset);
    }

    public function testUnexpectedCharacterInsideANestedArrayIsReportedAtThatDepth(): void
    {
        $position = JsonSyntaxErrorLocator::locate('{"items": [1, 2, ,]}');

        $this->assertSame(1, $position->line);
        $this->assertSame(18, $position->column);
        $this->assertSame(17, $position->offset);
    }

    public function testMissingColonAfterKeyIsReportedAtTheUnexpectedToken(): void
    {
        $position = JsonSyntaxErrorLocator::locate('{"a" 1}');

        $this->assertSame(1, $position->line);
        $this->assertSame(6, $position->column);
        $this->assertSame(5, $position->offset);
    }

    /**
     * The lexer's grammar walk treats any byte sequence starting with 0x80-0xF7 as a plausible
     * multi-byte UTF-8 character without validating the continuation bytes - it does not perform
     * the strict UTF-8 validation json_decode() does. A lone/invalid continuation byte therefore
     * "parses" cleanly under this scanner even though json_decode() rejects the same input with
     * JSON_ERROR_UTF8. The locator must return null (not a wrong/misleading position) so callers
     * fall back to file-only reporting rather than presenting a confusing location.
     */
    public function testMalformedUtf8ThatOnlyJsonDecodeRejectsReturnsNullInsteadOfAWrongPosition(): void
    {
        $json = "{\"a\": \"bad\x80byte\"}";

        $this->assertNull(json_decode($json, true));
        $this->assertSame(JSON_ERROR_UTF8, json_last_error());

        $this->assertNull(JsonSyntaxErrorLocator::locate($json));
    }
}
