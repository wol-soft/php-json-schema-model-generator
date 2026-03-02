# Issue #110 — Composition validation sets properties to null in failing branches

## Summary

When a composition (`oneOf`, `anyOf`, etc.) validates multiple branches, a failed branch's `catch`
block sets affected properties to `null` in `$modelData`. This is observable during serialization and
property access: properties that should retain their input values are seen as `null`.

The maintainer acknowledged (2026-02-19) that the null-setting mechanic's original purpose is unclear,
and that non-matching branches' properties should arguably be kept as additional properties. A later
comment (2026-03-01, `#issuecomment-3981294428`) reveals why the mechanic was introduced and frames
the real design tension.

---

## Root location

`src/Templates/Validator/ComposedItem.phptpl` — the catch block inside the branch-iteration loop.

**Code as of the #114 fix:**
```php
// After all branches, null only properties that no branch resolved:
foreach (array_diff($nullableProperties, $resolvedProperties) as $nullableProperty) {
    $modelData[$nullableProperty] = null;
}
```

`$modelData` is passed by reference from `validateComposition_N()` in
`src/SchemaProcessor/PostProcessor/Templates/CompositionValidation.phptpl`, which is itself a
reference to the caller's `$rawModelDataInput`. Mutations inside the composition closure propagate
back to the array that subsequent `processXxx()` calls read from.

---

## Why the null-setting was introduced

The maintainer's comment on 2026-03-01 (`#issuecomment-3981294428`) explains the real tension:

> "For this schema, the generated object currently has the interface
> ```php
> public function setStringProperty(?string $stringProperty): static;
> public function getStringProperty(): ?string;
> public function setIntegerProperty(?int $integerProperty): static;
> public function getIntegerProperty(): ?int;
> ```
> which feels valid but actually isn't. We could for example provide
> `{"stringProperty": 10, "integerProperty": 10}` and it is valid input but will break the interface."

The null-setting is a workaround for a fundamental API contract problem: when two `oneOf`/`anyOf`
branches define different properties, the generated model exposes typed getters for **all** properties
from **all** branches. But input can legally satisfy branch 1 with a value that would be a type
violation for branch 2's definition of the same key name — or more commonly, input provides a value
under a key defined by the non-matching branch with a type that doesn't fit the declared getter.

By nulling branch-1 properties when branch-1 fails, `processStringProperty` gets `null` (which
passes `?string` type check) instead of `10` (which would cause a `TypeError` at the return type
declaration). The null-setting is therefore **type-safety scaffolding** for a fundamentally broken
public API, not a bug introduced accidentally.

---

## The actual design problem

The generated model for:
```json
{
  "oneOf": [
    { "type": "object", "properties": { "stringProperty": { "type": "string" } } },
    { "type": "object", "properties": { "integerProperty": { "type": "integer" } } }
  ]
}
```

exposes this PHP interface:
```php
public function getStringProperty(): ?string
public function getIntegerProperty(): ?int
```

This looks clean but is a lie — the model can receive `{"stringProperty": 10, "integerProperty": 10}`
where `10` is valid for the integer branch but not the string branch. For `oneOf` this input is
actually **invalid** (both branches match), but for `anyOf`
`{"stringProperty": "hello", "integerProperty": 10}` is valid and:

- `getStringProperty()` correctly returns `"hello"`
- `getIntegerProperty()` correctly returns `10`

The problem is `{"stringProperty": 10, "integerProperty": 10}` under `anyOf` (valid — branch 2
matches on `integerProperty: 10`):

- Without null-setting: `getStringProperty()` would return `10` (int) → `TypeError` at `?string`
- With null-setting: `getStringProperty()` returns `null` ← type-safe but data was silently discarded

Neither outcome is semantically correct. The input had `stringProperty: 10` which, even if not
matching branch 1's string type, was still provided and should be accessible.

---

## Why removing null-setting alone fails

Simply removing the null-setting causes 32 test failures of the form:
```
TypeError: SomeClass::getStringProperty(): Return value must be of type ?string, int returned
```

These are all cases where input contains a value under the key of a non-matching branch, with a type
incompatible with the typed getter. The test expectations (`assertSame(null, $object->getStringProperty())`)
encode the current null-setting behavior.

Whether these test expectations are correct depends on the chosen API design — they are not
independently correct facts about what the model should do.

---

## Options considered

### Option A — Untyped getters (type erasure)
Discard per-property typed getters for composition properties. Too destructive; eliminates the primary
value of the library. Ruled out.

### Option B — Discriminated union branch objects
Stop promoting branch properties to the root class. Expose the resolved branch as a typed object:
`getValue(): BranchA|BranchB`. This option was initially considered promising but has critical
problems — see detailed analysis below.

### Option C — Keep flat promotion, remove type validators on transferred copies
Keep the flat API but strip type-check validators from root-level transferred copies. Requires
widening return types, which is still a breaking change.

### Option D — Current #114 fix: deferred null-setting with resolved-property guard (implemented)
Minimal fix; all tests pass. Does not address the underlying API problem. See current code.

