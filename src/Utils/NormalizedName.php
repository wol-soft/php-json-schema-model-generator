<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

class NormalizedName
{
    public static function from(string $name, JsonSchema $jsonSchema): string
    {
        $attributeName = preg_replace_callback(
            '/([a-z][a-z0-9]*)([A-Z])/',
            static function (array $matches): string {
                return "{$matches[1]}-{$matches[2]}";
            },
            $name,
        );

        $elements = array_map(
            static function (string $element): string {
                return ucfirst(strtolower($element));
            },
            preg_split('/[^a-z0-9]/i', $attributeName),
        );

        $attributeName = join('', $elements);

        if (empty($attributeName)) {
            throw new SchemaException(
                sprintf(
                    "Name '%s' results in an empty name in file %s",
                    $name,
                    $jsonSchema->getFile(),
                )
            );
        }

        return $attributeName;
    }
}
