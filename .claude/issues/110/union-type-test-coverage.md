# Union type preparatory work — test coverage requirements

## Overview

The union type preparatory work (Steps 1–6 in `union-type-preparation.md`) will change how native PHP
type hints are generated for multi-type properties. Currently, when a property can hold multiple PHP
types, the generator falls back to **no native type hint at all** (returning `null` from
`getReturnType()` / `getParameterType()`). After the preparatory work, it should emit a proper PHP 8.0
union type (`int|string|null`), which Reflection exposes as a `ReflectionUnionType` instance instead
of a `ReflectionNamedType`.

This document maps:
1. The five existing assertion helpers and how they interact with `ReflectionUnionType`
2. All test files that currently assert `assertNull()` on a type hint that will become non-null
3. All test files that use `$type->getName()` / `$type->allowsNull()` and how they remain correct
4. New assertion helpers needed
5. New test cases required per step

---

## Assertion helpers in `AbstractPHPModelGeneratorTestCase`

Five helpers are defined at lines 403–456:

```php
// DocBlock-based (return string, already union-capable):
getPropertyTypeAnnotation(class, property): string     // parses @var
getReturnTypeAnnotation(class, method): string         // parses @return
getParameterTypeAnnotation(class, method, int): string // parses @param

// Reflection-based (return ?ReflectionType, currently single-type only):
getParameterType(class, method, int): ?ReflectionType
getReturnType(class, method): ?ReflectionType
```

### The `ReflectionType` hierarchy problem

For a single-type property, `getReturnType()` returns a `ReflectionNamedType` which has:
```php
$type->getName(): string      // e.g. 'int', 'string', 'array'
$type->allowsNull(): bool
```

For a PHP 8.0 union type (`int|string`), `getReturnType()` returns a `ReflectionUnionType` which has:
```php
$type->getTypes(): ReflectionNamedType[]   // one element per constituent type
// NO getName() method — calling it causes a fatal error
// allowsNull() exists but only checks if null is in the union
```

This means every existing test that calls `$type->getName()` after `getReturnType()` / `getParameterType()`
**will work correctly for existing single-type properties** — those still return `ReflectionNamedType`.
The only tests that break are those asserting `assertNull($this->getReturnType(...))`, because those
properties will now have a non-null `ReflectionUnionType`.

### New helper methods needed in `AbstractPHPModelGeneratorTestCase`

To assert on union types cleanly, add:

```php
/**
 * Get all type names from a native PHP type hint (works for both single and union types).
 * Returns empty array if no type hint exists.
 * For single types: returns ['int'], ['string'], etc.
 * For union types: returns ['int', 'string'], ['int', 'string', 'null'], etc.
 * Null is included as 'null' when allowsNull() is true for single types, or
 * when 'null' is explicitly in the union.
 */
protected function getReturnTypeNames($object, string $method): array
{
    $type = $this->getReturnType($object, $method);
    if (!$type) {
        return [];
    }
    if ($type instanceof \ReflectionUnionType) {
        return array_map(fn($t) => $t->getName(), $type->getTypes());
    }
    // ReflectionNamedType
    $names = [$type->getName()];
    if ($type->allowsNull() && !in_array('null', $names, true)) {
        $names[] = 'null';
    }
    return $names;
}

protected function getParameterTypeNames($object, string $method, int $parameter = 0): array
{
    $type = $this->getParameterType($object, $method, $parameter);
    if (!$type) {
        return [];
    }
    if ($type instanceof \ReflectionUnionType) {
        return array_map(fn($t) => $t->getName(), $type->getTypes());
    }
    $names = [$type->getName()];
    if ($type->allowsNull() && !in_array('null', $names, true)) {
        $names[] = 'null';
    }
    return $names;
}
```

These helpers abstract over `ReflectionNamedType` vs `ReflectionUnionType` and let tests assert
`assertEqualsCanonicalizing(['int', 'string', 'null'], $this->getReturnTypeNames(...))`.

---

## Tests that currently assert `assertNull()` and will change

These are the tests that will **break** (in the sense of requiring updates) when union type support is
implemented, because the property that currently has no native type hint will gain one.

### `tests/Objects/MultiTypePropertyTest.php`

This is the primary affected file. It tests properties with `"type": ["number", "string", "array"]`
(schema: `MultiTypeProperty.json`, `RequiredMultiTypeProperty.json`).