---

## Why Option B (discriminated union objects) does not work cleanly

Option B was initially the recommended direction, but scrutiny reveals several structural problems:

### Problem 1 — The `getValue()` name is not self-describing and pollutes the API

A root-level `oneOf` represents constraints on the object itself, not a single named property. The
generated class has no natural property name to attach the accessor to. `getValue()` is generic and
meaningless. Worse, if the JSON schema also defines a regular property called `value`, there is a
direct name collision.

A `oneOf` or `anyOf` at the root level represents a **structural constraint** on the object as a
whole, not a named data field. Exposing it as a method implies the composition is a piece of data,
which it is not.

### Problem 2 — Objects with multiple compositions break the model

A schema can have both `oneOf` and `anyOf` at the root level simultaneously. The current architecture
creates one independent `ComposedPropertyValidator` per keyword. Under Option B, each would need its
own accessor method — two `getValue()`-style methods with no natural names and no coordination
between them. The object would have:

```php
getOneOfBranch(): OneOfBranchA|OneOfBranchB         // which index?
getAnyOfBranch(): AnyOfBranchA|AnyOfBranchB|null    // which index?
```

There is no schema-defined name for these compositions, so any generated name is arbitrary.

### Problem 3 — `anyOf` allows multiple matching branches simultaneously

For `anyOf`, multiple branches can validly match. The "resolved branch object" is ambiguous:

```json
{
  "anyOf": [
    { "properties": { "name": { "type": "string" } } },
    { "properties": { "age":  { "type": "integer" } } }
  ]
}
```

Input `{"name": "Alice", "age": 30}` satisfies **both** branches. Returning a single branch object
would be arbitrary. Returning an array of matched branch objects is accurate but exposes an awkward
API. Neither models the semantics well.

`allOf` already addresses this correctly with the merged-property approach (all branches always apply,
so merge all properties into one object). `anyOf` is semantically between `allOf` and `oneOf` and
does not have a clean single-object representation.

### Problem 4 — Root-level compositions work differently from property-level compositions

For `anyOf`/`allOf` on a **property** (non-root), the codebase already creates merged objects
(`MergedComposedPropertiesInterface`). For **root-level** compositions, `rootLevelComposition = true`
and no merged property is created — the properties are always promoted directly to the root schema.
Option B would require inverting this for root-level `oneOf`/`anyOf`, creating a new concept of
"root-level branch objects" that currently has no equivalent anywhere in the architecture.

---

## Recommended approach: Option C refined — flat API with honest typing

The cleanest solution that avoids all the problems above:

**Keep the flat property promotion. Remove type-check validators from root-level transferred copies.
Widen return types only where the JSON Schema type is genuinely ambiguous across branches.**

For a property that is defined with the same type in all branches that define it, the typed getter is
preserved:
```php
// Both branches define name: string → getStringProperty(): ?string  (safe, preserved)
```

For a property that is defined with conflicting types across branches, the return type is widened to
a **PHP union type** covering all branch types:
```php
// Branch 1: age: integer, Branch 2: age: string → getAge(): int|string|null
```

**Note:** An earlier version of this analysis proposed widening to `mixed`. That was superseded once
PHP native union type support was identified as the correct investment — see
`union-type-preparation.md`. `mixed` is a last resort when types are genuinely unknowable; a
property defined as `integer` in one branch and `string` in another has a precisely known type set
and should express it accurately.

The root-level `processXxx` methods do not re-validate types for composition-managed properties
(since the branch sub-class already validated them). They simply store whatever value is provided.

This means:
- No null-setting needed anywhere
- No branch objects, no accessor naming problem, no multiple-composition collision
- The API is honest: `int|string` accurately reflects which branch types are possible
- `anyOf` with multiple matching branches is handled naturally: all matching branches contributed
  their properties, stored directly on the flat model
- The existing `_propertyValidationState` / `validateComposition_N` machinery is unchanged

**Breaking change scope**: Only properties whose type genuinely varies across branches change from
a typed getter to a union. Properties with consistent types across all branches keep their typed
getters unchanged. This is a much smaller breaking surface than Option B.

---

## Will a fix for #110 make the current #114 fix obsolete?

**Yes, under the refined Option C approach.** Once type-check validators are removed from
root-level transferred property copies, the individual `processXxx` methods no longer throw on
type mismatches — they store whatever arrives. The null-setting in `ComposedItem.phptpl` becomes
unnecessary because there is no type error to pre-emptively prevent. The `$resolvedProperties` /
`$nullableProperties` tracking added for #114 can be removed along with the entire null-setting block.

The #114 fix is the correct minimal fix for now. It should be replaced as part of implementing
the #110 fix.

---

## Files to modify for Option C refined

- `src/Model/Schema.php` — `addProperty()`: widen to union when same name arrives with different type
- `src/PropertyProcessor/ComposedValue/AbstractComposedValueProcessor.php` — `transferPropertyType()`:
  set union when composition branches disagree on type
