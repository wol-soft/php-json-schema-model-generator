<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils\Json;

/**
 * A resolved position inside a raw JSON source string
 */
final class JsonSourcePosition
{
    public function __construct(
        public readonly int $line,
        public readonly int $column,
        public readonly int $offset,
    ) {
    }
}