**`testOptionalMultiTypeAnnotation()` — lines 61–62:**
```php
// CURRENT:
$this->assertNull($this->getParameterType($className, 'setProperty'));
$this->assertNull($this->getReturnType($className, 'getProperty'));

// AFTER union type support — property is float|string|array, optional → float|string|array|null:
$this->assertEqualsCanonicalizing(
    ['float', 'string', 'array', 'null'],
    $this->getParameterTypeNames($className, 'setProperty'),
);
$this->assertEqualsCanonicalizing(
    ['float', 'string', 'array', 'null'],
    $this->getReturnTypeNames($className, 'getProperty'),
);
```

Note: The DocBlock annotation tests on lines 53–59 remain unchanged (DocBlock path already works).

**`testRequiredMultiTypeAnnotation()` — lines 84–85:**
```php
// CURRENT:
$this->assertNull($this->getParameterType($className, 'setProperty'));
$this->assertNull($this->getReturnType($className, 'getProperty'));

// AFTER union type support — required, no null:
$this->assertEqualsCanonicalizing(
    ['float', 'string', 'array'],
    $this->getParameterTypeNames($className, 'setProperty'),
);
$this->assertEqualsCanonicalizing(
    ['float', 'string', 'array'],
    $this->getReturnTypeNames($className, 'getProperty'),
);
```

Note: When `implicitNull = false`, setter annotation was already `float|string|string[]` (no null).
The native type hint for required multi-type will omit null too.

### `tests/Objects/EnumPropertyTest.php`

**`testOptionalEnumPropertyWithMultipleValuesType()` — lines 193, 199:**

Schema `UntypedEnumProperty.json` has `"enum": ["red", null, 0, 10]` (string and int values).
Currently `string|int|null` DocBlock, no native hint.

```php
// CURRENT:
$this->assertNull($this->getReturnType($object, 'getProperty'));
$this->assertNull($this->getParameterType($object, 'setProperty'));

// AFTER:
$this->assertEqualsCanonicalizing(
    ['string', 'int', 'null'],
    $this->getReturnTypeNames($object, 'getProperty'),
);
$this->assertEqualsCanonicalizing(
    $implicitNull ? ['string', 'int', 'null'] : ['string', 'int'],
    $this->getParameterTypeNames($object, 'setProperty'),
);
```

**`testRequiredEnumPropertyWithSingleValueType()` (or the required multi-value variant) — similar lines:**

Schema with `"enum": ["red", 0, 10]` (string and int, no null), required.
```php
// CURRENT:
$this->assertNull($this->getReturnType($object, 'getProperty'));
$this->assertNull($this->getParameterType($object, 'setProperty'));

// AFTER (no null in union since not nullable):
$this->assertEqualsCanonicalizing(
    ['string', 'int'],
    $this->getReturnTypeNames($object, 'getProperty'),
);
```

### `tests/PostProcessor/EnumPostProcessorTest.php`

Tests where a PHP enum property with backing type has type-hint assertions. Line 91:
```php
$this->assertNull($this->getParameterType($object, 'setProperty'));
```
If the `EnumPostProcessor` enum property has a single backing type (e.g. `string`), this becomes
a typed `?MyEnum` setter — this may already be handled; verify on a case-by-case basis.

### `tests/PostProcessor/PatternPropertiesAccessorPostProcessorTest.php`

Line 442:
```php
$this->assertNull($this->getParameterType($object, 'setAlpha'));
```
This tests a filter-transformed property (`DateTime` filter on `string` — input type `string`,
output type `DateTime`). This falls into the filter/transformer multi-type case: `input ≠ output type`
causes `Property::getType()` to currently return `null`. With union type support it would return a
`PropertyType(['string', 'DateTime'])`.

