# Implementation Plan: Base-schema property precedence vs. composition type widening

## Goal

When a property is defined in the root `properties` section of an object schema AND appears again
in `anyOf`/`oneOf` composition branches, the root definition must be authoritative and must not
be widened by composition branches. Only properties that appear exclusively in composition branches
(i.e. not in root `properties`) should be subject to union widening.

---

## Prerequisite: design decision

Before starting implementation, confirm the answer to the open question from the analysis:

> When an anyOf/oneOf composition branch defines a type that contradicts the root `properties`
> type (e.g. root says `integer`, branch says `string`), should the generator emit a warning or
> silently skip the widening?

**Default assumption:** silently skip (the JSON Schema is technically valid — the branch
constraint can simply never be satisfied given the root constraint, which is the author's choice).
Flip this assumption if a warning is preferred.

---

## Phase 1 — Track property origin in `Schema`

**Tasks:**

1. Add a private `array $propertyOrigin = []` map to `Schema` — keys are property names, values
   are `string|null` (the `compositionProcessor` FQCN or `null` for root properties).
2. In `addProperty`, record the origin when a property is first registered:
   ```php
   $this->propertyOrigin[$property->getName()] = $compositionProcessor;
   ```
3. No behaviour change yet — just tracking.

**Acceptance:** `$propertyOrigin` is populated correctly for both root and composition-sourced
properties.

---

## Phase 2 — Guard widening against root-anchored properties

**Tasks:**

1. In the widening path of `addProperty` (the `else` branch where the property already exists),
   read the stored origin of the existing property:
   ```php
   $existingOrigin = $this->propertyOrigin[$property->getName()];
   ```
2. If `$existingOrigin === null` (root property) and the incoming property comes from a non-`allOf`
   composition processor, skip widening and return early.
3. Keep the existing `allOf` conflict-detection path unchanged — it correctly fires when a root
   property conflicts with an allOf branch.

**Acceptance:** The schema in the analysis example produces `setAge(int $age)`, not
`setAge(int | string $age)`.

---

## Phase 3 — Tests

**New test schemas** (in a new `tests/Schema/BasePropertyPrecedenceTest/` directory):

| File | Description |
|---|---|
| `RootAndAnyOfSameType.json` | Root `integer`, anyOf branches both `integer` — no change |
| `RootAndAnyOfConflictingType.json` | Root `integer`, one anyOf branch `string` — root wins |
| `RootAndOneOfConflictingType.json` | Root `integer`, oneOf branches `integer`+`string` — root wins |
| `RootPropertyExclusiveToOneBranch.json` | Root `string`, branch 1 has unrelated prop, branch 2 has same prop `string` — root preserved (not widens to `mixed`) |
| `OnlyCompositionBranches.json` | No root property, anyOf branches `integer`+`string` — widens to union (regression guard) |
| `RootAndAllOfSameType.json` | Root `integer`, allOf branch `integer` — no conflict |
| `RootAndAllOfConflictingType.json` | Root `integer`, allOf branch `string` — SchemaException |

**New test class** `tests/BasePropertyPrecedenceTest.php`:

- `testRootPropertyIsNotWidenedByAnyOfBranch`
- `testRootPropertyIsNotWidenedByOneOfBranch`
- `testRootPropertyExclusiveToOneBranchIsNotWidenedToMixed` — the `exclusiveBranchPropertyNeedsWidening` path
- `testCompositionOnlyPropertyIsStillWidened` (regression)
- `testAllOfConflictWithRootThrowsSchemaException`
- `testAllOfSameTypeAsRootIsAccepted`

---

## Phase 4 — Documentation update

Update `docs/source/combinedSchemas/anyOf.rst` and `oneOf.rst` with a note:

> When a property is also defined in the root `properties` section, the root type definition
> is authoritative. Composition branches may add further constraints but will not widen the
> property's type.

Also reference from the type-widening section added in the composition-docs topic.

---

## Status

- [x] Phase 1 — Track property origin (`$rootRegisteredProperties` in `Schema.php`)
- [x] Phase 2 — Guard widening against root properties (guard + warning in `addProperty` else-branch; `setGeneratorConfiguration` setter; wired in `SchemaProcessor.php`)
- [x] Phase 3 — Tests (7 schemas in `tests/Schema/BasePropertyPrecedenceTest/`, 7 tests in `tests/Objects/BasePropertyPrecedenceTest.php`, all green)
- [x] Phase 4 — Docs (added `.. note::` blocks to `anyOf.rst` and `oneOf.rst`)
- [x] Phase 5 — allOf intersection (conflict detection + type narrowing; 5 additional schemas; `applyAllOfIntersection` with explicit-nullable preservation)
- [x] Phase 6 — Refactor: merge logic moved to `src/Utils/PropertyMerger.php`; `Schema::addProperty` delegates to it; `stripTypeCheckValidators` inlined into `PropertyMerger`
