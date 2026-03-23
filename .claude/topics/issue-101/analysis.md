# Issue #101 — TypeError: `_getModifiedValues_*` receives object instead of array

## Bug description

When a **nested object property** has a `oneOf`/`anyOf` composition whose branches each carry
**distinct named properties** (so a real `_Merged_` auxiliary class is created), instantiating the
parent class with valid data throws a PHP `TypeError` at runtime:

```
TypeError: SomeClass::_getModifiedValues_xxxxx(): Argument #1 ($originalModelData)
    must be of type array, SomeNestedClass given
```

## Root cause

### Execution order in the generated `processXxx()` / `validateXxx()` pair

For a nested object property the generated code looks like this (simplified):

```php
// processBudgetRange — generated from Model.phptpl line 168–181
protected function processBudgetRange(array $modelData): void
{
    $value = $modelData['budget_range'] ?? null;

    // ObjectInstantiationDecorator runs HERE (resolvePropertyDecorator with nestedProperty=true)
    // $value is now an instance of Budget_range (the nested class), NOT the raw array any more
    $value = (function ($value) {
        return is_array($value) ? new Budget_range($value) : $value;
    })($value);

    $this->budgetRange = $this->validateBudgetRange($value, $modelData);
}

protected function validateBudgetRange($value, array $modelData)
{
    // ... type / instanceof validators ...
    // then the ComposedItem.phptpl closure:
    (function (&$value) use (...) {
        $originalModelData = $value;  // <-- $value is already a Budget_range OBJECT here!
        ...
        // later, inside the branch loop:
        $modifiedValues = array_merge(
            $modifiedValues,
            $this->_getModifiedValues_xxxxx($originalModelData, $value)
            //                             ^^^^^^^^^^^^^^^^^^
            //  Budget_range object passed where array is expected  --> TypeError
        );
    })($value);
}
```

The generated `_getModifiedValues_*` method (in `ComposedPropertyValidator::getCheck()`,
`SchemaProcessor.php` line ~84) has the signature:

```php
private function _getModifiedValues_xxxxx(array $originalModelData, object $nestedCompositionObject): array
```

So passing the already-instantiated object as `$originalModelData` violates the `array` type
declaration and produces the `TypeError`.

### Why the instantiation happens before validation

`ObjectInstantiationDecorator` is attached as a **property decorator** (via
`ObjectProcessor::process()`, not as a validator). In `Model.phptpl`, decorators are applied
inside `processXxx()` at the `resolvePropertyDecorator(property, true)` call — **before** the
`validateXxx()` call. By design, the nested object is fully instantiated before validation runs so
that validators can operate on the typed object.

The composition validator in `ComposedItem.phptpl` then captures that already-instantiated object
as `$originalModelData`. The `_getModifiedValues_*` method expects the **raw** input array in
order to detect which keys were modified by filters inside the branch, but it receives the
already-constructed object instead.

### When is the merged-property path taken?

`createMergedProperty` (and therefore the `_getModifiedValues_*` method) is only generated when:
1. `rootLevelComposition` is `false` (the composition is on a nested property, not the root
   schema), AND
2. `MergedComposedPropertiesInterface` is implemented (i.e. `anyOf` or `oneOf`), AND
3. `redirectMergedProperty` returns `false` — meaning **multiple** composition branches each have
   a nested schema (distinct properties per branch).

Condition 3 is key: the issue from #98 involved branches that added only constraints (no distinct
properties), so only one branch had a nested schema and `redirectMergedProperty` redirected to it.
Issue #101 involves branches where **each branch has its own set of named properties**, so all
branches have nested schemas and a true `_Merged_` class must be created.

## Minimal reproduction schema

A parent object with a nested `pricing_options` array whose items use `oneOf` with two branches
that each have distinct property names:

```json
{
  "type": "object",
  "properties": {
    "item": {
      "type": "object",
      "oneOf": [
        {
          "properties": {
            "price_per_unit": { "type": "number" }
          }
        },
        {
          "properties": {
            "flat_fee": { "type": "number" }
          }
        }
      ]
    }
  }
}
```

Instantiating with `new ParentClass(['item' => ['price_per_unit' => 9.99]])` throws:
```
TypeError: ParentClass::_getModifiedValues_xxxxx(): Argument #1 ($originalModelData)
    must be of type array, <NestedClass> given
```

