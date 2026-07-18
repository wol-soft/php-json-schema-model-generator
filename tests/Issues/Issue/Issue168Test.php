<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

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
     * A `"type": "array"` property must reject a JSON object, even when every one of the
     * object's values individually satisfies the `items` schema. `is_array($value)` alone is not
     * sufficient - the value must additionally be a list (`array_is_list($value)`).
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
     * A nested `"type": "object"` property must reject a JSON array outright, regardless of
     * `additionalProperties` - it must never be silently instantiated with every declared
     * property left at its default/null value. Because the fix keeps the value as the raw
     * (still-array) input whenever it fails the `array_is_list()` gate, the existing
     * TypeCheckValidator for "object" (`!is_object($value)`) is the one that fires, producing the
     * same "Requires object, got array" wording already used for a directly-passed scalar
     * mismatch (see ObjectPropertyTest).
     */
    public function testPermissiveObjectTypePropertyRejectsArrayInsteadOfDroppingItsData(): void
    {
        $className = $this->generateClassFromFile('ObjectTypePermissive.json');

        $this->expectException(InvalidTypeException::class);
        $this->expectExceptionMessage('Invalid type for target. Requires object, got array');

        new $className(['target' => ['Hello', 'World']]);
    }

    /**
     * Same as above with `additionalProperties: false` on the nested schema. Today this
     * incidentally rejects the array, but through the additionalProperties check misreporting the
     * array's integer indices as unexpected object property names. Once the `array_is_list()`
     * gate stops ObjectInstantiationDecorator from even attempting instantiation, the array is
     * never routed into the nested object at all, so the nested-object machinery
     * (additionalProperties included) never runs - the SAME clean outer type-mismatch fires as in
     * the permissive case, regardless of additionalProperties.
     */
    public function testRestrictiveObjectTypePropertyRejectsArrayWithTheSameCleanMessage(): void
    {
        $className = $this->generateClassFromFile('ObjectTypeRestrictive.json');

        $this->expectException(InvalidTypeException::class);
        $this->expectExceptionMessage('Invalid type for target. Requires object, got array');

        new $className(['target' => ['Hello', 'World']]);
    }

    /**
     * `"type": ["object", "array"]` must let a genuine JSON array satisfy the "array" alternative
     * and come back out as a plain PHP array - not be hijacked into an attempted object
     * instantiation just because ObjectInstantiationDecorator is the only decorator on the merged
     * property and fires unconditionally on any `is_array($value)`.
     */
    public function testMultiTypeObjectOrArrayProducesAnArrayForArrayShapedInput(): void
    {
        $className = $this->generateClassFromFile('MultiTypeObjectOrArray.json');

        $object = new $className(['target' => ['a', 'b', 'c']]);

        $this->assertSame(['a', 'b', 'c'], $object->getTarget());
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
     * `additionalProperties: <schema>` (as opposed to `true`/`false`) must not let a JSON array
     * masquerade as a fully legitimate `additionalProperties` bag keyed by its own indices. This
     * is a stronger form of testPermissiveObjectTypePropertyRejectsArrayInsteadOfDroppingItsData:
     * today the array isn't dropped, it is silently and fully absorbed as indistinguishable
     * object data (verified: `additionalProperties()->getAll() === [0 => 'Hello', 1 => 'World']`,
     * no exception). The `array_is_list()` gate on ObjectInstantiationDecorator fixes this too,
     * transitively - the nested object is never instantiated for a genuine list, so the
     * additionalProperties machinery never runs on it at all.
     */
    public function testObjectTypePropertyWithSchemaAdditionalPropertiesRejectsArrayInsteadOfAbsorbingIt(): void
    {
        $className = $this->generateClassFromFile('AdditionalPropertiesSchemaAbsorbsArray.json');

        $this->expectException(InvalidTypeException::class);
        $this->expectExceptionMessage('Invalid type for target. Requires object, got array');

        new $className(['target' => ['Hello', 'World']]);
    }

    /**
     * Same absorption bug as above, but via a digit-matching `patternProperties` pattern instead
     * of a schema-typed `additionalProperties` - and, unlike
     * testRestrictiveObjectTypePropertyRejectsArrayWithTheSameCleanMessage,
     * `additionalProperties: false` does NOT help here: the array's indices match the pattern
     * before the additionalProperties catch-all is ever reached, so today this absorbs the array
     * as a legitimate pattern-property match with no exception at all. The same
     * `array_is_list()` gate fixes it for the same reason - the nested object, and therefore its
     * patternProperties matching, is never reached for a genuine list.
     */
    public function testObjectTypePropertyWithNumericPatternPropertiesRejectsArrayInsteadOfAbsorbingIt(): void
    {
        $className = $this->generateClassFromFile('PatternPropertiesNumericAbsorbsArray.json');

        $this->expectException(InvalidTypeException::class);
        $this->expectExceptionMessage('Invalid type for target. Requires object, got array');

        new $className(['target' => ['Hello', 'World']]);
    }

    /**
     * A `oneOf` between an array branch and an object branch with schema-typed
     * `additionalProperties` is a strictly worse form of
     * testOneOfArrayOrObjectAcceptsValidObjectAsAnUnambiguousMatch: there, the object branch's
     * `additionalProperties: false` already excluded arrays from matching it, so only an object
     * matching the array branch's `items` schema was ambiguous. Here, EVERY array also matches
     * the object branch via absorption (see
     * testObjectTypePropertyWithSchemaAdditionalPropertiesRejectsArrayInsteadOfAbsorbingIt), so a
     * plain, unambiguous array input is rejected as "matched 2 elements" - oneOf between an array
     * schema and any realistically map-shaped object schema is broken outright today, not merely
     * ambiguous in edge cases.
     */
    public function testOneOfArrayOrObjectWithSchemaAdditionalPropertiesAcceptsArrayAsAnUnambiguousMatch(): void
    {
        $className = $this->generateClassFromFile('OneOfArrayOrObjectWithSchemaAdditionalProperties.json');

        $object = new $className(['target' => ['Hello', 'World']]);

        $this->assertSame(['Hello', 'World'], $object->getTarget());
    }
}
