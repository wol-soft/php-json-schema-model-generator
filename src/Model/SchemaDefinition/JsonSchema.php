<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\SchemaDefinition;

use LogicException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Utils\ArrayHash;
use PHPModelGenerator\Utils\JsonSchema as JsonSchemaUtil;
use PHPModelGenerator\Utils\SchemaSignatureNormalizer;

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
     * Set when the JsonSchema is a boolean schema (`true` accepts every value, `false` accepts none), which navigate()
     * returns for a boolean-valued target such as a definition `"yes": true`. A boolean is no decoded JSON object, so
     * $json stays empty for such a schema and getJson() refuses to hand it out: an empty array would turn `false` into
     * the schema which accepts everything.
     */
    private ?bool $booleanValue = null;

    /**
     * Set when an enclosing schema already asserts that the value of this schema is an object: the branches of a
     * composition of an object validate that very value, the schema of a dependency is only ever applied to the object
     * which holds the property, and the target of a class-defining reference is the object of the class. Nothing is
     * implied by a missing `type` there, so an object-describing schema doesn't need a warning.
     *
     * It is carried by the schema instead of being derived from the JSON: the type which the generator hands to a
     * branch is lost next to a `$ref` in the drafts which ignore the siblings of a reference, and the definition behind
     * a reference is built without knowing the site which references it. A value below the schema (a property, an
     * item, ...) is no such site, navigate() resets the marker.
     */
    private bool $objectAssertedByContext = false;

    /**
     * The nearest enclosing $id, normalized to always start with '#' (matching
     * SchemaDefinitionDictionary's dictionary key format). Defaults to the document root ('#')
     * and is updated by navigate() whenever it descends into a node carrying its own $id. Used to
     * resolve an empty/fragment-only $ref (RFC 3986 §5.2.2: "" and "#" both resolve to the
     * current base) against the correct scope instead of always the top-level document.
     */
    private string $baseId = '#';

    /**
     * The $schema URI in effect for this node: the current node's own $schema if it declares one,
     * otherwise inherited from the nearest ancestor that did. Per the JSON Schema core spec,
     * $schema SHOULD appear on the document root and MAY additionally appear on the root schema
     * object of an embedded schema resource (a subschema with its own $id, e.g. a $defs entry) to
     * declare a different dialect for that resource; it MUST NOT appear elsewhere. $schema is
     * therefore absent from most nodes, so without inheritance draft detection (see
     * AutoDetectionDraft) would only ever work for literal document-root-level properties.
     * navigate()/withJson() below both clone $this (which copies this property) and then
     * re-derive it via deriveSchemaUri(), so a local override on an embedded resource wins there
     * and continues to propagate to ITS descendants, while every other node keeps inheriting the
     * ancestor value undisturbed.
     */
    private ?string $schemaUri = null;

    /**
     * @param string $file the source file for the schema
     * @param array $json Decoded json schema
     * @param string $pointer The JSON pointer inside the $file leading to the schema provided in $json
     * @param string|null $rawSource The raw, undecoded text of $file, if the provider retained it. Populating this
     *                               enables SchemaException to report the line/column a validation error occurred
     *                               at; providers that don't have the raw text on hand may omit it.
     *
     * @throws SchemaException
     */
    public function __construct(
        private string $file,
        array $json,
        private string $pointer = '',
        private ?string $rawSource = null,
    ) {
        $json = $this->normalizeTypeDeclaration($json);

        $this->schemaUri = $this->deriveSchemaUri($json);
        $this->json = $json;

        $declaredId = self::extractId($json);

        if ($declaredId !== null) {
            $this->baseId = self::normalizeId($declaredId);
        }
    }

    /**
     * A `type` array is normalized to the equivalent form the rest of the pipeline understands:
     * duplicate entries are dropped and a list that then holds exactly one entry collapses to that
     * entry's scalar form, because `{"type": ["object"]}` and `{"type": "object"}` are the same
     * schema per JSON Schema. Doing this once, here, is what keeps the routing
     * (PropertyFactory::create() dispatching an array-valued type to the multi-type path),
     * the gate (SchemaProcessor::processSchema() comparing `type` against the string 'object') and
     * the classifier (ObjectShapeResolver, which normalizes on its own) agreeing by construction:
     * previously the classifier accepted `["object"]` as a definite object while the routing and
     * the gate refused it, producing either a misattributed "No nested schema for composed
     * property" error or a silently skipped file.
     *
     * Only this node's own `type` is normalized, not the whole subtree. Every schema node the
     * pipeline works with is wrapped in its own JsonSchema (via navigate() or withJson()) and is
     * therefore covered, whereas a recursive walk would have to know which sub-nodes are schemas at
     * all: rewriting every nested `type` blindly would corrupt schema-shaped data inside a
     * `default`, `const`, `enum` or `examples` payload, and descending only through applicator
     * keywords would mean hardcoding a draft's keyword set in a class that cannot consult the Draft
     * (draft detection reads `$schema` off a JsonSchema, so the dependency runs the other way).
     *
     * The same reasoning bounds the one case where this could misfire: navigating into a non-schema
     * node that happens to carry a `type` key of its own (a `default` value, say). No caller does
     * that today - navigate() is used for `$ref` targets, definitions and property subschemas, all
     * of which are schemas.
     *
     * @throws SchemaException
     */
    private function normalizeTypeDeclaration(array $json): array
    {
        if (!array_key_exists('type', $json) || !is_array($json['type'])) {
            return $json;
        }

        // SORT_REGULAR rather than the default SORT_STRING: a malformed schema may list a non-scalar
        // entry, which SORT_STRING would raise an "Array to string conversion" warning for before
        // the pipeline's own type check gets to reject it with a message naming the actual defect.
        $declaredTypes = array_values(array_unique($json['type'], SORT_REGULAR));

        if ($declaredTypes === []) {
            throw new SchemaException(
                sprintf("Empty 'type' array in file '%s'", $this->file),
                $this,
            );
        }

        $json['type'] = count($declaredTypes) === 1 ? $declaredTypes[0] : $declaredTypes;

        return $json;
    }

    /**
     * @throws LogicException for a boolean schema, see isBooleanSchema()
     */
    public function getJson(): array
    {
        if ($this->booleanValue !== null) {
            throw new LogicException(
                sprintf(
                    "The boolean schema at '%s' in file '%s' has no JSON object, check isBooleanSchema() before"
                        . ' reading it',
                    $this->pointer,
                    $this->file,
                ),
            );
        }

        return $this->json;
    }

    public function isBooleanSchema(): bool
    {
        return $this->booleanValue !== null;
    }

    /**
     * @throws LogicException if the schema is no boolean schema
     */
    public function getBooleanValue(): bool
    {
        return $this->booleanValue
            ?? throw new LogicException(
                sprintf("The schema at '%s' in file '%s' is not a boolean schema", $this->pointer, $this->file),
            );
    }

    public function isObjectAssertedByContext(): bool
    {
        return $this->objectAssertedByContext;
    }

    public function withObjectAssertedByContext(bool $objectAsserted = true): JsonSchema
    {
        $jsonSchema = clone $this;
        $jsonSchema->objectAssertedByContext = $objectAsserted;

        return $jsonSchema;
    }

    /**
     * Whether every value this schema is applied to is known to be an object: the schema declares the object type
     * itself (a list holding only "object" is the same declaration), or an enclosing schema asserts it.
     */
    public function assertsObject(): bool
    {
        return $this->objectAssertedByContext || (array) ($this->json['type'] ?? []) === ['object'];
    }

    /**
     * The $schema URI in effect for this node — see the $schemaUri property doc.
     */
    public function getSchemaUri(): ?string
    {
        return $this->schemaUri;
    }

    /**
     * The node's own $schema if declared, otherwise whatever this instance already inherited.
     * $json is mixed (not array) because navigate() can land on a non-object JSON value (e.g. a
     * "dependencies" entry that is a plain list of property names rather than a schema object).
     */
    private function deriveSchemaUri(mixed $json): ?string
    {
        return self::extractStringKeyword($json, '$schema') ?? $this->schemaUri;
    }

    /**
     * The $id declared by the given node, or null if the node doesn't declare one.
     */
    public static function extractId(mixed $json): ?string
    {
        return self::extractStringKeyword($json, '$id');
    }

    /**
     * Only a string value counts as the keyword. The node handed in is not necessarily a schema:
     * navigating lands on keyword maps such as "properties" or "definitions", whose keys are
     * user-chosen names, so a property named "$schema" or "$id" holds a property definition (an
     * array) under that key. Checking only for the key's presence would misread that definition
     * as the keyword, and tracking which keywords introduce a map instead would need a keyword
     * table kept in sync with every draft.
     */
    private static function extractStringKeyword(mixed $json, string $keyword): ?string
    {
        return is_array($json) && is_string($json[$keyword] ?? null) ? $json[$keyword] : null;
    }

    /**
     * create the signature from all fields which are directly relevant for the created object. Additional fields
     * can be ignored as the resulting code will be identical
     */
    public function getSignature(): string
    {
        return ArrayHash::hash(
            SchemaSignatureNormalizer::normalize($this->json),
            self::SCHEMA_SIGNATURE_RELEVANT_FIELDS,
            false,
        );
    }

    /**
     * @throws SchemaException
     */
    public function withJson(array $json): JsonSchema
    {
        $jsonSchema = clone $this;
        $jsonSchema->json = $jsonSchema->normalizeTypeDeclaration($json);
        $jsonSchema->booleanValue = null;
        $jsonSchema->schemaUri = $jsonSchema->deriveSchemaUri($json);

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
     *
     * A boolean-valued target yields a boolean schema, which can't be navigated into any further. A scalar or null
     * isn't a schema at all and is rejected. The subschema is no more asserted to be an object by the context than any
     * other value below this schema; a caller which navigates to a branch of a composition of an object passes the
     * assertion on explicitly.
     *
     * @throws SchemaException
     */
    public function navigate(string | int $pointer): JsonSchema
    {
        $trimmed = trim((string) $pointer, '/');

        if ($trimmed === '') {
            return $this;
        }

        $jsonSchema = clone $this;
        $jsonSchema->objectAssertedByContext = false;

        foreach (explode('/', $trimmed) as $pathSegment) {
            $jsonSchema->pointer .= "/$pathSegment";
            $decodedPathSegment = JsonSchemaUtil::decodePointer($pathSegment);

            if (!array_key_exists($decodedPathSegment, $jsonSchema->json)) {
                throw new SchemaException("Unresolved path segment $pathSegment in file $this->file", $jsonSchema);
            }

            $target = $jsonSchema->json[$decodedPathSegment];

            if (is_bool($target)) {
                $jsonSchema->json = [];
                $jsonSchema->booleanValue = $target;

                continue;
            }

            if (!is_array($target)) {
                throw new SchemaException(
                    sprintf(
                        "The value at '%s' in file '%s' is not a schema, got %s",
                        $jsonSchema->pointer,
                        $this->file,
                        gettype($target),
                    ),
                    $jsonSchema,
                );
            }

            $jsonSchema->json = $target;

            $declaredId = self::extractId($jsonSchema->json);

            if ($declaredId !== null) {
                $jsonSchema->baseId = self::normalizeId($declaredId);
            }
        }

        $jsonSchema->json = $jsonSchema->normalizeTypeDeclaration($jsonSchema->json);
        $jsonSchema->schemaUri = $jsonSchema->deriveSchemaUri($jsonSchema->json);

        return $jsonSchema;
    }

    public function getBaseId(): string
    {
        return $this->baseId;
    }

    public static function normalizeId(string $id): string
    {
        return str_starts_with($id, '#') ? $id : "#$id";
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
}
