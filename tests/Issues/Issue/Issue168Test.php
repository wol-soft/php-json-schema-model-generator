<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

/**
 * Issue #168: JSON objects and JSON arrays both decode to a PHP `array` via `json_decode($json,
 * true)`, and the generator's type guards for "object" (an `is_array($value) ? new X($value) :
 * $value` instantiation attempt) and "array" (`is_array($value)` / iterate-by-value item
 * validation) both key off exactly that same PHP `array` type. Neither guard ever checks
 * `array_is_list()`, so nothing in the generated code actually distinguishes a JSON array from a
 * JSON object once the raw value has reached PHP. See
 * .claude/topics/array-object-type-guard-collision/analysis.md for the full investigation.
 *
 * Each test below asserts the CORRECT, desired behavior per JSON Schema - not today's actual
 * (buggy) behavior. All of them are therefore expected to be red until a fix (an
 * `array_is_list()` gate on both guards, per the analysis doc's suggested fix direction) lands;
 * they must not be weakened to pass against the current, broken output. Once the fix lands, they
 * should turn green with no further change needed.
 */
class Issue168Test extends AbstractIssueTestCase
{
    /**
     * A `"type": "array"` property must reject a JSON object, even when every one of the
     * object's values individually satisfies the `items` schema. `is_array($value)` alone is not
     * sufficient - the value must additionally be a list (`array_is_list($value)`).
     */
    public function testArrayTypePropertyRejectsAJsonObject(): void
    {
        $className = $this->generateClassFromFile('ArrayTypeAcceptsObject.json');

        $this->expectException(InvalidTypeException::class);

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
}
