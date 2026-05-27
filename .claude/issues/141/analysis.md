# Issue #141 — `_getModifiedValues` TypeError with string enum + allOf/$ref

## Bug

When a property uses `allOf: [{$ref: "#/$defs/SomeEnum"}]` where `SomeEnum` is a string enum, the `EnumPostProcessor`'s `Enum::filter` transforms the input string (e.g., `'clicks'`) to a `UnitEnum` object during validation. The composed property validator's `_getModifiedValues_*` helper crashes because `$originalModelData` is still the original string (set before the filter ran on line 13 of `ComposedItem.phptpl`), but the method expects `array`.

## Root Cause

In `src/Templates/Validator/ComposedItem.phptpl`:

```
$originalModelData = $value;    // line 13 — captures raw string
// ... validators run, filter transforms $value to enum object
if (is_object($value)) {        // line 91 — passes because filter made it an object
    $this->modifiedValuesMethod($originalModelData, $value);  // line 92 — $originalModelData is string, expects array
}
```

## Fix

Added `&& is_array($originalModelData)` to the guard on line 91:

```php
if (is_object($value) && is_array($originalModelData)) {
```

This is safe because:
- `_getModifiedValues` accesses `$originalModelData[$key]` (array access). When the original value was a scalar, `getNestedSchema()` returns null for the composition property, so `$propertyAccessors` is empty and the method would return `[]` anyway.
- For normal object properties (the existing working case), `$value` starts as an array, so `is_array($originalModelData)` is true and behavior is unchanged.

## Fix 1: _getModifiedValues TypeError (ComposedItem.phptpl)

Added `&& is_array($originalModelData)` to the guard on line 91 of `ComposedItem.phptpl`:

```php
if (is_object($value) && is_array($originalModelData)) {
```

## Fix 2: Getter return type missing enum class (EnumPostProcessor)

Added `propagateEnumTypeToParent()` method and calls in `processNestedEnumProperties()`.
After a composed property (inside allOf/oneOf/anyOf or array items) gains an enum output
type from `processPropertyEnum`, the new method propagates the enum class names to the
parent property's output type via `$parentProperty->setType(..., false)`.

This ensures `getType(true)` (used for PHP return type declarations of getters and setters)
includes the enum class name alongside the original scalar type. Previously, only docblock
annotations were updated via `CompositionTypeHintDecorator`, but the PHP type declaration
still declared the original scalar type — causing a TypeError when calling the getter.

## Files Changed

- `src/Templates/Validator/ComposedItem.phptpl` — Added `&& is_array($originalModelData)` guard
- `src/SchemaProcessor/PostProcessor/EnumPostProcessor.php` — Added `propagateEnumTypeToParent()`,
  called from `processNestedEnumProperties()` for both composed properties and array item properties
- `tests/Schema/Issues/141/allOfWithRefEnum.json` — Test schema with allOf + $ref to string enum
- `tests/Issues/Issue/Issue141Test.php` — Consolidates all EnumPostProcessor assertions (construction,
  getter, null, invalid) in one test method to avoid enum class redeclaration

## Verification

- 2430 tests, 6095 assertions — all passing
- PHPCS clean
