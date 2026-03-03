# Phase 6 — Property Merger: Deep Analysis

This document extends the original `implementation-plan.md` Phase 6 section with a comprehensive
treatment of every edge case, invalid-schema pattern, and architectural interaction that the
composition-merger must handle correctly.

---

## 1. Type-Merging Decision Table

When `Schema::addProperty()` receives a property whose name already exists, the correct action
depends on the combination of existing and incoming types.

| Existing type | Incoming type | Correct action |
|---|---|---|
| `int` | `int` | No-op (same type, no widening needed) |
| `int` | `string` | Widen to `int\|string` |
| `int` | `null` type | Set `nullable=true` on existing; no name change |
| `int\|string` | `bool` | Widen further to `int\|string\|bool` |
| `int\|string` | `int` | No-op (already contained) |
| has `getNestedSchema()` | anything | No-op (merged-property logic takes precedence) |
| anything | has `getNestedSchema()` | No-op (same) |
| null (no type) | `int` | No-op (can't safely merge into untyped property) |
| `int` | null (no type) | No-op |

---

## 2. `null` as a Type Name

`"type": ["string", "null"]` causes `NullProcessor` to contribute `'null'` to `allowedPropertyTypes`.
The same can arrive in a composition branch: `{ "type": "null" }` or an implicit null from
`nullable: true`. In both cases `'null'` must be **stripped from the type name list and converted to
`nullable = true`** rather than kept as a type name, for two reasons:

1. `RenderHelper::getType()` already appends `'null'` when `$nullable === true`. Keeping it as a
   type name as well produces `string|null|null` in the emitted output.
2. PHP does not accept `null` as a union member when the property is already nullable via `?`.

**Pattern (already established in Phase 4, must be replicated in Phase 6):**
```php
$hasNull = in_array('null', $mergedNames, true);
$nonNullNames = array_values(array_filter($mergedNames, fn($t) => $t !== 'null'));
$merged = new PropertyType($nonNullNames, $hasNull ? true : null);
```

---

## 3. Nested-Schema Guard

`AbstractComposedValueProcessor::transferPropertyType()` already bails out when any composition
branch has a nested schema (lines ~197–203). The `Schema::addProperty()` merger must enforce the
same guard before attempting to merge types. If either the **existing** or **incoming** property
carries a nested schema, the duplicate is a **merged-object property** — the existing merged-object
logic owns it, and Phase 6 must not interfere.

```php
if ($existing->getNestedSchema() !== null || $property->getNestedSchema() !== null) {
    return $this;  // leave merged-object logic in charge
}
```

Failure to add this guard risks overwriting the type of a merged object property with a plain scalar
union type, breaking nested-object access.

---

## 4. Input vs Output Type: Both Must Be Merged

The original Phase 6 plan calls `$existing->getType()` without an argument, which (after Phase 5)
returns the *union* of input+output types for filter properties. This is wrong for the merger: both
the **input type** (setter, `getType(false)`) and the **output type** (getter, `getType(true)`)
must be merged independently.

**Example:**
```json
"oneOf": [
  { "properties": { "date": { "type": "string", "format": "date-time" } } },
  { "properties": { "date": { "type": "string" } } }
]
```
- Branch 1 input: `string`, output: `DateTime`
- Branch 2 input: `string`, output: `string`
- Merged input: `string` (same, no widening)
- Merged output: `DateTime|string`

If the merger uses `getType()` (no arg) it will see the Phase-5 union `string|DateTime` for
branch 1, and conflate the input/output distinction, creating an incorrect result.

**Correct pattern:**
```php
$existingInput  = $existing->getType(false);  // input type (setter)
$existingOutput = $existing->getType(true);   // output type (getter/storage)
$incomingInput  = $property->getType(false);
$incomingOutput = $property->getType(true);
```

Note: after Phase 5, `getType(false)` for a filter property now returns a *constructed* union on
every call (not stored), so comparing `->getNames()` is safe. For the merger, however, we want the
raw stored `$this->type` and `$this->outputType`, not the Phase-5 on-the-fly union. This means the
merger should use `getType(true)` (output type) as the canonical stored output, and for the input
type use the stored `$this->type` — which is always the raw input type even for filter properties.
Since `getType(false)` returns the Phase-5 union when types differ, the merger cannot rely on it for
component extraction.

**Implication:** Phase 6 must read `getType(true)` for the output and `getType(false)` for the
setter-facing type. Because the Phase-5 union is synthesised on-the-fly, the real stored type
components are only accessible via `getType(true)` (output) and the internal `$type` field. This
creates a dependency: Phase 6 needs to be aware of Phase 5's behaviour and call sites carefully.

The safest approach is to store and compare `getType(true)` (output, always the canonical stored
value) and reconstruct the setter union after merging:

```php
// Use stored input type directly from getType(true) with outputType=false would return union.
// Instead: compare the output types (getType(true)) for each property.
// After merging output types, the Phase-5 getType(false) logic will synthesise the correct
// setter union automatically from the updated stored types.
$existing->setType(
    $existing->getType(true),        // keep stored input type as-is (Phase 5 handles setter)
    new PropertyType($mergedOutputNames, $nullable),
);
```

---

## 5. Validator Filtering: Scope and Safety

`cloneTransferredProperty()` already strips most validators from branch copies before they arrive at
`addProperty()`. The merger's `filterValidators()` call is a safety net for the case where a
type-check validator survived the transfer. Only `TypeCheckInterface` implementations must be
removed; all other validators on the existing root property (e.g. `PropertyTemplateValidator`,
`FilterValidator`) must survive.

Classes implementing `TypeCheckInterface`:
- `TypeCheckValidator` (`src/Model/Validator/TypeCheckValidator.php`)
- `MultiTypeCheckValidator` (`src/Model/Validator/MultiTypeCheckValidator.php`)
- `ReflectionTypeCheckValidator` (if present)

The `filterValidators()` call should only run when a type widening actually happened (i.e. inside
the `if ($mergedNames !== $existingType->getNames())` guard), not on every duplicate.

---

## 6. `allOf` Semantics

For `allOf`, all branches always apply — a property that appears in multiple branches with
*different* types is a **contradictory schema** (`value` must be both `int` AND `string`
simultaneously, which is impossible). Phase 6 **must throw a `SchemaException`** for this case.

Silently generating code (even a widened union) for a contradictory schema is wrong: it produces a
model that the developer will use, unaware that their schema is broken. The generated class would
accept values that no branch can actually validate, and all provided values would fail validation at
runtime. Failing loudly at generation time surfaces the problem immediately.

Detection: when the same property name arrives via an `allOf` branch and the merger would widen the
type (i.e. the incoming type is not already contained in the existing type), throw:

```php
if ($compositionType === 'allOf') {
    throw new SchemaException(
        "Property '{$property->getName()}' is defined with conflicting types across allOf branches. " .
        "allOf requires all constraints to hold simultaneously, making this schema unsatisfiable."
    );
}
```

The `allOf` / `anyOf` / `oneOf` context must be passed into `addProperty()`, or detected from the
calling `AbstractComposedValueProcessor` subclass, to apply this check only to `allOf`.

---

## 7. `onResolve` Callback Counter

`Schema::addProperty()` registers an `onResolve` callback on the first-seen property to decrement a
counter (`$this->resolvedProperties`). When the same property name arrives a second time as a
duplicate, the merger must **not** register another callback. The counter tracks the number of
distinct properties, and registering twice would require two resolutions for what is logically one
property, stalling schema resolution.

The existing code structure already avoids this: the duplicate enters the `else` branch and the
callback registration in the `if` branch is bypassed. Phase 6 only adds type-widening logic to the
`else` branch and must not add any `onResolve` call there.

---

## 8. Multi-Branch Accumulation (> 2 branches)

For 3+ branches all defining the same property with different types:

```json
"oneOf": [
  { "properties": { "x": { "type": "integer" } } },
  { "properties": { "x": { "type": "string" }  } },
  { "properties": { "x": { "type": "boolean" } } }
]
```

Branches are processed sequentially by `transferComposedPropertiesToSchema()`. Each triggers
`addProperty()`:

1. Branch 1: `x: int` → stored as `PropertyType(['int'], true)`
2. Branch 2: `x: string` → merger fires, `int` + `string` = `PropertyType(['int', 'string'], true)`
3. Branch 3: `x: bool` → merger fires again, `['int', 'string']` + `['bool']` = `PropertyType(['int', 'string', 'bool'], true)`

`PropertyType`'s constructor deduplication via `array_unique` handles the case where the same type
appears in multiple branches. No special multi-branch code is required; accumulation is naturally
correct.

---

## 9. Compositions Within Compositions

For deeply nested compositions (e.g. a `oneOf` branch that itself contains an `anyOf`), the inner
composition resolves its branch properties into the inner branch's schema before the outer
`transferComposedPropertiesToSchema()` runs. By the time Phase 6 merging occurs at the outer level,
the inner composition has already resolved to a concrete type. Phase 6 handles these cases
transparently because it only operates on the final transferred `PropertyType`.

---

## 10. Multiple Root-Level Compositions

A schema can have both a `oneOf` and an `anyOf` at the root:

```json
{
  "oneOf": [ { "properties": { "a": { "type": "integer" } } }, ... ],
  "anyOf": [ { "properties": { "a": { "type": "string" }  } }, ... ]
}
```

Each composition creates its own `ComposedPropertyValidator` and calls
`transferComposedPropertiesToSchema()` independently. Both trigger `addProperty()` for `a`. The
second call sees the duplicate and Phase 6 merges. This is correct: both composition constraints
apply, and the root property must accept both types.

---

## 11. Identified Gaps in Original Phase 6 Plan

| # | Gap | Correct approach |
|---|---|---|
| 1 | Uses `$existing->getType()` (no arg) — post-Phase-5 returns synthesised union | Use `getType(true)` for output type; leave `getType(false)` to Phase-5 synthesis |
| 2 | No nested-schema guard | Add `getNestedSchema()` check before any merge attempt |
| 3 | `'null'` as type name not handled | Strip `'null'`, convert to `nullable=true` (Task 4.2 pattern) |
| 4 | `filterValidators` runs unconditionally in plan pseudocode | Only run inside the "types changed" guard |
| 5 | Task 6.2 `transferPropertyType()` still uses `->getName()` (single-name) | Must use `->getNames()` to correctly handle existing union types when combining branches |
| 6 | No test for filter-property cross-branch merge | Add to Task 6.3 test cases |
| 7 | No test for 3-branch accumulation | Add to Task 6.3 test cases |
| 8 | No test for explicit `null` in cross-typed branch | Add to Task 6.3 test cases |

---

## 12. Corrected Task 6.1 Algorithm

```php
} else {
    $existing = $this->properties[$property->getName()];

    // Guard: nested-object merging is handled by the merged-property system; don't interfere.
    if ($existing->getNestedSchema() !== null || $property->getNestedSchema() !== null) {
        return $this;
    }

    // Use getType(true) to access the raw stored output type (Phase-5 getType(false) returns
    // a synthesised union and cannot be decomposed back into components).
    $existingOutput = $existing->getType(true);
    $incomingOutput = $property->getType(true);

    if (!$existingOutput || !$incomingOutput) {
        return $this;  // Can't merge when either side has no type
    }

    $allNames = array_merge($existingOutput->getNames(), $incomingOutput->getNames());

    // Normalise: strip 'null' → nullable flag; PropertyType constructor deduplicates the rest.
    $hasNull = in_array('null', $allNames, true);
    $nonNullNames = array_values(array_filter($allNames, fn(string $t): bool => $t !== 'null'));

    if (!$nonNullNames) {
        return $this;  // Degenerate: only null types, nothing to merge
    }

    // PropertyType constructor deduplicates, so compare post-construction.
    $mergedType = new PropertyType($nonNullNames, $hasNull ? true : null);

    if ($mergedType->getNames() === $existingOutput->getNames()
        && $mergedType->isNullable() === $existingOutput->isNullable()
    ) {
        return $this;  // Types identical after dedup; no change needed
    }

    // Widen: setType with the existing stored input type unchanged (Phase 5 synthesises the
    // setter union from the stored input/output types automatically).
    $existing->setType(
        $existing->getType(true) !== $existingOutput ? $existingOutput : null,
        $mergedType,
    );

    // Strip any surviving type-check validators; branch sub-classes enforce constraints.
    $existing->filterValidators(
        static fn(Validator $v): bool =>
            !($v->getValidator() instanceof TypeCheckInterface),
    );
}
```

> **Note on `setType` arguments:** The first argument to `setType()` is the *input* type. For
> Phase 6 composition properties the input type is always the same as the output type (no
> filter transformation at the root level). Pass `null` as input to let Phase-5 synthesis handle
> the setter, or pass `$mergedType` as both input and output for the straightforward case.
> The exact call must be verified against `Property::setType()` behaviour during implementation.

---

## 13. Corrected Task 6.2: `transferPropertyType()` Must Use `getNames()`

The plan pseudocode for Task 6.2 calls `$p->getType()->getName()` (singular), which returns only
the first element of a union type. If a `CompositionPropertyDecorator` already carries a union
`PropertyType` (e.g. from Phase 4 or Phase 5), this silently truncates it. Replace with
`$p->getType()->getNames()` and flatten via `array_merge`:

```php
$allNames = array_merge(...array_map(
    static fn(CompositionPropertyDecorator $p): array =>
        $p->getType() ? $p->getType()->getNames() : [],
    $compositionProperties,
));
$nonEmpty = array_values(array_filter(array_unique($allNames)));

if (!$nonEmpty || $this instanceof NotProcessor) {
    return;
}

$hasNull = in_array('null', $nonEmpty, true);
$nonNullNames = array_values(array_filter($nonEmpty, fn($t) => $t !== 'null'));
$nullable = count($compositionProperties) > count($nonNullNames) || $hasNull;

$property->setType(new PropertyType($nonNullNames ?: $nonEmpty, $nullable ? true : null));
```

---

## 14. Additional Test Cases for Task 6.3

Beyond the original plan, the following scenarios need dedicated test cases:

| Scenario | Schema pattern | Expected |
|---|---|---|
| 3-branch accumulation | `oneOf` with `int`, `string`, `bool` for same prop | Union `int\|string\|bool` |
| Explicit null branch | `oneOf` with `int` and `null` type | `?int`, nullable=true, no union |
| Filter property in one branch | `dateTime` in branch 1, plain `string` in branch 2 | Output type `DateTime\|string` |
| Same type both branches | `int` and `int` | No widening, stays `?int` |
| `anyOf` parallel | same as `oneOf` cases above | Same union output |
| Nested object branch | `object` + `string` | No type merge; merged-object logic owns it |
| No-type branch | typed branch + untyped branch | No widening (can't merge with no type) |
