# Analysis: Type overlap between patternProperties and declared/composition properties

## Background

Issue #110 implemented `PropertyMerger` to handle type narrowing and widening when the same
property name appears in multiple composition branches (`anyOf`/`oneOf`/`allOf`/`if-then-else`)
and also when a root-declared property overlaps with a composition branch
(`base-property-precedence` topic).

`patternProperties` introduces a third overlap source that is architecturally distinct from
compositions: a pattern like `"^a"` can match declared property names (e.g. `"alpha"`) and
also match properties that are introduced by composition branches. The current implementation
does not apply `PropertyMerger` to these overlaps; instead it handles them differently (or
not at all) through the post-processor pipeline.

---

## What patternProperties currently does

### Processing pipeline

1. **`BaseProcessor::addPatternPropertiesValidator`** (`src/PropertyProcessor/Property/BaseProcessor.php:154`)
   — called during schema processing. For each pattern, creates a `PatternPropertiesValidator`
   and attaches it to the schema's base validators. This validator holds an internal
   `$validationProperty` (a `Property` object typed per the pattern schema) that is used to
   validate each matching key at runtime. No interaction with declared properties at this stage.

2. **`PatternPropertiesPostProcessor`** (`src/SchemaProcessor/PostProcessor/Internal/PatternPropertiesPostProcessor.php`)
   — post-processing phase. Walks declared properties and checks which ones match each pattern
   regex. Stores the matching `PropertyInterface` objects per pattern hash in `$patternHashes`
   for use by the constructor hook. Adds two internal properties (`_patternProperties`,
   `_patternPropertiesMap`). Emits a constructor hook that pre-populates references for
   matching declared properties.

3. **`ExtendObjectPropertiesMatchingPatternPropertiesPostProcessor`** (`...Internal/ExtendObjectPropertiesMatchingPatternPropertiesPostProcessor.php`)
   — post-processing phase (runs after the above). For declared properties whose names match a
   pattern, transfers any `filter` from the pattern schema to the declared property. Adds a
   setter hook so that when a matching declared property is set, the base validators (including
   pattern validation) are re-executed.

### What this achieves

- A property named `"alpha"` on a schema with pattern `"^a": {"type":"integer"}` is validated
  both by its own property schema (from `properties`) and by the pattern validator at runtime.
- The pattern's filter is copied to the declared property's setter.
- The declared property's type hint in generated code is **not** touched by the pattern pipeline
  at all — neither narrowed nor widened.

### Observation: no PropertyMerger integration

`PatternPropertiesValidator` creates its `$validationProperty` via `PropertyFactory::create`
but never calls `Schema::addProperty`. The pattern's type constraint therefore never passes
through `PropertyMerger::merge`. The type of the declared property in the generated class is
purely determined by the `properties` schema definition, regardless of what the matching
`patternProperties` schema says.

---

## The overlap scenarios

### Scenario 1: Declared property type ⊂ pattern type (compatible, narrower)

```json
{
  "type": "object",
  "properties": {
    "alpha": { "type": "integer" }
  },
  "patternProperties": {
    "^a": { "type": "number" }
  }
}
```

Semantics: `alpha` must be an integer (from `properties`) AND must be a number (from
`patternProperties`). `integer` ⊂ `number`, so any integer satisfies both. The effective type
is `integer`.

