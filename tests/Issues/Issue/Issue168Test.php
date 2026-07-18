<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

/**
 * Issue #168: JSON objects and JSON arrays both decode to a PHP `array` via `json_decode($json,
 * true)`, and the generator's type guards for "object" (an `is_array($value) ? new X($value) :
 * $value` instantiation attempt) and "array" (`is_array($value)` / iterate-by-value item
 * validation) both key off exactly that same PHP `array` type. Neither guard ever checks
 * `array_is_list()`, so nothing in the generated code actually distinguishes a JSON array from a
 * JSON object once the raw value has reached PHP. Each test below pins the CURRENT, verified
 * behavior of the generated code for one shape of this collision - it is not the desired
 * behavior. See .claude/topics/array-object-type-guard-collision/analysis.md for the full
 * investigation.
 */
class Issue168Test extends AbstractIssueTestCase
{
    /**
     * A `"type": "array"` property has no guard against list-ness at all: `is_array($value)` is
     * true for an associative PHP array too, and the `items` validator iterates by VALUE
     * (`foreach ($items as $index => &$value)`), never checking that the keys are a 0-based
     * sequential list. A JSON object therefore satisfies "type": "array" outright - keys and all
     * - as long as its values individually satisfy the `items` schema.
     */
    public function testArrayTypePropertySilentlyAcceptsAJsonObject(): void
    {
        $className = $this->generateClassFromFile('ArrayTypeAcceptsObject.json');

        $object = new $className(['tags' => ['a' => 'x', 'b' => 'y']]);

        // Per JSON Schema, {"a": "x", "b": "y"} does NOT satisfy "type": "array" - this is
        // pinning the current (incorrect) permissive behavior, not the desired outcome.
        $this->assertSame(['a' => 'x', 'b' => 'y'], $object->getTags());
    }

    /**
     * A nested `"type": "object"` property with unrestricted `additionalProperties` (the JSON
     * Schema default) silently accepts a JSON array: `is_array($value)` is true, so
     * ObjectInstantiationDecorator attempts `new NestedClass($value)`. None of the array's
     * integer keys (0, 1, ...) match a declared property name, so every declared property is
     * left at its default/null value, no error is raised, and the array's data is silently
     * dropped.
     */
    public function testPermissiveObjectTypePropertySilentlyDropsArrayDataWithNoError(): void
    {
        $className = $this->generateClassFromFile('ObjectTypePermissive.json');

        $object = new $className(['target' => ['Hello', 'World']]);

        // Per JSON Schema, ["Hello", "World"] does NOT satisfy "type": "object" - this pins the
        // current silent-data-loss acceptance, not the desired outcome (a rejection).
        $this->assertNotNull($object->getTarget());
        $this->assertNull($object->getTarget()->getName());
    }

    /**
     * The same array-into-object collision as above, but with `additionalProperties: false` on
     * the nested schema. The array IS rejected here, but only as a side effect of the
     * additionalProperties check treating the array's integer indices as if they were unexpected
     * object property names - the resulting message talks about "additional properties [0, 1]",
     * which is misleading for a caller who passed a JSON array, not an object with unexpected
     * keys.
     */
    public function testRestrictiveObjectTypePropertyRejectsArrayWithMisleadingMessage(): void
    {
        $className = $this->generateClassFromFile('ObjectTypeRestrictive.json');

        $this->expectException(NestedObjectException::class);
        $this->expectExceptionMessageMatches(
            '/Provided JSON for \w+ contains not allowed additional properties \[0, 1\]/',
        );

        new $className(['target' => ['Hello', 'World']]);
    }

    /**
     * `"type": ["object", "array"]` combines both branches on one property, but
     * ObjectInstantiationDecorator is the only decorator ever attached (the array branch adds
     * only validators, no decorator), and it fires unconditionally for any `is_array($value)`.
     * A genuine JSON array meant to satisfy the "array" branch is therefore always hijacked into
     * an attempted object instantiation first - the "array" alternative of the union is
     * unreachable for real JSON arrays whose elements don't happen to also be valid properties.
     */
    public function testMultiTypeObjectOrArrayNeverProducesAnArrayForArrayShapedInput(): void
    {
        $className = $this->generateClassFromFile('MultiTypeObjectOrArray.json');

        $object = new $className(['target' => ['a', 'b', 'c']]);
        $target = $object->getTarget();

        // Desired behavior per the "array" alternative would be a plain PHP array ['a','b','c'].
        // Pinning current behavior: it is silently coerced into the object branch instead, and
        // all three array elements are lost (none of them is named "name").
        $this->assertIsObject($target);
        $this->assertNull($target->getName());
    }

    /**
     * A `oneOf` between a `"type": "array"` branch and a `"type": "object"` branch (with
     * `additionalProperties: false` on the object branch to block the reverse direction) should
     * let a well-formed object uniquely satisfy the object branch. Because the array branch's
     * `items` validator accepts any PHP array/map whose values match the item schema (see
     * testArrayTypePropertySilentlyAcceptsAJsonObject), an object whose property values happen to
     * satisfy the array's item schema (a string, here) matches BOTH branches and oneOf rejects
     * otherwise-valid input as ambiguous.
     */
    public function testOneOfArrayOrObjectRejectsValidObjectAsAmbiguousMatch(): void
    {
        $className = $this->generateClassFromFile('OneOfArrayOrObject.json');

        $this->expectException(OneOfException::class);
        $this->expectExceptionMessage(
            "Invalid value for target declined by composition constraint.\n"
            . '  Requires to match one composition element but matched 2 elements.',
        );

        new $className(['target' => ['name' => 'Hans']]);
    }
}