- `src/Templates/Validator/ComposedItem.phptpl` — remove `$resolvedProperties`, `$nullableProperties`,
  and the deferred null-setting block entirely (see implementation-plan.md Phase 7)
- Affected test expectations that rely on null-setting side effects (see list below)

---

## Additional defect: same property name in multiple branches — cross-typed promotion

Verified by generating a class from:
```json
{
  "oneOf": [
    { "properties": { "age": { "type": "integer", "minimum": 0 } }, "required": ["age"] },
    { "properties": { "age": { "type": "string",  "pattern": "^[0-9]+$" } }, "required": ["age"] }
  ]
}
```

### What the branch sub-classes do (correct)

Each nested branch class independently validates its own definition of `age`:
- Branch 1: `int $age`, required + `is_int` + `minimum >= 0`
- Branch 2: `string $age`, required + `is_string` + `pattern ^[0-9]+$`

The composition validation logic correctly tries each branch and counts successes.

### What the root class does (broken)

**`Schema::addProperty()` silently discards the second definition** (`src/Model/Schema.php:137`):

```php
if (!isset($this->properties[$property->getName()])) {
    $this->properties[$property->getName()] = $property;  // branch 1 wins
} else {
    // TODO: throw SchemaException — currently a no-op
    // branch 2's age is silently dropped
}
```

The integer branch (`age: int, minimum >= 0`) is transferred first and stored. The string branch's
`age` is transferred second and silently thrown away. The root class ends up with:

```php
/** @var int|null */
protected $age;
public function getAge(): ?int { ... }
public function setAge(?int $age): static { ... }
```

This is **wrong**: the model legally accepts `age: "42"` (string matching `^[0-9]+$`), but
`getAge()` declares `?int`. After a successful string-branch match, `getAge()` returns either `null`
(if null-setting silenced it) or throws a `TypeError` (if the string value reached the typed return).

**`validateAge()` on the root is intentionally empty** — `cloneTransferredProperty()` strips all
validators except `PropertyTemplateValidator`. This is correct by design (the branch sub-class
already ran the checks), but it means the root stores the raw value without any type enforcement.
The bug is purely in the declared PHP type of the getter/setter.

**`_getModifiedValues` works by coincidence** — it is a single method for all branches. It calls
`getAge()` on whichever branch object succeeded and compares to the raw input. For same-named
properties with different types this accidentally works because the comparison is **value-equality**
(`!==`), not type-strict (`!==` with strict types). If branch 2 succeeds with `age = "42"` (string)
and the original input has `age = "42"` (string), then `"42" !== "42"` is `false` →
`modifiedValues` stays empty → the root reads the raw value directly from the input array.
Correct outcome, wrong mechanism. The comparison would fail (treating an unmodified value as
modified) only if a filter transformed the value to a different representation.

### The `affectedObjectProperties` double-registration

`appendAffectedObjectProperty()` is called once per property per branch. Both branches register
their own cloned `age` property object, so `$nullableProperties` and `$resolvedProperties` can
each contain `'age'` twice. `array_diff` operates on string values so duplicates are harmless —
`array_diff(['age','age'], ['age'])` = `[]` correctly. But it is semantically redundant and the
two `age` objects tracked are distinct PHP objects representing the same logical property.

### What the fix for #110 must address here

`Schema::addProperty()` needs to handle the duplicate name case instead of silently no-oping.
Under refined Option C, the correct behaviour when a second property with the same name is added
from a composition branch with a **different type** is:

1. Widen the existing property's type to a union covering all branch types (e.g. `int|string`)
2. Widen the setter parameter to accept the same union
3. Remove any type-check validator that was retained from the first branch's clone

(An earlier draft of this analysis said "widen to `mixed`". That was superseded — see the
"Recommended approach" section above and `union-type-preparation.md`.)

When the type is the **same** across all branches that define the property (e.g. both define
`age: integer`), the property keeps its typed getter — `addProperty()` can simply skip the
second addition as it does now, since the types agree.

This detection logic belongs in `Schema::addProperty()` or in
`BaseProcessor::transferComposedPropertiesToSchema()` before the `addProperty()` call.

---

## Test cases encoding the null-setting behavior (require updating under Option C)

- `tests/ComposedValue/ComposedAnyOfTest.php` — `testMatchingPropertyForComposedAnyOfObjectIsValid`
  (data sets: negative int, zero int, positive int, empty string, numeric string, filled string, additional property)
- `tests/ComposedValue/ComposedAnyOfTest.php` — `testMatchingPropertyForComposedAnyOfObjectWithRequiredPropertiesIsValid`
- `tests/ComposedValue/ComposedOneOfTest.php` — `testMatchingPropertyForComposedOneOfObjectIsValid` (same data sets)
- `tests/ComposedValue/ComposedOneOfTest.php` — `testMatchingPropertyForComposedOneOfObjectWithRequiredPropertiesIsValid`
- `tests/ComposedValue/ComposedOneOfTest.php` — `testValidationInSetterMethods` (2 data sets)
- `tests/PostProcessor/PopulatePostProcessorTest.php` — `testPopulateComposition` (2 data sets)
