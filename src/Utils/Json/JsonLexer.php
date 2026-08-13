<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils\Json;

/**
 * A forward-only, line/column-tracking cursor over a raw JSON source string.
 *
 * The lexer never validates that the source is well-formed JSON on its own — callers decide
 * whether a failed token (null return) means "stop and report an error" (syntax checking) or
 * "this cannot happen because json_decode already accepted this text" (pointer resolution).
 */
class JsonLexer
{
    private int $offset;
    private int $line;
    private int $column;

    public function __construct(private readonly string $source, int $offset = 0, int $line = 1, int $column = 1)
    {
        $this->offset = $offset;
        $this->line = $line;
        $this->column = $column;
    }

    public function getPosition(): JsonSourcePosition
    {
        return new JsonSourcePosition($this->line, $this->column, $this->offset);
    }

    public function isEof(): bool
    {
        return $this->offset >= strlen($this->source);
    }

    public function peekChar(): ?string
    {
        return $this->isEof() ? null : $this->source[$this->offset];
    }

    public function skipWhitespace(): void
    {
        while (!$this->isEof() && in_array($this->source[$this->offset], [' ', "\t", "\n", "\r"], true)) {
            $this->advanceChar();
        }
    }

    public function matchChar(string $char): bool
    {
        if ($this->peekChar() !== $char) {
            return false;
        }

        $this->advanceChar();

        return true;
    }

    /**
     * Lexes a JSON string starting at the current (already verified) opening quote. Returns the
     * decoded string value, or null if the source doesn't contain a well-formed JSON string here
     * (unterminated, invalid escape, unescaped control character).
     */
    public function lexString(): ?string
    {
        if ($this->peekChar() !== '"') {
            return null;
        }

        $this->advanceChar();
        $result = '';

        while (true) {
            if ($this->isEof()) {
                return null;
            }

            $byte = ord($this->source[$this->offset]);

            if ($byte === 0x22) {
                $this->advanceChar();

                return $result;
            }

            if ($byte === 0x5C) {
                $escaped = $this->lexEscapeSequence();

                if ($escaped === null) {
                    return null;
                }

                $result .= $escaped;

                continue;
            }

            if ($byte < 0x20) {
                return null;
            }

            $start = $this->offset;
            $this->advanceChar();
            $result .= substr($this->source, $start, $this->offset - $start);
        }
    }

    /**
     * Matches the JSON number grammar at the current position. Returns the raw matched text, or
     * null if no valid number starts here.
     */
    public function lexNumber(): ?string
    {
        $pattern = '/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/';

        if (!preg_match($pattern, $this->source, $matches, 0, $this->offset) || $matches[0] === '') {
            return null;
        }

        $length = strlen($matches[0]);
        $this->offset += $length;
        $this->column += $length;

        return $matches[0];
    }

    /**
     * Matches one of the JSON literals (true, false, null) at the current position, requiring
     * that it isn't immediately followed by another identifier character. Returns the matched
     * literal, or null if none matches here.
     */
    public function lexLiteral(): ?string
    {
        foreach (['true', 'false', 'null'] as $literal) {
            $length = strlen($literal);

            if (substr($this->source, $this->offset, $length) !== $literal) {
                continue;
            }

            $nextByte = $this->offset + $length < strlen($this->source) ? $this->source[$this->offset + $length] : null;

            if ($nextByte !== null && (ctype_alnum($nextByte) || $nextByte === '_')) {
                continue;
            }

            $this->offset += $length;
            $this->column += $length;

            return $literal;
        }

        return null;
    }

    /**
     * Consumes and discards a full JSON value (string, number, literal, object or array) without
     * validating it, trusting that the source has already passed json_decode successfully.
     * Returns false if nothing recognizable as a JSON value starts here.
     */
    public function skipValue(): bool
    {
        $this->skipWhitespace();
        $char = $this->peekChar();

        if ($char === null) {
            return false;
        }

        if ($char === '"') {
            return $this->lexString() !== null;
        }

        if ($char === '{') {
            return $this->skipObject();
        }

        if ($char === '[') {
            return $this->skipArray();
        }

        if ($char === '-' || ($char >= '0' && $char <= '9')) {
            return $this->lexNumber() !== null;
        }

        return $this->lexLiteral() !== null;
    }