**Current behaviour:** generated type hint is `int` — correct by accident (the `properties`
definition wins because `patternProperties` doesn't touch it).

**Expected behaviour:** same — `int`.

### Scenario 2: Declared property type ⊃ pattern type (compatible, broader)

```json
{
  "type": "object",
  "properties": {
    "alpha": { "type": "number" }
  },
  "patternProperties": {
    "^a": { "type": "integer" }
  }
}
```

Semantics: `alpha` must be a number AND must be an integer. Both must hold simultaneously — this
is effectively `allOf` semantics. The effective type is `integer` (the intersection).

**Current behaviour:** generated type hint is `float` (PHP type for JSON `number`). The
pattern constraint that narrows it to integer is silently ignored. A value like `3.14` would
pass the property-level type check (it is a number) but fail the pattern validator at runtime.
The property type hint in the generated model is therefore misleading: the setter accepts
`float` but the object will throw on any non-integer float.

**Expected behaviour:** generated type hint should be `int` — the intersection of `number` and
`integer`. Alternatively, a `SchemaException` should be thrown since `number`/`integer` are
not equal types (similar to how allOf contradiction is handled), but the intersection is
non-empty and unambiguous (`integer`), so narrowing is more useful.

### Scenario 3: Declared property type vs. pattern type — conflicting

```json
{
  "type": "object",
  "properties": {
    "alpha": { "type": "string" }
  },
  "patternProperties": {
    "^a": { "type": "integer" }
  }
}
```

Semantics: `alpha` must be a string AND must be an integer. These are mutually exclusive — the
schema is unsatisfiable for `alpha`.

**Current behaviour:** generated type hint is `string`. The pattern validator will always fail
at runtime for `alpha`. No generation-time error is raised.

**Expected behaviour:** `SchemaException` should be thrown at generation time — this is an
unsatisfiable schema, the same as a contradictory `allOf`. This mirrors the existing allOf
intersection detection in `PropertyMerger::applyAllOfIntersection`.

### Scenario 4: Declared property type matches pattern type exactly

```json
{
  "type": "object",
  "properties": {
    "alpha": { "type": "integer", "minimum": 10 }
  },
  "patternProperties": {
    "^a": { "type": "integer", "multipleOf": 10 }
  }
}
```

This is the scenario covered by the existing test `PatternProperties.json`. The types match;
the pattern adds additional validators (multipleOf) but the type is the same.

**Current behaviour:** generated type hint is `int` — correct. The runtime validation covers
both constraints.

**Expected behaviour:** same — no change needed for this case.

### Scenario 5: Pattern matches a composition-branch-transferred property

```json
{
  "type": "object",
  "anyOf": [
    { "properties": { "alpha": { "type": "integer" } } },
    { "properties": { "alpha": { "type": "string"  } } }
  ],
  "patternProperties": {
    "^a": { "type": "integer" }
  }
}
```

Semantics: `alpha` must satisfy `anyOf` (so `int | string`) AND must be an integer (from
`patternProperties`). The effective type is `integer`.

**Current behaviour:** `alpha` is transferred from composition branches via `PropertyMerger`
→ type becomes `int | string`. The pattern constraint is in a separate `PatternPropertiesValidator`
and is never reconciled with the merged type. Generated type hint: `int | string`.

**Expected behaviour:** `int` — the pattern's `allOf`-like constraint intersects with the
union from composition, narrowing back to `integer`.

### Scenario 6: Pattern matches a composition-branch-transferred property — conflicting

```json
{
  "type": "object",
  "anyOf": [
    { "properties": { "alpha": { "type": "integer" } } },
    { "properties": { "alpha": { "type": "string"  } } }
  ],
  "patternProperties": {
    "^a": { "type": "boolean" }
  }
}
```

Semantics: `alpha` must satisfy `anyOf` (integer or string) AND must be a boolean. No value
can satisfy all constraints simultaneously — unsatisfiable.

**Current behaviour:** generated type hint is `int | string`. No error raised.

**Expected behaviour:** `SchemaException` at generation time (empty intersection).

### Scenario 7: Undeclared property matched only by pattern — no overlap

```json
{
  "type": "object",
  "properties": {
    "beta": { "type": "string" }
  },
  "patternProperties": {
    "^a": { "type": "integer" }
  }
}
```

`beta` does not match `^a`. No overlap.

**Current behaviour:** correct — `beta` is typed `string`, pattern applies only to dynamic
keys matching `^a`.

**Expected behaviour:** same — no change needed.

---

## Why patternProperties is semantically allOf for matching declared properties

JSON Schema spec §10.3.2 (Draft 2019-09+) / §6.5 (Draft 7):

> `patternProperties` applies the sub-schema to all properties whose names match the pattern.

This applies to _all_ properties including those declared in `properties`. There is no
precedence rule between `properties` and `patternProperties`; both apply simultaneously.
The effective constraint on a matching declared property is the logical AND of the two schemas,
which is exactly what `allOf` semantics means.

This means the correct merge strategy for a pattern-vs-declared overlap is:
- Apply the **intersection** logic (same as `applyAllOfIntersection` in `PropertyMerger`).
- Throw `SchemaException` if the intersection is empty (conflicting types).
- Narrow the declared property's type to the intersection if it is a proper subset.

---

## Current architecture gap

The `PatternPropertiesValidator` holds a `$validationProperty` whose type represents the
pattern's schema type. This type is used only at runtime (template rendering). It is never
compared against the declared property's `PropertyInterface` type. Neither
`PatternPropertiesPostProcessor` nor `ExtendObjectPropertiesMatchingPatternPropertiesPostProcessor`
performs any type comparison or invokes `PropertyMerger`.

The `ExtendObjectPropertiesMatchingPatternPropertiesPostProcessor` already knows which declared
properties match which pattern (via `preg_match` in `transferPatternPropertiesFilterToProperty`).
It already iterates over `$schema->getProperties()` and over `$patternPropertiesValidators`. It
just does not compare types.

The `PatternPropertiesValidator` already exposes its `$validationProperty` via
`getValidationProperty()`. The type of this property is accessible via
`$validator->getValidationProperty()->getType(true)`.

---

## Proposed resolution

### Where to add the check

The logical home is **`ExtendObjectPropertiesMatchingPatternPropertiesPostProcessor`** (or a
new companion post processor at the same layer). It already has:
- Access to `$schema->getProperties()` (the declared/composition-transferred properties).
- Access to the `PatternPropertiesValidator` collection (via `$schema->getBaseValidators()`).
- A loop that already matches declared properties against patterns.

After confirming a pattern match on a declared property, compare the declared property's type
against the pattern's `$validationProperty` type using the same intersection logic as
`PropertyMerger::applyAllOfIntersection`.

### What the check should do

1. Get the declared property's `PropertyType` via `$property->getType(true)`.
2. Get the pattern's `PropertyType` via `$validator->getValidationProperty()->getType(true)`.
3. If either side has no type (null / truly untyped), skip — no narrowing possible.
4. Compute the intersection of the declared names and the pattern names.
5. If the intersection is empty → throw `SchemaException` (unsatisfiable, same message style
   as allOf conflict).
6. If the intersection equals the declared names (declared type already satisfies pattern) →
   no-op (existing correct case, Scenario 1 / 4).
7. If the intersection is a proper subset of the declared names → narrow the declared
   property's type to the intersection and strip the type-check validators (same as allOf
   narrowing).

