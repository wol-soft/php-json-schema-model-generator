<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Exception\MessageFormatter;

/**
 * Formats type names for the messages of the generator (schema errors and warnings), which address
 * the author of a schema: the types are named as JSON Schema names them ('integer', 'number',
 * 'boolean'), not as the PHP types the generator maps them to ('int', 'float', 'bool'). A single
 * type is quoted ('string'), several types are listed with every entry quoted
 * (['string', 'null']). Class names (e.g. the output of a filter) are kept as they are.
 *
 * The runtime exceptions keep their own token sets: the expected side of InvalidTypeException
 * names the generator's PHP types, the provided side the result of gettype().
 */
class TypeNameFormatter
{
    /**
     * Formats the value of a `type` keyword. The internal 'base' type, which replaces the `type` of
     * a schema which becomes a class, is shown as the 'object' it stands for.
     *
     * @param string|string[] $jsonType
     */
    public static function fromJsonTypes(string | array $jsonType): string
    {
        $typeNames = array_values(array_unique(array_map(
            static fn(string $typeName): string => $typeName === 'base' ? 'object' : $typeName,
            (array) $jsonType,
        )));

        return is_array($jsonType) && count($typeNames) > 1
            ? '[' . MessageFormatter::quotedList($typeNames) . ']'
            : "'$typeNames[0]'";
    }

    /**
     * Formats the PHP type names of a PropertyType, of a filter or of a type check.
     *
     * @param string[] $phpTypeNames
     */
    public static function fromPhpTypes(array $phpTypeNames): string
    {
        return self::fromJsonTypes(array_map(TypeConverter::phpToJsonSchema(...), array_values($phpTypeNames)));
    }
}