    private function skipObject(): bool
    {
        $this->matchChar('{');
        $this->skipWhitespace();

        if ($this->matchChar('}')) {
            return true;
        }

        while (true) {
            $this->skipWhitespace();

            if ($this->lexString() === null) {
                return false;
            }

            $this->skipWhitespace();

            if (!$this->matchChar(':')) {
                return false;
            }

            if (!$this->skipValue()) {
                return false;
            }

            $this->skipWhitespace();

            if ($this->matchChar(',')) {
                continue;
            }

            if ($this->matchChar('}')) {
                return true;
            }

            return false;
        }
    }

    private function skipArray(): bool
    {
        $this->matchChar('[');
        $this->skipWhitespace();

        if ($this->matchChar(']')) {
            return true;
        }

        while (true) {
            if (!$this->skipValue()) {
                return false;
            }

            $this->skipWhitespace();

            if ($this->matchChar(',')) {
                continue;
            }

            if ($this->matchChar(']')) {
                return true;
            }

            return false;
        }
    }

    private function lexEscapeSequence(): ?string
    {
        $this->advanceChar();

        if ($this->isEof()) {
            return null;
        }

        $escapeChar = $this->source[$this->offset];

        $simpleEscapes = [
            '"' => '"',
            '\\' => '\\',
            '/' => '/',
            'b' => "\x08",
            'f' => "\x0C",
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
        ];

        if (isset($simpleEscapes[$escapeChar])) {
            $this->advanceChar();

            return $simpleEscapes[$escapeChar];
        }

        if ($escapeChar !== 'u') {
            return null;
        }

        $this->advanceChar();
        $codepoint = $this->readHex4();

        if ($codepoint === null) {
            return null;
        }

        if ($codepoint >= 0xD800 && $codepoint <= 0xDBFF) {
            if (($this->source[$this->offset] ?? null) !== '\\' || ($this->source[$this->offset + 1] ?? null) !== 'u') {
                return null;
            }

            $this->advanceChar();
            $this->advanceChar();
            $lowSurrogate = $this->readHex4();

            if ($lowSurrogate === null || $lowSurrogate < 0xDC00 || $lowSurrogate > 0xDFFF) {
                return null;
            }

            $codepoint = 0x10000 + (($codepoint - 0xD800) << 10) + ($lowSurrogate - 0xDC00);
        }

        return $this->encodeCodepoint($codepoint);
    }

    private function readHex4(): ?int
    {
        if ($this->offset + 4 > strlen($this->source)) {
            return null;
        }

        $hex = substr($this->source, $this->offset, 4);

        if (!preg_match('/^[0-9a-fA-F]{4}$/', $hex)) {
            return null;
        }

        for ($index = 0; $index < 4; $index++) {
            $this->advanceChar();
        }

        return hexdec($hex);
    }

    private function encodeCodepoint(int $codepoint): string
    {
        if ($codepoint <= 0x7F) {
            return chr($codepoint);
        }

        if ($codepoint <= 0x7FF) {
            return chr(0xC0 | ($codepoint >> 6)) . chr(0x80 | ($codepoint & 0x3F));
        }

        if ($codepoint <= 0xFFFF) {
            return chr(0xE0 | ($codepoint >> 12))
                . chr(0x80 | (($codepoint >> 6) & 0x3F))
                . chr(0x80 | ($codepoint & 0x3F));
        }

        return chr(0xF0 | ($codepoint >> 18))
            . chr(0x80 | (($codepoint >> 12) & 0x3F))
            . chr(0x80 | (($codepoint >> 6) & 0x3F))
            . chr(0x80 | ($codepoint & 0x3F));
    }

    /**
     * Advances by exactly one logical character, tracking line/column. \r\n counts as a single
     * line break; column counts UTF-8 characters, not bytes.
     */
    private function advanceChar(): void
    {
        if ($this->isEof()) {
            return;
        }

        $byte = ord($this->source[$this->offset]);

        if ($byte === 0x0D) {
            $this->offset++;

            if (!$this->isEof() && $this->source[$this->offset] === "\n") {
                $this->offset++;
            }

            $this->line++;
            $this->column = 1;

            return;
        }

        if ($byte === 0x0A) {
            $this->offset++;
            $this->line++;
            $this->column = 1;

            return;
        }

        $this->offset += $this->utf8SequenceLength($byte);
        $this->column++;
    }

    private function utf8SequenceLength(int $leadingByte): int
    {
        if ($leadingByte < 0x80) {
            return 1;
        }

        if (($leadingByte & 0xE0) === 0xC0) {
            return 2;
        }

        if (($leadingByte & 0xF0) === 0xE0) {
            return 3;
        }

        if (($leadingByte & 0xF8) === 0xF0) {
            return 4;
        }

        return 1;
    }
}
