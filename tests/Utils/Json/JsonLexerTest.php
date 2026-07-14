<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Utils\Json;

use PHPModelGenerator\Utils\Json\JsonLexer;
use PHPUnit\Framework\TestCase;

class JsonLexerTest extends TestCase
{
    public function testSkipWhitespaceAdvancesPastSpacesTabsAndNewlines(): void
    {
        $lexer = new JsonLexer(" \t\n\r \"x\"");
        $lexer->skipWhitespace();

        $this->assertSame('"', $lexer->peekChar());
    }

    public function testLexStringDecodesSimpleEscapeSequences(): void
    {
        $lexer = new JsonLexer('"a\\"b\\\\c\\/d\\be\\ff\\ng\\rh\\ti"');

        $this->assertSame("a\"b\\c/d\x08e\x0Cf\ng\rh\ti", $lexer->lexString());
    }

    public function testLexStringDecodesUnicodeEscape(): void
    {
        $lexer = new JsonLexer('"caf\\u00e9"');

        $this->assertSame('café', $lexer->lexString());
    }

    public function testLexStringDecodesSurrogatePairEscape(): void
    {
        $lexer = new JsonLexer('"\\ud83d\\ude00"');

        $this->assertSame("\u{1F600}", $lexer->lexString());
    }

    public function testLexStringReturnsNullForUnterminatedString(): void
    {
        $lexer = new JsonLexer('"unterminated');

        $this->assertNull($lexer->lexString());
    }

    public function testLexStringReturnsNullForUnescapedControlCharacter(): void
    {
        $lexer = new JsonLexer("\"broken\nstring\"");

        $this->assertNull($lexer->lexString());
    }

    public function testLexStringReturnsNullForInvalidEscapeSequence(): void
    {
        $lexer = new JsonLexer('"bad\\qescape"');

        $this->assertNull($lexer->lexString());
    }

    public function testLexStringReturnsNullForIncompleteUnicodeEscape(): void
    {
        $lexer = new JsonLexer('"bad\\u12"');

        $this->assertNull($lexer->lexString());
    }

    public function testLexStringReturnsNullForUnpairedHighSurrogate(): void
    {
        $lexer = new JsonLexer('"\\ud83donly"');

        $this->assertNull($lexer->lexString());
    }

    public function testLexNumberMatchesIntegerFloatAndExponentForms(): void
    {
        $this->assertSame('42', (new JsonLexer('42'))->lexNumber());
        $this->assertSame('-42', (new JsonLexer('-42'))->lexNumber());
        $this->assertSame('3.14', (new JsonLexer('3.14'))->lexNumber());
        $this->assertSame('1.5e10', (new JsonLexer('1.5e10'))->lexNumber());
        $this->assertSame('1E-10', (new JsonLexer('1E-10'))->lexNumber());
    }

    public function testLexNumberStopsAtLeadingZeroBoundary(): void
    {
        $lexer = new JsonLexer('01');

        // "0" is the longest valid JSON number at this position; the leading-zero rule means
        // the following "1" is not part of it and is left for the caller to reject.
        $this->assertSame('0', $lexer->lexNumber());
        $this->assertSame('1', $lexer->peekChar());
    }

    public function testLexNumberReturnsNullWhenNoNumberStartsHere(): void
    {
        $lexer = new JsonLexer('"not a number"');

        $this->assertNull($lexer->lexNumber());
    }

    public function testLexLiteralMatchesTrueFalseAndNull(): void
    {
        $this->assertSame('true', (new JsonLexer('true'))->lexLiteral());
        $this->assertSame('false', (new JsonLexer('false'))->lexLiteral());
        $this->assertSame('null', (new JsonLexer('null'))->lexLiteral());
    }

    public function testLexLiteralRejectsPartialMatchFollowedByIdentifierChar(): void
    {
        $this->assertNull((new JsonLexer('truex'))->lexLiteral());
        $this->assertNull((new JsonLexer('nullable'))->lexLiteral());
    }

    public function testMatchCharConsumesOnlyOnExactMatch(): void
    {
        $lexer = new JsonLexer('{}');

        $this->assertFalse($lexer->matchChar('['));
        $this->assertTrue($lexer->matchChar('{'));
        $this->assertSame('}', $lexer->peekChar());
    }

    public function testSkipValueConsumesNestedObjectsAndArraysWithoutValidating(): void
    {
        $lexer = new JsonLexer('{"a": [1, 2, {"b": "c"}], "d": null}, "next"');

        $this->assertTrue($lexer->skipValue());
        $this->assertSame(',', $lexer->peekChar());
    }

    public function testCrlfLineBreakIsCountedAsASingleLine(): void
    {
        $lexer = new JsonLexer("\"a\"\r\n\"b\"");

        $lexer->lexString();
        $lexer->skipWhitespace();
        $position = $lexer->getPosition();

        $this->assertSame(2, $position->line);
        $this->assertSame(1, $position->column);
    }

    public function testColumnCountsUtf8CharactersNotBytes(): void
    {
        $lexer = new JsonLexer('"café"x');

        $lexer->lexString();
        $position = $lexer->getPosition();

        // the 4-char string "café" (é is a 2-byte UTF-8 sequence) plus 2 quotes = column 7
        $this->assertSame(7, $position->column);
        $this->assertSame(7, $position->offset);
    }

    public function testTabCountsAsASingleColumn(): void
    {
        $lexer = new JsonLexer("\t\"x\"");
        $lexer->skipWhitespace();

        $this->assertSame(2, $lexer->getPosition()->column);
    }
}
