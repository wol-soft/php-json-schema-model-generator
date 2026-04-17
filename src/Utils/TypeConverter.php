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
            'NULL' => 'null',
        ][$type] ?? $type;
    }

    public static function jsonSchemaToPHP(string $type): string
    {
        return [
            'integer' => 'int',
            'number'  => 'float',
            'boolean' => 'bool',
        ][$type] ?? $type;
    }
}
