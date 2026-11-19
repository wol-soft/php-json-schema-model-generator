<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

/**
 * Resolves the base name for the classes of the composition branches of a schema from the JSON
 * pointer of the schema.
 */
class BranchNameResolver
{
    /** Base name for branches of a schema which doesn't live below a named slot (the root of a file) */
    public const string FALLBACK_NAME = 'Branch';

    /** Keywords which are followed by a user chosen key (property name, definition name) */
    private const array NAMED_SLOT_KEYWORDS = ['properties', 'definitions', '$defs'];

    /** Composition keywords which are followed by a branch index */
    private const array INDEXED_KEYWORDS = ['allOf', 'anyOf', 'oneOf'];

    /**
     * Returns the last property or definition name along the pointer, e.g. 'order' for
     * '/properties/order/allOf/0/allOf/1'. The pointer is walked from the start and the keyword
     * slots are consumed pairwise so a property which is called like a keyword
     * ('/properties/properties/allOf/0') resolves to that property instead of the keyword behind it.
     *
     * The key of the enclosing named slot is used instead of the name of the parent class: the
     * name of the parent class contains the names of all its ancestors, so a name derived from it
     * grows with every level of nesting and the file names eventually exceed the limit of the file
     * system. The key is a single segment of the pointer and therefore doesn't depend on the depth.
     */
    public static function resolve(string $pointer): string
    {
        $segments = array_values(array_filter(explode('/', $pointer), 'strlen'));
        $count = count($segments);
        $name = self::FALLBACK_NAME;

        for ($index = 0; $index < $count; $index++) {
            if (in_array($segments[$index], self::NAMED_SLOT_KEYWORDS, true) && $index + 1 < $count) {
                $name = $segments[++$index];
                continue;
            }

            if (
                in_array($segments[$index], self::INDEXED_KEYWORDS, true)
                && $index + 1 < $count
                && is_numeric($segments[$index + 1])
            ) {
                $index++;
            }
        }

        return $name;
    }
}
