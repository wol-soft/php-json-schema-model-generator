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

## Files Changed

- `src/Templates/Validator/ComposedItem.phptpl` — Added `&& is_array($originalModelData)` guard
- `tests/Schema/Issues/141/allOfWithRefEnum.json` — Test schema with allOf + $ref to string enum
- `tests/Issues/Issue/Issue141Test.php` — Test that construction with valid value doesn't throw TypeError

## Verification

- 2432 tests, 6094 assertions — all passing
- PHPCS clean
