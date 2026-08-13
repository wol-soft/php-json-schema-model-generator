<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils\Json;

/**
 * Runs a minimal recursive-descent grammar walk over raw JSON source text that is already known
 * to have failed json_decode, to pinpoint the first position where the input stops being valid
 * JSON (unexpected character, unterminated string, trailing comma, unexpected end of input, ...).
 *
 * Returns null if the scanner can't pinpoint a position either, so callers can gracefully fall
 * back to file-only error reporting instead of raising a confusing meta-error.
 */
class JsonSyntaxErrorLocator
{
    private ?JsonSourcePosition $errorPosition = null;

    public static function locate(string $source): ?JsonSourcePosition
    {
        return (new self())->run($source);
    }

    private function run(string $source): ?JsonSourcePosition
    {
        $lexer = new JsonLexer($source);

        if (!$this->parseValue($lexer)) {
            return $this->errorPosition;
        }

        $lexer->skipWhitespace();

        if (!$lexer->isEof()) {
            $this->errorPosition ??= $lexer->getPosition();
        }

        return $this->errorPosition;
    }

    private function fail(JsonLexer $lexer): bool
    {
        $this->errorPosition ??= $lexer->getPosition();

        return false;
    }

    private function parseValue(JsonLexer $lexer): bool
    {
        $lexer->skipWhitespace();
        $char = $lexer->peekChar();

        return match (true) {
            $char === '"' => $lexer->lexString() !== null ? true : $this->fail($lexer),
            $char === '{' => $this->parseObject($lexer),
            $char === '[' => $this->parseArray($lexer),
            $char === '-' || ($char !== null && $char >= '0' && $char <= '9')
                => $lexer->lexNumber() !== null ? true : $this->fail($lexer),
            $char === 't' || $char === 'f' || $char === 'n'
                => $lexer->lexLiteral() !== null ? true : $this->fail($lexer),
            default => $this->fail($lexer),
        };
    }

    private function parseObject(JsonLexer $lexer): bool
    {
        $lexer->matchChar('{');
        $lexer->skipWhitespace();

        if ($lexer->matchChar('}')) {
            return true;
        }

        while (true) {
            $lexer->skipWhitespace();

            if ($lexer->peekChar() !== '"') {
                return $this->fail($lexer);
            }

            if ($lexer->lexString() === null) {
                return $this->fail($lexer);
            }

            $lexer->skipWhitespace();

            if (!$lexer->matchChar(':')) {
                return $this->fail($lexer);
            }

            if (!$this->parseValue($lexer)) {
                return false;
            }

            $lexer->skipWhitespace();

            if ($lexer->matchChar(',')) {
                continue;
            }

            if ($lexer->matchChar('}')) {
                return true;
            }

            return $this->fail($lexer);
        }
    }

    private function parseArray(JsonLexer $lexer): bool
    {
        $lexer->matchChar('[');
        $lexer->skipWhitespace();

        if ($lexer->matchChar(']')) {
            return true;
        }

        while (true) {
            if (!$this->parseValue($lexer)) {
                return false;
            }

            $lexer->skipWhitespace();

            if ($lexer->matchChar(',')) {
                continue;
            }

            if ($lexer->matchChar(']')) {
                return true;
            }

            return $this->fail($lexer);
        }
    }
}
