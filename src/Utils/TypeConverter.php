<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

class TypeConverter
{
    public static function gettypeToInternal(string $type): string
    {
        return [
            'boolean' => 'bool',
            'integer' => 'int',
            'double' => 'float',
        ][$type] ?? $type;
    }
}
