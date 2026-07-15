<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\SchemaDefinition;

use PHPModelGenerator\Exception\GeneratorException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Utils\ArrayHash;

class JsonSchema
{
    private const array SCHEMA_SIGNATURE_RELEVANT_FIELDS = [
        'type',
        'properties',
        '$ref',
        'allOf',
        'anyOf',
        'oneOf',
        'not',
        'if',
        'then',
        'else',
        'additionalProperties',
        'required',
        'propertyNames',
        'minProperties',
        'maxProperties',
        'dependencies',
        'patternProperties',
    ];

    protected array $json;

    /**
     * JsonSchema constructor.
     *
     * @param string $file the source file for the schema
     * @param array $json Decoded json schema
     * @param string $pointer The JSON pointer inside the $file leading to the schema provided in $json
     * @param string|null $rawSource The raw, undecoded text of $file, if the provider retained it. Populating this
     *                               enables SchemaException to report the line/column a validation error occurred
     *                               at; providers that don't have the raw text on hand may omit it.
     */
    public function __construct(
        private string $file,
        array $json,
        private string $pointer = '',
        private ?string $rawSource = null,
    ) {
        // wrap in an allOf to pass the processing to multiple handlers - ugly hack to be removed after rework
        if (
            isset($json['$ref']) &&
            count(array_diff(
                array_intersect(array_keys($json), self::SCHEMA_SIGNATURE_RELEVANT_FIELDS),
                ['$ref', 'type'],
            ))
        ) {
            $json = array_merge(
                array_diff_key($json, array_fill_keys(self::SCHEMA_SIGNATURE_RELEVANT_FIELDS, null)),
                [
                    'allOf' => [
                        ['$ref' => $json['$ref']],
                        array_intersect_key(
                            $json,
                            array_fill_keys(array_diff(self::SCHEMA_SIGNATURE_RELEVANT_FIELDS, ['$ref']), null),
                        ),
                    ],
                ],
            );
        }

        $this->json = $json;
    }

    public function getJson(): array
    {
        return $this->json;
    }

    /**
     * create the signature from all fields which are directly relevant for the created object. Additional fields
     * can be ignored as the resulting code will be identical
     */
    public function getSignature(): string
    {
        return ArrayHash::hash($this->json, self::SCHEMA_SIGNATURE_RELEVANT_FIELDS);
    }

    public function withJson(array $json): JsonSchema
    {
        $jsonSchema = clone $this;
        $jsonSchema->json = $json;

        return $jsonSchema;
    }

    /**
     * Creates a clone of this JsonSchema with a different pointer, without navigating the JSON content.
     * Use when the target pointer path cannot be traversed via navigate() (e.g. the schema value is `true`).
     */
    public function withPointer(string $pointer): JsonSchema
    {
        $jsonSchema = clone $this;
        $jsonSchema->pointer = $pointer;

        return $jsonSchema;
    }

    /**
     * Creates a clone of the JsonSchema object with a subschema,
     * navigated to the provided $pointer from the current schema.
     */
    public function navigate(string | int $pointer): JsonSchema
    {
        $trimmed = trim((string) $pointer, '/');

        if ($trimmed === '') {
            return $this;
        }

        $jsonSchema = clone $this;

        foreach (explode('/', $trimmed) as $pathSegment) {
            $jsonSchema->pointer .= "/$pathSegment";
            $decodedPathSegment = self::decodePointer($pathSegment);

            if (!array_key_exists($decodedPathSegment, $jsonSchema->json)) {
                throw new SchemaException("Unresolved path segment $pathSegment in file $this->file", $jsonSchema);
            }

            $jsonSchema->json = $jsonSchema->json[$decodedPathSegment];
        }

        return $jsonSchema;
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function getRawSource(): ?string
    {
        return $this->rawSource;
    }

    public function getPointer(): string
    {
        return $this->pointer;
    }

    public static function encodePointer(string | int $pointer): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], (string) $pointer);
    }

    public static function decodePointer(string | int $pointer): string
    {
        return str_replace(['~1', '~0'], ['/', '~'], (string) $pointer);
    }
}