Whether this is desirable for filter output types (versus the issue #110 cross-branch case) requires
a separate design decision. It may be appropriate to keep `assertNull()` here and only apply union
widening for the branch-collision scenario (Step 6), not for the filter case (Step 2).

---

## Tests that use `$type->getName()` — these remain correct

All tests that call `$type->getName()` after `getReturnType()` / `getParameterType()` do so on
properties that have a **single type** (possibly nullable). The generator currently produces single
`ReflectionNamedType` for these, and will continue to do so after the union work — only properties
that genuinely span multiple types get `ReflectionUnionType`.

**Files where `getName()` / `allowsNull()` calls are safe (no change required):**

| File | Method | Native type asserted |
|---|---|---|
| `BasicSchemaGenerationTest.php` | `testGetterAndSetterAreGeneratedForMutableObjects` | `'string'`, allowsNull |
| `ComposedOneOfTest.php` | `testCompositionTypes` | `'int'`, allowsNull |
| `ComposedOneOfTest.php` | `testTypesForComposedPropertyWithNullBranch` | `'array'`, allowsNull |
| `ComposedAnyOfTest.php` | `testCompositionTypes` | `'int'`, allowsNull |
| `ComposedAllOfTest.php` | `testCompositionTypes` | `'int'`, `'string'`, allowsNull |
| `ComposedAllOfTest.php` | `testComposedPropertyDefinitionWithValidValues` | `'int'`, allowsNull |
| `ComposedAllOfTest.php` | `testExtendedPropertyDefinitionWithValidValues` | `'float'`, allowsNull |
| `DefaultValueTest.php` | `testTypedPropertyTypeHintsWithImplicitNullEnabledAcceptNull` | `'int'`, allowsNull |
| `DefaultValueTest.php` | `testTypedPropertyTypeHintsWithImplicitNullDisabledDeclinesNull` | `'int'`, !allowsNull |
| `ArrayPropertyTest.php` | `testOptionalArrayPropertyType` | `'array'`, allowsNull |
| `ArrayPropertyTest.php` | `testRequiredArrayPropertyType` | `'array'`, !allowsNull |
| `EnumPropertyTest.php` | `testEnumPropertyWithNullType` | `'string'`, allowsNull |
| `RequiredPropertyTest.php` | `testRequiredPropertyType` | `'string'`, !allowsNull |
| `BuilderClassPostProcessorTest.php` | `testBuilderClassSetsVariablesCorrectly` | `'string'` |

**Exception: `DefaultValueTest.php` — untyped property (lines 220, 223):**
```php
$this->assertNull($this->getReturnType($object, 'getProperty'));   // "mixed" = no native hint
$this->assertNull($this->getParameterType($object, 'setProperty')); // same
```
This tests a property with `"type"` absent or `"type": "object"` with no specific type mapping — the
generator currently emits no native type for `mixed`. This `assertNull` should **remain** as-is
because `mixed` means "unknown type", not "multiple known types". The union work should not affect
`mixed` properties.

---

## `RenderHelper::getType()` — the `?Type` vs `Type|null` distinction

After Step 3, `RenderHelper::getType()` must preserve `?string` (the shorthand) for single nullable
types and only emit the long form `int|string|null` for actual union types. Tests that verify
**exact generated code** (if any) need to account for this. Currently no tests assert the exact
string form of a type hint — they all go through Reflection. So this formatting rule is internal
and does not require additional test assertions, only correctness in `RenderHelper::getType()` itself.

---

## New test cases required per step

### Step 1 — `PropertyType` extended to accept `string[]`

Unit test (if unit tests exist for `PropertyType`, else integration via generated output):
- `new PropertyType('string')` → `getNames()` returns `['string']`, `isUnion()` returns `false`
- `new PropertyType(['int', 'string'])` → `getNames()` returns `['int', 'string']`, `isUnion()` returns `true`
- `new PropertyType(['int', 'string'])` → `getName()` returns `'int'` (first element, backward compat)

### Step 2 — `Property::getType()` returns union instead of null for filter/transformer case

Existing `MultiTypePropertyTest` (schema: `"type": ["number", "string", "array"]`) exercises the
multi-processor path, not the filter/transformer path. A separate test is needed:

Schema with a `date-time` format (string input → DateTime output via filter):
- `getReturnType()` for getter currently returns `null`; with Step 2 it returns `ReflectionUnionType(['string', 'DateTime'])`
- Covered by `PatternPropertiesAccessorPostProcessorTest` line 442 (see note above about design decision)

**Design decision required before implementing Step 2:** Should filter/transformer input↔output type
pairs produce a union hint, or only the cross-branch property collision from Step 6? If only Step 6,
then `Property::getType()` should NOT return a union for the filter case; only `Schema::addProperty()`
should produce multi-type `PropertyType` instances.

### Step 3 — `RenderHelper::getType()` emits union syntax

Add an integration test that:
1. Generates a class with a multi-type property (once Step 6 creates them)
2. Asserts the generated PHP file contains `int|string|null` syntax, NOT `?int` or `?string` alone
3. Asserts the generated PHP file does NOT contain `?int|string` (which is a PHP syntax error)

### Step 5 — Type-check validator generation for union types

The type-check validator for `age: int|string` must produce:
```php
if (!is_int($value) && !is_string($value) && $value !== null) { throw ...; }
```
not:
```php
if (!is_int($value) && $value !== null) { throw ...; }
```

New test verifying that passing a `string` to an `int|string` property does **not** throw, while
passing a `bool` still throws. This requires a schema and test method — see Step 6 below since the
first consumer is the cross-branch property.

### Step 6 — `Schema::addProperty()` widens on collision

This is the most important new test coverage area. A new test class (e.g. `CrossTypedCompositionTest`)
testing the `ageCrossTyped.json` schema (currently at `tests/Schema/Issues/110/ageCrossTyped.json`,
to be moved to `tests/Schema/CrossTypedCompositionTest/` when the test class is created):

```json
{
  "type": "object",
  "oneOf": [
    { "properties": { "age": { "type": "integer", "minimum": 0 } }, "required": ["age"] },
    { "properties": { "age": { "type": "string",  "pattern": "^[0-9]+$" } }, "required": ["age"] }
  ]
}
```

Required assertions:

**Type hint assertions:**
```php
// DocBlock still works (already did before):
$this->assertSame('int|string', $this->getPropertyTypeAnnotation($object, 'age'));
$this->assertSame('int|string', $this->getReturnTypeAnnotation($object, 'getAge'));
$this->assertSame('int|string', $this->getParameterTypeAnnotation($object, 'setAge'));

// Native PHP union type (new assertions):
$this->assertEqualsCanonicalizing(
    ['int', 'string'],
    $this->getReturnTypeNames($object, 'getAge'),
);
$this->assertEqualsCanonicalizing(
    ['int', 'string'],
    $this->getParameterTypeNames($object, 'setAge'),
);
```

**Functional assertions (int branch):**
```php
$object = new $className(['age' => 42]);
$this->assertSame(42, $object->getAge());
```

**Functional assertions (string branch):**
```php
$object = new $className(['age' => '42']);
$this->assertSame('42', $object->getAge());
```

**Validation still enforced in branch sub-classes:**
```php
// Integer branch: minimum: 0
$this->expectException(ValidationException::class);
new $className(['age' => -1]);

// String branch: pattern ^[0-9]+$
$this->expectException(ValidationException::class);
new $className(['age' => 'abc']);
```

**Both branches invalid (oneOf: exactly one must match):**
```php
$this->expectException(ValidationException::class);
new $className(['age' => 3.14]);  // not int, not string → neither branch matches
```

---

## `testCompositionTypes()` in Composed*Test files — no change expected

`ComposedOneOfTest::testCompositionTypes()` uses a schema where `age` is `integer` in the oneOf and
`name` is `string`. Both are **single-type** compositions — each property is defined in only one
branch. The root class gets `getAge(): ?int` (int branch) and `getName(): ?string` (string branch).
These do **not** collide across branches.

After union type work, these single-type compositions continue to produce `ReflectionNamedType`, so:
```php
$this->assertSame('int', $setAgeParamType->getName());
$this->assertTrue($setAgeParamType->allowsNull());
```
...remain correct and need no change.

The `testCompositionTypes()` tests only need updating if the schemas are changed to use cross-typed
same-name properties — which they should not be, as they test a different scenario.

---

## Summary of assertion changes

| Test file | Test method | Change type |
|---|---|---|
| `MultiTypePropertyTest` | `testOptionalMultiTypeAnnotation` | `assertNull → assertEqualsCanonicalizing` on new helpers |
| `MultiTypePropertyTest` | `testRequiredMultiTypeAnnotation` | Same |
| `EnumPropertyTest` | `testOptionalEnumPropertyWithMultipleValuesType` | Same |
| `EnumPropertyTest` | `testRequiredEnumPropertyWithSingleValueType` (multi-type) | Same |
| `PatternPropertiesAccessorPostProcessorTest` | accessor multi-type | Depends on design decision for filter case |
| `DefaultValueTest` | `testUntypedPropertyTypeAnnotationsAreMixed` | No change (mixed stays null) |
| All `Composed*Test.testCompositionTypes()` | — | No change (single-type compositions) |

**New helpers needed:** `getReturnTypeNames()`, `getParameterTypeNames()` in `AbstractPHPModelGeneratorTestCase`

**New test class needed:** `CrossTypedCompositionTest` (or added to existing `ComposedOneOfTest`/`ComposedAnyOfTest`)
covering `ageCrossTyped.json`-style schemas with assertions on both type hints and runtime behaviour.
