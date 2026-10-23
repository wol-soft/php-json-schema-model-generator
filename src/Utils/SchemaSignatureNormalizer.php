<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

/**
 * Brings the lists of a schema whose order doesn't matter into a canonical order, so schemas which only differ in
 * such an order get the same signature (and are mapped to one class).
 *
 * Only lists which are known to be sets are sorted: type, required, enum and the dependants of a property
 * dependency. Every other list keeps its declared order - e.g. filter (the filters are executed in this order),
 * tuple items and composition branches (the branch order is part of the error messages and decides which branch
 * declaration of a property takes precedence). Sorting every list (the previous behaviour) mapped schemas with a
 * different filter or tuple order to the same class. Unknown keywords keep their order: a missed deduplication
 * only costs an additional identical class, a wrong one generates wrong code.
 */
class SchemaSignatureNormalizer
{
    private const array UNORDERED_LIST_KEYWORDS = ['type', 'required', 'enum'];
    /** Data, never interpreted as a schema: a list inside keeps its order, a key named like a keyword is no keyword */
    private const array VALUE_KEYWORDS = ['const', 'default', 'examples', 'enum'];
    private const array SCHEMA_KEYWORDS = [
        'not',
        'if',
        'then',
        'else',
        'items',
        'additionalItems',
        'additionalProperties',
        'contains',
        'propertyNames',
        'unevaluatedItems',
        'unevaluatedProperties',
    ];
    private const array SCHEMA_LIST_KEYWORDS = ['allOf', 'anyOf', 'oneOf', 'items', 'prefixItems'];
    private const array SCHEMA_MAP_KEYWORDS = [
        'properties',
        'patternProperties',
        'definitions',
        '$defs',
        'dependentSchemas',
    ];

    public static function normalize(array $schema): array
    {
        foreach ($schema as $keyword => $value) {
            if (!is_string($keyword) || !is_array($value)) {
                continue;
            }

            if (in_array($keyword, self::UNORDERED_LIST_KEYWORDS, true) && array_is_list($value)) {
                $schema[$keyword] = self::sortList($value);

                continue;
            }

            if (in_array($keyword, self::VALUE_KEYWORDS, true)) {
                continue;
            }

            $schema[$keyword] = match (true) {
                // items is a single schema or (tuple) a list of schemas
                in_array($keyword, self::SCHEMA_LIST_KEYWORDS, true) && array_is_list($value) =>
                    array_map(static fn($item): mixed => is_array($item) ? self::normalize($item) : $item, $value),
                in_array($keyword, self::SCHEMA_KEYWORDS, true) => self::normalize($value),
                in_array($keyword, self::SCHEMA_MAP_KEYWORDS, true) =>
                    array_map(static fn($item): mixed => is_array($item) ? self::normalize($item) : $item, $value),
                // property dependencies (list of dependants, a set) and schema dependencies
                in_array($keyword, ['dependencies', 'dependentRequired'], true) => array_map(
                    static fn($item): mixed => match (true) {
                        is_array($item) && array_is_list($item) => self::sortList($item),
                        is_array($item) => self::normalize($item),
                        default => $item,
                    },
                    $value,
                ),
                default => $value,
            };
        }

        return $schema;
    }

    private static function sortList(array $list): array
    {
        usort(
            $list,
            static fn($first, $second): int => strcmp(self::canonicalJson($first), self::canonicalJson($second)),
        );

        return $list;
    }

    private static function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }

            $value = array_map(self::canonicalJson(...), $value);
        }

        return (string) json_encode($value);
    }
}
