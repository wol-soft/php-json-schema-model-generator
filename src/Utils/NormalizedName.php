<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

class NormalizedName
{
    public static function from(string $name, JsonSchema $jsonSchema): string
    {
        // Word boundaries are inserted before an uppercase letter which follows a lowercase letter or a digit
        // (userId, http2Server) and before the last capital of a run of capitals if it starts a capitalised word
        // (SHSArtist => SHS-Artist). Lowercasing a run of capitals as a whole (SHSArtist => Shsartist) would
        // swallow the boundary of the following word.
        $attributeName = preg_replace(
            ['/(?<=[a-z0-9])(?=[A-Z])/', '/(?<=[A-Z])(?=[A-Z][a-z])/'],
            '-',
            $name,
        );

        $elements = array_map(
            static fn(string $element): string => ucfirst(strtolower($element)),
            preg_split('/[^a-z0-9]/i', (string) $attributeName),
        );

        $attributeName = join('', $elements);

        if ($attributeName === '') {
            throw new SchemaException(
                sprintf(
                    "Name '%s' results in an empty name in file %s",
                    $name,
                    $jsonSchema->getFile(),
                ),
                $jsonSchema,
            );
        }

        return $attributeName;
    }
}