The `anyOf` variant produces the same error.

## Fix options

### Option A — Pass raw array to `_getModifiedValues_*` from the template

In `ComposedItem.phptpl`, the `$originalModelData` at line 13 is captured from `$value` which is
already an object. The fix is to capture the raw input array separately before any object
instantiation could have happened, and pass that as the first argument.

However the ComposedItem template runs **inside** the validator closure, where the outer `$value`
is already the instantiated object. There is no separate "raw array" variable in scope at that
point.

A possible approach: add a new template variable that holds the raw array representation of the
value. But this requires passing it in from the outer `processXxx` / `validateXxx` scope.

### Option B (recommended) — Change `_getModifiedValues_*` to accept object|array

The cleanest fix: make the generated `_getModifiedValues_*` method accept `object | array` instead
of requiring `array`. When the argument is an object, use the object's own accessor methods (or
`get_object_vars`) to extract the raw key-value pairs, mirroring what the array path does.

Or more simply: when `$originalModelData` is an object, treat all keys as "not originally
present", which causes the method to return `[]` (no modified values). This is correct because
object instantiation already happened; the purpose of `_getModifiedValues_*` is to propagate
filter-transformed values from a branch object back into the merged object. When the outer value
is already an object, it has already gone through any filter transforms during its own
construction, so there is nothing extra to propagate.

### Option C — Skip `_getModifiedValues_*` call when value is already an object

In `ComposedItem.phptpl`, the call at line 91–93 is already wrapped in `if (is_object($value))`.
But `$originalModelData` is also an object at that point. A simpler guard:

```
if (is_object($value) && is_array($originalModelData)) {
    $modifiedValues = array_merge($modifiedValues, $this->{{ modifiedValuesMethod }}($originalModelData, $value));
}
```

When `$originalModelData` is already an object (because the outer property value was instantiated
before the composition validator ran), skip the modified-values collection entirely. The
already-instantiated object already incorporates any filter transforms; there is nothing further to
merge.

This is the minimal targeted fix and aligns with the existing `is_object($value)` guard's intent.

## Preferred fix

**Option C** — add `&& is_array($originalModelData)` to the existing guard in `ComposedItem.phptpl`.
It is a one-line change, surgical, and correct: when the value was already instantiated as an
object before the composition validator ran (the nested-object case), filter-transformed values
have already been applied during construction. There is no raw array to diff against.

## Key code locations

- `src/Templates/Validator/ComposedItem.phptpl:91–93` — guard around `_getModifiedValues_*` call
- `src/Model/Validator/ComposedPropertyValidator.php:84` — generated method signature `(array $originalModelData, ...)`
- `src/PropertyProcessor/Property/ObjectProcessor.php:69–77` — where `ObjectInstantiationDecorator` is added
- `src/Templates/Model.phptpl:178` — where property decorator runs (before `validateXxx`)

## Test coverage plan — COMPLETED

Schemas:
- `tests/Schema/Issues/101/nestedObjectWithAnyOfDistinctBranchProperties.json` (mode/speed/level)
- `tests/Schema/Issues/101/nestedObjectWithOneOfDistinctProperties.json` (pricing_option)
- `tests/Schema/Issues/101/nestedObjectWithAnyOfDistinctProperties.json` (budget)

All edge cases covered in `tests/Issues/Issue/Issue101Test.php`:
1. `anyOf` — two branches with distinct named properties → valid input accepted, correct getters work
2. `oneOf` — same scenario, invalid input (both branches) rejected
3. Valid input accepted (correct branch satisfies the composition)
4. Invalid input rejected (neither/both branches match for `oneOf`, no branch for `anyOf`)
5. The returned object is not null and has correct property values
6. Absent/null optional property accepted for all three schemas

## Resolution

The issue-#98 fix (`addComposedValueValidator` skipping composition validation for nested object
properties that already have a resolved schema) also resolves issue #101. When `rootLevelComposition`
is false AND the property already has a nested schema, the composition validator is never added to
the parent class, so `_getModifiedValues_*` is never called with an already-instantiated object.

The `ComposedItem.phptpl` fix (Option C) was the original preferred fix before #98 was in place,
but it is not needed — the #98 guard prevents the problematic path from being reached entirely.

All 23 tests pass.
