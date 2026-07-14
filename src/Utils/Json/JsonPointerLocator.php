<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils\Json;

use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

/**
 * Resolves an RFC 6901 JSON pointer against raw JSON source text to the source position of the
 * value the pointer points at.
 *
 * The source text is assumed to already have passed json_decode successfully - this locator never
 * validates JSON syntax, it only walks an already-known-valid document. It must never throw: a
 * pointer that can't be resolved (should not normally happen, since pointers are derived from
 * navigating the very same decoded structure) simply yields null, so a failed lookup never masks
 * the real error being reported.
 */
class JsonPointerLocator
{
    public static function locate(string $source, string $pointer): ?JsonSourcePosition
    {
        $trimmedPointer = trim($pointer, '/');

        $lexer = new JsonLexer($source);
        $lexer->skipWhitespace();

        if ($trimmedPointer === '') {
            return $lexer->isEof() ? null : $lexer->getPosition();
        }

        foreach (explode('/', $trimmedPointer) as $segment) {
            $decodedSegment = JsonSchema::decodePointer($segment);
            $match = self::findSegment($lexer, $decodedSegment);

            if ($match === null) {
                return null;
            }

            $lexer = new JsonLexer($source, $match->offset, $match->line, $match->column);
        }

        return $lexer->getPosition();
    }

    private static function findSegment(JsonLexer $lexer, string $segment): ?JsonSourcePosition
    {
        $lexer->skipWhitespace();

        return match ($lexer->peekChar()) {
            '{' => self::findObjectKey($lexer, $segment),
            '[' => self::findArrayIndex($lexer, $segment),
            default => null,
        };
    }

    /**
     * Scans every key of the object at the current position, keeping the last occurrence that
     * matches $segment - matching json_decode's own last-write-wins duplicate-key behavior, so
     * the reported position always belongs to the value actually present in the decoded tree.
     */
    private static function findObjectKey(JsonLexer $lexer, string $segment): ?JsonSourcePosition
    {
        if (!$lexer->matchChar('{')) {
            return null;
        }

        $lexer->skipWhitespace();

        if ($lexer->matchChar('}')) {
            return null;
        }

        $match = null;

        while (true) {
            $lexer->skipWhitespace();
            $key = $lexer->lexString();

            if ($key === null) {
                return null;
            }

            $lexer->skipWhitespace();

            if (!$lexer->matchChar(':')) {
                return null;
            }

            $lexer->skipWhitespace();
            $valuePosition = $lexer->getPosition();

            if ($key === $segment) {
                $match = $valuePosition;
            }

            if (!$lexer->skipValue()) {
                return null;
            }

            $lexer->skipWhitespace();

            if ($lexer->matchChar(',')) {
                continue;
            }

            if ($lexer->matchChar('}')) {
                break;
            }

            return null;
        }

        return $match;
    }

    private static function findArrayIndex(JsonLexer $lexer, string $segment): ?JsonSourcePosition
    {
        if (!preg_match('/^(?:0|[1-9][0-9]*)$/', $segment)) {
            return null;
        }

        $targetIndex = (int) $segment;

        if (!$lexer->matchChar('[')) {
            return null;
        }

        $lexer->skipWhitespace();

        if ($lexer->matchChar(']')) {
            return null;
        }

        $currentIndex = 0;
        $match = null;

        while (true) {
            $lexer->skipWhitespace();
            $valuePosition = $lexer->getPosition();

            if ($currentIndex === $targetIndex) {
                $match = $valuePosition;
            }

            if (!$lexer->skipValue()) {
                return null;
            }

            $currentIndex++;
            $lexer->skipWhitespace();

            if ($lexer->matchChar(',')) {
                continue;
            }

            if ($lexer->matchChar(']')) {
                break;
            }

            return null;
        }

        return $match;
    }
}