### Interaction with PropertyMerger

`PropertyMerger::applyAllOfIntersection` is private and takes `PropertyInterface` objects.
Options:
- A: Expose the intersection logic as a package-private or internal method and call it from
  the post processor.
- B: Call `Schema::addProperty` with a synthetic `allOf`-sourced property carrying the pattern
  type. This is architecturally awkward because `addProperty` is designed for the initial
  build phase, not post-processing.
- C: Duplicate (inline) the narrowing logic in the post processor. This is simple but creates
  drift if `PropertyMerger` logic changes.
- D: Refactor `PropertyMerger` to expose an `applyIntersection(PropertyInterface $property, PropertyType $incoming)`
  public method that both `applyAllOfIntersection` and the new post processor can call.

Option D keeps `PropertyMerger` as the single place that owns type-merge logic.

### Timing: post-processor vs. schema-processing phase

`ExtendObjectPropertiesMatchingPatternPropertiesPostProcessor` runs _after_
`transferComposedPropertiesToSchema` has already run (composition properties are already
merged into the schema's property slots via `PropertyMerger`). This means by the time the
post processor fires, Scenario 5 (composition + pattern overlap) already has its merged
`int | string` type in the property slot — and the pattern intersection narrows it further.
This is the correct ordering: composition widening first, then pattern narrowing.

---

## Edge cases

### Nullable declared property vs. non-nullable pattern

```json
{
  "properties": { "alpha": { "type": ["integer", "null"] } },
  "patternProperties": { "^a": { "type": "integer" } }
}
```

Declared type: `int | null`. Pattern type: `int`. Intersection of names: `integer`.
The pattern does not explicitly say `"not null"` — it simply adds the constraint `type: integer`
to matching props. A null value would fail the pattern validator at runtime regardless.

Decision needed: should the generated type be `int` (strict intersection) or `int | null`
(preserve nullable from declared side)? The `allOf` precedent in `PropertyMerger` preserves
nullable when one side is explicitly nullable and the other does not explicitly deny it.
Applying the same rule here: `int | null` — preserve nullable.

### Multiple patterns matching the same declared property

```json
{
  "properties": { "alpha": { "type": "number" } },
  "patternProperties": {
    "^a":  { "type": "integer" },
    "alp": { "type": "string"  }
  }
}
```

Both patterns match `alpha`. The effective constraint is `number AND integer AND string`.
The intersection across all three is empty — `SchemaException`.

The implementation should accumulate intersections across all matching patterns before deciding
(not just pair-wise between the declared property and one pattern).

### Pattern with no type constraint

```json
{
  "patternProperties": {
    "^a": { "minLength": 5 }
  }
}
```

No `type` in the pattern schema → `$validator->getValidationProperty()->getType(true)` returns
`null`. Skip type comparison (rule 3 above). No narrowing.

### Pattern matching only composition-transferred properties (no root declared property)

A property that exists only in composition branches (no root `properties` entry) still lands in
`$schema->getProperties()` after `transferComposedPropertiesToSchema`. The post processor's loop
over `$schema->getProperties()` will find it. The same logic applies — but note that the
composition already ran `PropertyMerger`, so the property's type may already be a union. The
pattern then intersects with that union.

However, there is one subtlety: the composition transfer happens inside `onAllPropertiesResolved`
callbacks (async). The post processors run after all schemas are resolved (`ModelGenerator` calls
post processors after the `RenderQueue` is built). This should be fine — by post-processor time,
all `onAllPropertiesResolved` callbacks have fired.

Verify: does the post processor see composition-transferred properties? Currently
`ExtendObjectPropertiesMatchingPatternPropertiesPostProcessor` calls `$schema->getProperties()`
which returns `$this->properties` — the same array that `addProperty` writes to. Yes, it would
see composition-transferred properties.

But there is a further subtlety: `PatternPropertiesPostProcessor` (the other internal one)
restricts its "matching" check to `$schemaProperties = array_keys($json['properties'] ?? [])`:

```php
if (in_array($property->getName(), $schemaProperties) &&
    preg_match('/' . addcslashes($validator->getPattern(), '/') . '/', $property->getName())
) {
```

This means it only considers declared properties (from JSON `properties`), not
composition-transferred ones. The new type-check should not have that restriction.

---

## Affected files

| File | Change needed |
|---|---|
| `src/SchemaProcessor/PostProcessor/Internal/ExtendObjectPropertiesMatchingPatternPropertiesPostProcessor.php` | Add type intersection check after the filter-transfer loop |
| `src/Utils/PropertyMerger.php` | Expose intersection logic (option D) |
| `tests/Schema/PatternPropertiesTest/` | New schema fixtures for each scenario |
| `tests/Basic/PatternPropertiesTest.php` | New test methods covering each scenario |
| `docs/source/` | Document that patternProperties narrows declared property types |

---

## Decisions and implementation status

1. **SchemaException for conflicts** — throw `SchemaException`. Implemented.

2. **Nullable preservation** — strict intersection (no nullable preservation). The pattern
   constraint is definitive. Implemented via `$preserveNullable = false` in `narrowToIntersection`.

3. **Scope** — applied to all `$schema->getProperties()` entries (declared and composition-
   transferred). Implemented.

4. **Multiple matching patterns** — not covered (edge case deemed not worth the complexity).

5. **`PropertyMerger` refactor** — extracted `narrowToIntersection` as a public method.
   `applyAllOfIntersection` delegates to it. Both paths share the same logic.

### Additional implementation notes

- **`int`/`float` subtype**: `integer` ⊂ `number` in JSON Schema. PHP stores these as `int` and
  `float` — disjoint names. A `computeDeclaredIntersection` helper resolves this by treating
  `int` as a valid element of the `float` set, yielding `int` rather than an empty intersection.

- **`IntToFloatCastDecorator` removal**: when a property is narrowed from `float` to `int`, the
  `IntToFloatCastDecorator` (added by `NumberProcessor`) must be stripped. Added
  `filterDecorators` to `PropertyInterface`, `Property`, and `PropertyProxy` for this.

- **Transforming filter avoidance**: pattern schemas with a `filter` key are skipped in the type
  intersection check. The filter transforms the PHP type (e.g. `string` → `DateTime`); comparing
  the schema-declared type against a filter-transformed declared property type would produce a
  false conflict. The type is read from the raw JSON `type` key via `resolvePatternTypeFromJson`,
  not from `getValidationProperty()->getType(true)`, to avoid post-filter type contamination.

## Implementation complete

Files changed:
- `src/Utils/PropertyMerger.php` — `narrowToIntersection` public method; `computeDeclaredIntersection` helper; `filterDecorators` call for `int`/`float` narrowing
- `src/Model/Property/PropertyInterface.php` — `filterDecorators` method added
- `src/Model/Property/Property.php` — `filterDecorators` implementation
- `src/Model/Property/PropertyProxy.php` — `filterDecorators` delegation
- `src/SchemaProcessor/PostProcessor/Internal/ExtendObjectPropertiesMatchingPatternPropertiesPostProcessor.php` — `applyPatternPropertiesTypeIntersection` method; `resolvePatternTypeFromJson` helper
- `tests/Schema/PatternPropertiesTest/*.json` — 7 new schema fixtures
- `tests/Basic/PatternPropertiesTest.php` — 7 new test methods
