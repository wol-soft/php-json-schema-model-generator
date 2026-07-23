<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #168: JSON objects and JSON arrays both decode to a PHP `array` via `json_decode($json,
 * true)`, and the generator's type guards for "object" (an `is_array($value) ? new X($value) :
 * $value` instantiation attempt) and "array" (`is_array($value)` / iterate-by-value item
 * validation) both key off exactly that same PHP `array` type. Fixed by additionally requiring
 * `array_is_list($value)` at every load-bearing guard (`TypeCheckValidator`,
 * `ReflectionTypeCheckValidator`, `ObjectInstantiationDecorator`, `DefaultValueModifier`). See
 * .claude/topics/array-object-type-guard-collision/analysis.md for the full investigation and
 * implementation notes.
 */
class Issue168Test extends AbstractIssueTestCase
{
    /**
     * Five independently-discovered collision scenarios that all resolve to the same outcome:
     * a JSON array reaching a value that requires "array"-with-list-shape or "type": "object"
     * must be rejected with a clean `InvalidTypeException`, not silently mistyped/absorbed.
     */
    #[DataProvider('arrayOrObjectTypeMismatchDataProvider')]
    public function testArrayOrObjectTypeMismatchIsRejectedWithACleanTypeError(
        string $schemaFile,
        array $propertyValue,
        string $expectedMessage,
    ): void {
        $className = $this->generateClassFromFile($schemaFile);

        $this->expectException(InvalidTypeException::class);
        $this->expectExceptionMessage($expectedMessage);

        new $className(['target' => $propertyValue]);
    }

    public static function arrayOrObjectTypeMismatchDataProvider(): array
    {
        return [
            // A nested `"type": "object"` property with unrestricted `additionalProperties` (the
            // JSON Schema default) must reject a JSON array outright - it must never be silently
            // instantiated with every declared property left at its default/null value. Because
            // the fix keeps the value as the raw (still-array) input whenever it fails the
            // `array_is_list()` gate, the existing TypeCheckValidator for "object"
            // (`!is_object($value)`) is the one that fires, producing the same
            // "Requires object, got array" wording already used for a directly-passed scalar
            // mismatch (see ObjectPropertyTest).
            'permissive object property rejects a JSON array' => [
                'ObjectTypePermissive.json',
                ['Hello', 'World'],
                'Invalid type for target. Requires object, got array',
            ],
            // Same as above with `additionalProperties: false` on the nested schema. Before the
            // fix this incidentally rejected the array too, but through the additionalProperties
            // check misreporting the array's integer indices as unexpected object property
            // names. Once the `array_is_list()` gate stops ObjectInstantiationDecorator from even
            // attempting instantiation, the array is never routed into the nested object at all,
            // so the nested-object machinery (additionalProperties included) never runs - the
            // SAME clean outer type-mismatch fires as in the permissive case, regardless of
            // additionalProperties.
            'restrictive object property (additionalProperties: false) rejects with the same clean message' => [
                'ObjectTypeRestrictive.json',
                ['Hello', 'World'],
                'Invalid type for target. Requires object, got array',
            ],
            // `additionalProperties: <schema>` (as opposed to `true`/`false`) must not let a JSON
            // array masquerade as a fully legitimate `additionalProperties` bag keyed by its own
            // indices. Before the fix the array wasn't dropped, it was silently and fully
            // absorbed as indistinguishable object data (verified:
            // `additionalProperties()->getAll() === [0 => 'Hello', 1 => 'World']`, no exception).
            // The `array_is_list()` gate on ObjectInstantiationDecorator fixes this too,
            // transitively - the nested object is never instantiated for a genuine list, so the
            // additionalProperties machinery never runs on it at all.
            'object property with schema-typed additionalProperties rejects instead of absorbing a JSON array' => [
                'AdditionalPropertiesSchemaAbsorbsArray.json',
                ['Hello', 'World'],
                'Invalid type for target. Requires object, got array',
            ],
            // Same absorption bug as above, but via a digit-matching `patternProperties` pattern
            // instead of a schema-typed `additionalProperties` - and, unlike the restrictive case
            // above, `additionalProperties: false` did NOT help here: the array's indices matched
            // the pattern before the additionalProperties catch-all was ever reached, so before
            // the fix this absorbed the array as a legitimate pattern-property match with no
            // exception at all. The same `array_is_list()` gate fixes it for the same reason -
            // the nested object, and therefore its patternProperties matching, is never reached
            // for a genuine list.
            'object property with numeric patternProperties rejects instead of absorbing a JSON array' => [
                'PatternPropertiesNumericAbsorbsArray.json',
                ['Hello', 'World'],
                'Invalid type for target. Requires object, got array',
            ],
        ];
    }

    /**
     * A `"type": "array"` property must reject a JSON object, even when every one of the
     * object's values individually satisfies the `items` schema. `is_array($value)` alone is not
     * sufficient - the value must additionally be a list (`array_is_list($value)`). Kept separate
     * from arrayOrObjectTypeMismatchDataProvider() above because it targets a differently-named
     * property ("tags", declared as "array" rather than "object") in its own schema file.
     *
     * The resulting message ("Requires array, got array") is not very informative - `gettype()`
     * reports "array" for both a JSON array and a JSON object once decoded, so it cannot itself
     * distinguish which shape was actually provided. That is an existing message-formatting
     * limitation shared with every other `InvalidTypeException`, not something this fix
     * introduces; improving it is a separate, unstarted concern.
     */
    public function testArrayTypePropertyRejectsAJsonObject(): void
    {
        $className = $this->generateClassFromFile('ArrayTypeAcceptsObject.json');

        $this->expectException(InvalidTypeException::class);
        $this->expectExceptionMessage('Invalid type for tags. Requires array, got array');

        new $className(['tags' => ['a' => 'x', 'b' => 'y']]);
    }

    /**
     * A `oneOf` between a `"type": "array"` branch and a `"type": "object"` branch (with
     * `additionalProperties: false` on the object branch to block the reverse direction) must let
     * a well-formed object uniquely satisfy the object branch. It must not also match the array
     * branch just because the object's property values happen to satisfy the array's `items`
     * schema - the array branch must reject any non-list value, exactly as in
     * testArrayTypePropertyRejectsAJsonObject.
     */
    public function testOneOfArrayOrObjectAcceptsValidObjectAsAnUnambiguousMatch(): void
    {
        $className = $this->generateClassFromFile('OneOfArrayOrObject.json');

        $object = new $className(['target' => ['name' => 'Hans']]);

        $this->assertSame('Hans', $object->getTarget()->getName());
    }

    /**
     * Two independently-discovered scenarios where array-shaped input must come back out as a
     * plain PHP array, unchanged, instead of being hijacked into an attempted object
     * instantiation.
     */
    #[DataProvider('arrayShapedInputProducesAPlainArrayDataProvider')]
    public function testArrayShapedInputProducesAPlainArrayInsteadOfBeingAbsorbedIntoAnObject(
        string $schemaFile,
        array $propertyValue,
    ): void {
        $className = $this->generateClassFromFile($schemaFile);

        $object = new $className(['target' => $propertyValue]);

        $this->assertSame($propertyValue, $object->getTarget());
    }

    public static function arrayShapedInputProducesAPlainArrayDataProvider(): array
    {
        return [
            // `"type": ["object", "array"]` must let a genuine JSON array satisfy the "array"
            // alternative and come back out as a plain PHP array - not be hijacked into an
            // attempted object instantiation just because ObjectInstantiationDecorator is the
            // only decorator on the merged property and fires unconditionally on any
            // `is_array($value)`.
            'multi-type object-or-array property produces a plain array for array-shaped input' => [
                'MultiTypeObjectOrArray.json',
                ['a', 'b', 'c'],
            ],
            // A `oneOf` between an array branch and an object branch with schema-typed
            // `additionalProperties` is a strictly worse form of
            // testOneOfArrayOrObjectAcceptsValidObjectAsAnUnambiguousMatch: there, the object
            // branch's `additionalProperties: false` already excluded arrays from matching it, so
            // only an object matching the array branch's `items` schema was ambiguous. Here,
            // EVERY array also matched the object branch via absorption before the fix (see
            // arrayOrObjectTypeMismatchDataProvider()'s schema-typed-additionalProperties case),
            // so a plain, unambiguous array input used to be rejected as "matched 2 elements" -
            // oneOf between an array schema and any realistically map-shaped object schema was
            // broken outright, not merely ambiguous in edge cases.
            'oneOf array-or-object with schema additionalProperties accepts array as an unambiguous match' => [
                'OneOfArrayOrObjectWithSchemaAdditionalProperties.json',
                ['Hello', 'World'],
            ],
        ];
    }
}
