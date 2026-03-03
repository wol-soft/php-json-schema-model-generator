# Issue #110 — Implementation Plan

## Objective

The generator currently lacks native PHP union type support. This gap manifests in three distinct
code paths, all of which currently fall back to either no type hint or a narrowed/incorrect one:

| Scenario | Source | Current output | Correct output |
|---|---|---|---|
| **A** — `"type": ["number","string","array"]` | `MultiTypeProcessor` | no native hint (`assertNull`) | `float\|string\|array` |
| **B** — transforming filter (`string` → `DateTime`) | `FilterProcessor` + `Property::getType()` | no native hint | `string\|DateTime` |
| **C** — same property name in multiple composition branches with different types | `Schema::addProperty()` | first-branch type only (`?int`) | `int\|string` |

All three scenarios already produce correct DocBlock annotations via the decorator chain; only the
native PHP type hint path (`RenderHelper::getType()` → `Property::getType()` → `PropertyType`) is
broken. The fix is a single shared infrastructure upgrade with three consumer-side changes.

Related files already committed:
- `src/Templates/Validator/ComposedItem.phptpl` — issue #114 fix (deferred null-setting)
- `.claude/issues/110/analysis.md` — design analysis and option evaluation
- `.claude/issues/110/union-type-preparation.md` — 6-step preparatory work
- `.claude/issues/110/union-type-test-coverage.md` — test coverage requirements

---

## High-level phases

```
Phase 1  — PropertyType union support          (pure data model, zero behaviour change)
Phase 2  — Render pipeline union output        (infrastructure; existing tests unchanged)
Phase 3  — Test helper extension               (tooling for new assertions)
Phase 4  — Scenario A: MultiTypeProcessor      (JSON Schema "type" arrays)
Phase 5  — Scenario B: filter/transformer      (input type ≠ output type)
Phase 6  — Scenario C: composition merger      (cross-branch same-name properties)
Phase 7  — ComposedItem cleanup                (#114 workaround removed)
Phase 8  — Test suite update                   (assertNull → union assertions)
Phase 9  — Typed property declarations         (native type hint on protected $x)
Phase 10 — Remove getName() compat shim        (migrate all callers; delete the method)
```

Phases 1–3 are purely additive (no existing test should break).
Phases 4–6 are independent consumers of the union infrastructure; each changes generated output
for its scenario only and can be implemented and merged in any order after Phases 1–3.
Phase 7 depends on Phase 6. Phase 8 depends on Phases 3–7.
Phase 9 depends on Phases 1–2 and can otherwise proceed independently; its test updates (Task 9.3)
should be combined with Phase 8 commits since they touch the same test files.
Phase 10 depends on all previous phases and is a standalone cleanup commit.

---

## Phase 1 — Extend `PropertyType` to support multiple type names

**Goal:** Make `PropertyType` able to carry n ≥ 1 type names while remaining backward-compatible
with all existing call sites that pass a single string.

### Task 1.1 — Add `string[]` storage to `PropertyType`

File: `src/Model/Property/PropertyType.php`

- Change constructor parameter from `string $name` to `string|array $name`
- Store as `private array $names` (always an array, normalised in constructor)
- Keep `getName(): string` returning `$this->names[0]` — backward compat for all existing callers
- Add `getNames(): string[]` returning the full array
- Add `isUnion(): bool` returning `count($this->names) > 1`

No callers change. All existing `new PropertyType('string')` constructions continue to work.
All existing `->getName()` calls continue to work and return the same single string.

**New unit tests** (add to a new `tests/Model/Property/PropertyTypeTest.php`):

```php
// Single-name construction (backward compat)
$t = new PropertyType('string');
assert($t->getNames() === ['string']);
assert($t->getName() === 'string');
assert($t->isUnion() === false);

// Multi-name construction
$t = new PropertyType(['int', 'string']);
assert($t->getNames() === ['int', 'string']);
assert($t->getName() === 'int');   // first element
assert($t->isUnion() === true);

// Nullability passthrough unchanged
$t = new PropertyType('int', true);
assert($t->isNullable() === true);
$t = new PropertyType(['int', 'string'], null);
assert($t->isNullable() === null);
```

**Acceptance:** All existing tests pass unchanged. New unit tests for `PropertyType` pass.

---

## Phase 2 — Update the render pipeline to emit union syntax

**Goal:** `RenderHelper::getType()` must produce `int|string|null` (long form) when
`PropertyType::isUnion()` is true. The `?` shorthand is only valid for a single type.

### Task 2.1 — Update `RenderHelper::getType()` for union output

File: `src/Utils/RenderHelper.php`

Current code emits `"$nullable{$type->getName()}"` — always a single type. Replace with:

```php
$nullable = ($type->isNullable() ?? $this->isPropertyNullable($property, $outputType)) || $forceNullable;
$names = $type->getNames();

if ($type->isUnion()) {
    if ($nullable) {
        $names[] = 'null';
    }
    return implode('|', array_unique($names));
}

// Single type — preserve ?Type shorthand
return ($nullable ? '?' : '') . $names[0];
```

**Regression guard** — single-type nullable must still emit `?string`, not `string|null`. This is
verified implicitly by all existing tests that call `getReturnType(...)->getName()` and expect a
`ReflectionNamedType` (not `ReflectionUnionType`). No new test is needed here because if `?string`
were accidentally changed to `string|null`, the existing `->getName()` calls would fatal-error on
a `ReflectionUnionType`, which would surface immediately.

**Acceptance:** All existing tests pass unchanged. The `isUnion()` branch is never entered because
no existing `PropertyType` carries multiple names yet.

### Task 2.2 — Remove `explode`/`implode` round-trip in `RenderHelper::getTypeHintAnnotation()`

File: `src/Utils/RenderHelper.php`

**Current code** (line 122):
```php
return implode('|', array_unique(explode('|', $typeHint)));
```

`$typeHint` is the string returned by `$property->getTypeHint()`, which is itself assembled by
the decorator chain via `implode('|', ...)`. Splitting it back to deduplicate is a string
round-trip. This pattern also means `|null` must be guarded with another `explode` check.

**With `PropertyType::getNames()` available** (Task 1.1), the nullability guard and deduplication
can work on arrays throughout:

```php
public function getTypeHintAnnotation(
    PropertyInterface $property,
    bool $outputType = false,
    bool $forceNullable = false,
): string {
    $typeHint = $property->getTypeHint($outputType);
    $hasDefinedNullability = ($type = $property->getType($outputType)) && $type->isNullable() !== null;

    $nullable = ($hasDefinedNullability && $type->isNullable())
        || (!$hasDefinedNullability && $this->isPropertyNullable($property, $outputType))
        || $forceNullable;

    $parts = array_unique(explode('|', $typeHint));

    if ($nullable && !in_array('mixed', $parts, true) && !in_array('null', $parts, true)) {
        $parts[] = 'null';
    }

    return implode('|', $parts);
}
```

The `explode` on `$typeHint` remains here because `getTypeHint()` itself returns a `string` built
by the decorator chain. Fully eliminating this split would require `getTypeHint()` to return
`string[]` instead — a broader refactor outside the scope of this issue. What this task does:

1. Replaces the `strstr($typeHint, 'mixed')` string scan with an `in_array` on the split `$parts`
   (consistent with how the null check works)
2. Moves the `array_unique` deduplication to operate on `$parts` directly, before `implode`
3. Guards `|null` addition with `!in_array('null', $parts)` to prevent `null|null` when the
   PropertyType already includes `null` as one of its names (Scenario A: `"type": ["string","null"]`)

**Acceptance:** All existing tests pass unchanged. The output string is identical for all current
inputs (no existing property carries `null` as a type name in its `getTypeHint()` output).

---

## Phase 3 — Extend test assertion helpers

**Goal:** Add `getReturnTypeNames()` and `getParameterTypeNames()` to
`AbstractPHPModelGeneratorTestCase` so new tests can assert on union types without calling
`->getName()` which fatal-errors on a `ReflectionUnionType`.

### Task 3.1 — Add union-aware type name helpers

File: `tests/AbstractPHPModelGeneratorTestCase.php`

```php
/**
 * Returns all type names from a native PHP return type hint.
 * Handles both ReflectionNamedType (single) and ReflectionUnionType (union).
 * Null is represented as the string 'null'. Returns [] if no hint exists.
 */
protected function getReturnTypeNames($object, string $method): array
{
    $type = $this->getReturnType($object, $method);
    if (!$type) {
        return [];
    }
    if ($type instanceof \ReflectionUnionType) {
        return array_map(fn(\ReflectionNamedType $t) => $t->getName(), $type->getTypes());
    }
    /** @var \ReflectionNamedType $type */
    $names = [$type->getName()];
    if ($type->allowsNull() && $type->getName() !== 'null') {
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
        return array_map(fn(\ReflectionNamedType $t) => $t->getName(), $type->getTypes());
    }
    /** @var \ReflectionNamedType $type */
    $names = [$type->getName()];
    if ($type->allowsNull() && $type->getName() !== 'null') {
        $names[] = 'null';
    }
    return $names;
}
```

Use `assertEqualsCanonicalizing` in consuming tests since union type element order is unspecified.

**Acceptance:** All existing tests pass unchanged. New helpers are unused at this point.

---

## Phase 4 — Scenario A: `MultiTypeProcessor` native type hint

**Goal:** Properties declared with `"type": ["number","string","array"]` (JSON Schema type arrays)
should emit a native PHP union type hint. Currently `MultiTypeProcessor` creates a `Property` with
`$type = null` and uses only `TypeHintDecorator` for DocBlocks, producing no native hint.

### How `MultiTypeProcessor` currently works

1. Creates a property with no `PropertyType` (`$type = null`)
2. Processes each type via its own sub-processor (e.g. `NumberProcessor`, `StringProcessor`)
3. Strips individual `TypeCheckInterface` validators from sub-properties
4. Collects their `getTypes()` into `$allowedPropertyTypes`
5. After all sub-properties resolve, adds a single `MultiTypeCheckValidator($allowedPropertyTypes, ...)`
   and a `TypeHintDecorator` for the DocBlock union string

The validator already generates the correct multi-clause `is_X` check
(`!is_float($value) && !is_string($value) && !is_array($value) && $value !== null`).
The only missing piece is a `PropertyType` carrying all the type names so the native hint path works.

### Task 4.1 — Set a union `PropertyType` on the property in `MultiTypeProcessor`

File: `src/PropertyProcessor/Property/MultiTypeProcessor.php`

After `$this->allowedPropertyTypes` is fully populated (inside the final `onResolve` callback,
after all sub-properties resolve), set the property type:

```php
// Existing: add TypeHintDecorator + MultiTypeCheckValidator
// Add after those:
$property->setType(
    new PropertyType($this->allowedPropertyTypes, null),
    // output type same as input type — no filter transformation here
    new PropertyType($this->allowedPropertyTypes, null),
);
```

The `null` nullability defers to `RenderHelper::isPropertyNullable()` (same behaviour as before for
optional/required determination). `RenderHelper::getType()` will now call `isUnion() = true` and
emit `float|string|array` or `float|string|array|null`.

**Note:** `"null"` as a type element in `$allowedPropertyTypes` (from `"type": ["string","null"]`)
must be handled: do not emit `null` as a type name in the PHP union hint — that would produce
`string|null|null` after the nullable check. Instead, if `'null'` is in the names, set
`$nullable = true` on the `PropertyType` and remove `'null'` from `$names` before constructing
the `PropertyType`.

**New functional tests** (add to `tests/Objects/MultiTypePropertyTest.php`):

- Passing a `float` to a union-typed setter no longer throws (was: untyped, any value accepted;
  now: typed, verify `float` is in the union). This is already covered by the existing
  `testValidProvidedValuePassesValidation` data provider (which passes floats, strings, arrays) —
  confirm these continue to pass after Task 4.1.
- Passing a `bool` to a union-typed setter still throws (already covered by
  `testInvalidProvidedValueThrowsAnException`).
- Add a test that the property itself (via Reflection on the object) has no type before Phase 9
  but has a union type after Phase 9 (Task 9.3 handles this).

**Acceptance:** `MultiTypePropertyTest::testOptionalMultiTypeAnnotation` and
`testRequiredMultiTypeAnnotation` change from `assertNull(getReturnType(...))` to non-null
`ReflectionUnionType` assertions (Task 8.1 updates these). All existing functional tests
(valid/invalid values) continue to pass. All other tests pass unchanged.

### Task 4.2 — Handle `"type": ["string","null"]` — explicit null in type array

The `NullProcessor` contributes the type name `'null'` to `$allowedPropertyTypes`. The
`MultiTypeCheckValidator` already handles this by setting `$allowImplicitNull = false`.

For the native hint, `'null'` must be converted to nullability rather than a type name. In Task 4.1,
before constructing the `PropertyType`:

```php
$hasNull = in_array('null', $this->allowedPropertyTypes, true);
$nonNullTypes = array_values(array_filter(
    $this->allowedPropertyTypes,
    fn(string $t): bool => $t !== 'null',
));

$property->setType(
    new PropertyType($nonNullTypes, $hasNull ? true : null),
    new PropertyType($nonNullTypes, $hasNull ? true : null),
);
```

This produces `?string` for `["string","null"]` (single non-null type, nullable = true) or
`string|int|null` for `["string","integer","null"]` (multiple non-null types, nullable = true).

**New test** (add to `tests/Objects/MultiTypePropertyTest.php`):

A new test method `testNullableMultiTypeAnnotation` using a schema with `"type": ["string","null"]`:

```php
// Native hint: ?string (single non-null type, nullable)
$this->assertEqualsCanonicalizing(
    ['string', 'null'],
    $this->getReturnTypeNames($className, 'getProperty'),
);
// Functional: null is valid (it is a listed type)
$object = new $className(['property' => null]);
$this->assertNull($object->getProperty());
// Functional: string is valid
$object = new $className(['property' => 'hello']);
$this->assertSame('hello', $object->getProperty());
```

**Acceptance:** Existing tests for `"type": ["string","null"]` properties continue to pass with
correct `?string` native hint.

---

## Phase 5 — Scenario B: filter/transformer native type hint

**Goal:** A property with a transforming filter (e.g. `date-time` → `DateTime`) currently has
`$type = PropertyType('string')` and `$outputType = PropertyType('DateTime')`. `Property::getType(false)`
returns `null` when these differ, suppressing the native hint. With union support, the setter should
accept `string|DateTime` and the getter should return `string|DateTime`.

### Task 5.1 — Update `Property::getType()` for the filter/transformer case

File: `src/Model/Property/Property.php`

Replace:
```php
// TODO: PHP 8 use union types to accept multiple input types
if (!$outputType && $this->type && $this->outputType
    && $this->outputType->getName() !== $this->type->getName()
) {
    return null;
}
```

With:
```php
if (!$outputType && $this->type && $this->outputType
    && $this->outputType->getName() !== $this->type->getName()
) {
    return new PropertyType(
        array_unique(array_merge($this->type->getNames(), $this->outputType->getNames())),
        $this->type->isNullable() ?? $this->outputType->isNullable(),
    );
}
```

The getter (`$outputType = true`) path is unchanged: it already returns `$this->outputType` directly,
which is `PropertyType('DateTime')` — correct for the getter's declared output type.

The setter (`$outputType = false`) path now returns `string|DateTime`, allowing the caller to pass
either the raw string (pre-filter) or an already-transformed `DateTime` (pass-through case).

**New functional tests** (add to `tests/PostProcessor/PatternPropertiesAccessorPostProcessorTest.php`
or the relevant filter test file):

- Setter accepts a raw `string` value (pre-filter) — verify the filter runs and the property stores
  a `DateTime`. This is already covered implicitly by `testPatternPropertiesWithFilter()`; confirm
  it still passes.
- Setter accepts an already-constructed `DateTime` (pass-through, skips filter) — add an explicit
  assertion if not already present:
  ```php
  $dt = new DateTime('2020-01-01');
  $object->setAlpha($dt);
  $this->assertSame($dt, $object->getAlpha());
  ```
- Setter rejects a value that is neither `string` nor `DateTime` (e.g. `42`) — should throw a
  `TypeError` or `ValidationException` depending on where the check runs.

**Acceptance:** Filter-property tests that currently assert `assertNull(getParameterType(...))` now
return a `ReflectionUnionType`. Specifically `PatternPropertiesAccessorPostProcessorTest` line 442
changes. Task 8.3 updates these assertions. All other tests pass.

---

## Phase 6 — Scenario C: composition merger for cross-typed same-name properties

**Goal:** When two branches of a composition define the same property name with different types
(e.g. `age: integer` in branch 1, `age: string` in branch 2), the root-level property should be
widened to a union type rather than silently keeping only the first.

**Extended analysis:** See `.claude/issues/110/phase6-merger-analysis.md` for a comprehensive
treatment of every edge case, including the nested-schema guard, `null`-as-type-name handling,
input vs output type distinction, multi-branch accumulation, `allOf` semantics, and validator
filtering scope. The algorithm below incorporates all findings from that analysis.

### Task 6.1 — Update `Schema::addProperty()` to merge conflicting types

File: `src/Model/Schema.php`

The current `else` branch contains a commented-out exception with TODO test names:
```php
// TODO tests:
// testConditionalObjectProperty
// ...
// throw new SchemaException("Duplicate attribute name {$property->getName()}");
```
Remove this commented-out block as part of this task — the type-widening logic replaces it.

Replace the silent no-op in the `else` branch of `addProperty()`:

```php
} else {
    $existing = $this->properties[$property->getName()];

    // Nested-object merging is handled by the merged-property system; don't interfere.
    if ($existing->getNestedSchema() !== null || $property->getNestedSchema() !== null) {
        return $this;
    }

    // Use getType(true) to access the raw stored output type.
    // getType(false) after Phase 5 returns a synthesised union and cannot be decomposed.
    $existingOutput = $existing->getType(true);
    $incomingOutput = $property->getType(true);

    if (!$existingOutput || !$incomingOutput) {
        return $this;  // Can't merge when either side has no type
    }

    $allNames = array_merge($existingOutput->getNames(), $incomingOutput->getNames());

    // Strip 'null' → nullable flag; PropertyType constructor deduplicates the rest.
    $hasNull = in_array('null', $allNames, true);
    $nonNullNames = array_values(array_filter($allNames, fn(string $t): bool => $t !== 'null'));

    if (!$nonNullNames) {
        return $this;  // Degenerate: only null types
    }

    $mergedType = new PropertyType($nonNullNames, $hasNull ? true : null);

    if ($mergedType->getNames() === $existingOutput->getNames()
        && $mergedType->isNullable() === $existingOutput->isNullable()
    ) {
        return $this;  // Types identical after dedup — nothing to do
    }

    // Widen: pass merged type as both input and output. The root has no filter transformation,
    // so input = output. Phase-5 synthesis in getType(false) will handle the setter correctly.
    $existing->setType($mergedType, $mergedType);

    // Strip any surviving type-check validators; branch sub-classes enforce constraints.
    $existing->filterValidators(
        static fn(Validator $v): bool =>
            !($v->getValidator() instanceof TypeCheckInterface),
    );
}
```

**Notes:**
- `nullable: true` set on the merged type because a `oneOf`/`anyOf` root property is always
  nullable — the branch that doesn't win doesn't provide a value. If `'null'` appeared explicitly
  in the names, the `$hasNull` path covers that and `nullable` is forced `true` explicitly.
- The `onResolve` callback counter is intentionally not registered for the duplicate — it was
  already counted when the first copy was added.
- `filterValidators()` runs only inside the "types changed" guard to avoid touching properties
  that didn't actually need widening.
- **`allOf` with conflicting types must throw `SchemaException`**: `allOf` requires all branches
  to hold simultaneously, so conflicting types on the same property produce an unsatisfiable
  schema. Widening to a union and silently generating code would be wrong — the developer must
  be notified immediately. The composition type (`allOf` vs `anyOf`/`oneOf`) must be passed into
  `addProperty()` or detectable from the calling processor to apply this check.

**Acceptance:** `ageCrossTyped.json` generates `getAge(): int | string` instead of `getAge(): ?int`.
All existing tests pass (no existing schema has conflicting same-name cross-typed properties).

### Task 6.2 — Update `AbstractComposedValueProcessor::transferPropertyType()` for multi-branch unions

File: `src/PropertyProcessor/ComposedValue/AbstractComposedValueProcessor.php`

`transferPropertyType()` currently only sets the root property type when all composition branches
agree on a single type. Extend it to set a union when branches produce different types.

**Important:** The original plan used `->getName()` (returns only the first element of a union).
This must be `->getNames()` to correctly handle branches that themselves already carry a union type
(e.g. from Phase 4 or Phase 5). Use `array_merge` to flatten across branches:

```php
private function transferPropertyType(PropertyInterface $property, array $compositionProperties): void
{
    if ($this instanceof NotProcessor) {
        return;
    }

    $allNames = array_merge(...array_map(
        static fn(CompositionPropertyDecorator $p): array =>
            $p->getType() ? $p->getType()->getNames() : [],
        $compositionProperties,
    ));

    // Strip 'null' → nullable flag; PropertyType constructor deduplicates.
    $hasNull = in_array('null', $allNames, true);
    $nonNullNames = array_values(array_filter(
        array_unique($allNames),
        fn(string $t): bool => $t !== 'null',
    ));

    if (!$nonNullNames) {
        return;  // No typed branches
    }

    // nullable: true if any branch had no type (branch didn't always provide a value)
    // or if 'null' appeared explicitly, or if branches disagree (not all always apply).
    $hasBranchWithNoType = count($compositionProperties) > count(array_filter(
        $compositionProperties,
        static fn(CompositionPropertyDecorator $p): bool => $p->getType() !== null,
    ));
    $nullable = $hasNull || $hasBranchWithNoType || count($nonNullNames) > 1
        ? true
        : null;

    $property->setType(new PropertyType($nonNullNames, $nullable));
}
```

**Acceptance:** All existing tests pass. `ageCrossTyped.json` now also has its composition-level
type set correctly alongside the root widening in Task 6.1.

### Task 6.3 — Write the `CrossTypedCompositionTest` test class

Files: `tests/ComposedValue/CrossTypedCompositionTest.php` (new),
`tests/Schema/CrossTypedCompositionTest/` (new schema directory)

The probe schema `tests/Schema/Issues/110/ageCrossTyped.json` already exists from the investigation
phase. Move or copy it into the new `CrossTypedCompositionTest/` schema directory alongside any
additional schema variants created for this test class.

**Test cases for `oneOf` with `age: integer (min 0) | age: string (pattern ^[0-9]+$)`:**

1. **Type hint assertions**: getter returns `ReflectionUnionType(['int', 'string'])`, setter
   parameter returns the same, property DocBlock `@var` is `int|string|null`
2. **Integer branch valid**: `['age' => 42]` → `getAge() === 42`
3. **String branch valid**: `['age' => '42']` → `getAge() === '42'`
4. **Integer constraint enforced in branch**: `age = -1` throws (minimum: 0)
5. **String constraint enforced in branch**: `age = 'abc'` throws (pattern mismatch)
6. **Neither branch matches**: `age = 3.14` throws (not int, not matching string pattern)
7. **Missing required property**: `[]` throws

**Parallel test set for `anyOf`** with the same schema (an `anyOf` variant):

1. Same type hint assertions
2. Integer branch valid: `['age' => 42]` → `getAge() === 42`
3. String branch valid: `['age' => '42']` → `getAge() === '42'`
4. Integer constraint enforced: `age = -1` throws
5. String constraint enforced: `age = 'abc'` throws

**Additional test cases** (required by the extended analysis in `phase6-merger-analysis.md`):

- **Same type in both branches, no widening** (schema: `oneOf [{ age: integer }, { age: integer, minimum: 0 }]`):
  root property stays `?int` — verifies widening only fires when types *differ*
- **3-branch accumulation** (`oneOf` with `int`, `string`, `bool` for same prop):
  getter/setter have union `int | string | bool`
- **Explicit null branch** (`oneOf` with `int` branch and `null`-type branch):
  result is `?int` (nullable=true, no union), not `int|null`
- **Nested object in one branch**: no type merge; merged-property logic stays in charge
- **No-type branch** (one typed branch + one untyped branch): no widening applied

---

## Phase 7 — Remove the `#114` workaround from `ComposedItem.phptpl`

**Goal:** The `$resolvedProperties`/`$nullableProperties` deferred null-setting added for issue #114
exists to prevent `TypeError` at typed getters when a non-matching branch's value would arrive.
After Phases 4–6, root properties have accurate union types and this `TypeError` can no longer occur.

### Task 7.1 — Verify the workaround is obsolete

After Phases 4–6 are complete, temporarily remove the null-setting block and run the full test suite.
Any failure indicates a remaining case where the value type still doesn't fit the root getter —
investigate before proceeding.

### Task 7.2 — Remove the null-setting block from `ComposedItem.phptpl`

File: `src/Templates/Validator/ComposedItem.phptpl`

Remove:
- `$resolvedProperties = [];` and `$nullableProperties = [];` declarations
- `$resolvedProperties[] = '...';` lines inside the success path
- `$nullableProperties[] = '...';` lines inside the catch block
- `foreach (array_diff($nullableProperties, $resolvedProperties) ...)` block after the branch loop

**Acceptance:** All tests pass with the null-setting block removed.

---

## Phase 8 — Update the test suite

**Goal:** Update all tests that encoded the pre-union-type behaviour. These fall into two groups:
tests that assert `assertNull` on native type hints (which are now non-null), and tests that assert
`null` return values from getters (which were null only because of the null-setting workaround).

### Task 8.1 — Update `MultiTypePropertyTest` (Scenario A)

File: `tests/Objects/MultiTypePropertyTest.php`

`testOptionalMultiTypeAnnotation` and `testRequiredMultiTypeAnnotation` currently assert:
```php
$this->assertNull($this->getParameterType($className, 'setProperty'));
$this->assertNull($this->getReturnType($className, 'getProperty'));
```

Change to use the new helpers (schema has `"type": ["number","string","array"]` → PHP types
`float|string|array`):
```php
$this->assertEqualsCanonicalizing(
    $implicitNull ? ['float', 'string', 'array', 'null'] : ['float', 'string', 'array'],
    $this->getParameterTypeNames($className, 'setProperty'),
);
$this->assertEqualsCanonicalizing(
    ['float', 'string', 'array', 'null'],   // getter always nullable (optional property)
    $this->getReturnTypeNames($className, 'getProperty'),
);
```

### Task 8.2 — Update `EnumPropertyTest` for multi-value enums (Scenario A subset)

File: `tests/Objects/EnumPropertyTest.php`

Enum properties with heterogeneous value types (e.g. `"enum": ["red", null, 0, 10]` — both string
and int values) currently assert `assertNull` on native type hints. The `MultiTypeProcessor` path
handles these (the type array is derived from the enum values' PHP types). Update:

```php
// Before:
$this->assertNull($this->getReturnType($object, 'getProperty'));
$this->assertNull($this->getParameterType($object, 'setProperty'));

// After:
$this->assertEqualsCanonicalizing(
    ['string', 'int', 'null'],
    $this->getReturnTypeNames($object, 'getProperty'),
);
$this->assertEqualsCanonicalizing(
    $implicitNull ? ['string', 'int', 'null'] : ['string', 'int'],
    $this->getParameterTypeNames($object, 'setProperty'),
);
```

### Task 8.3 — Update `PatternPropertiesAccessorPostProcessorTest` (Scenario B)

File: `tests/PostProcessor/PatternPropertiesAccessorPostProcessorTest.php`

The `setAlpha` setter for a filter-transformed property (string → DateTime) currently asserts:
```php
$this->assertNull($this->getParameterType($object, 'setAlpha'));
```

After Phase 5, this becomes `string|DateTime`. Update to:
```php
$this->assertEqualsCanonicalizing(
    ['string', 'DateTime', 'null'],   // or without null if required
    $this->getParameterTypeNames($object, 'setAlpha'),
);
```

Also update the DocBlock annotation assertion if it changes (currently `string|DateTime|null` — the
DocBlock path already produced this correctly, so it should be unchanged).

### Task 8.4 — Update null-setting side-effect tests (Scenario C)

These tests currently assert `null` for properties that were silenced by the null-setting workaround.
After Phase 7, those properties return their actual input values.

Affected tests (from `analysis.md`):
- `ComposedAnyOfTest::testMatchingPropertyForComposedAnyOfObjectIsValid` (7 data sets)
- `ComposedAnyOfTest::testMatchingPropertyForComposedAnyOfObjectWithRequiredPropertiesIsValid`
- `ComposedOneOfTest::testMatchingPropertyForComposedOneOfObjectIsValid` (7 data sets)
- `ComposedOneOfTest::testMatchingPropertyForComposedOneOfObjectWithRequiredPropertiesIsValid`
- `ComposedOneOfTest::testValidationInSetterMethods` (2 data sets)
- `PopulatePostProcessorTest::testPopulateComposition` (2 data sets)

For each data set: determine the new expected return value.
- Property key **not present** in the input object → still `null` (key absent, not null-set)
- Property key **present** but belonging to a non-winning branch → now returns the raw input value
  (string, int, etc.) rather than `null`

### Task 8.5 — Update `EnumPostProcessorTest` for filter union hints (Scenario B)

File: `tests/PostProcessor/EnumPostProcessorTest.php`

The `EnumPostProcessor` applies a transforming filter (raw string/int → PHP backed enum). The setter
accepts either the raw scalar or an already-constructed enum instance (pass-through). After Phase 5,
this becomes a native union hint. Update the `assertNull(getParameterType(...))` calls to
union-type assertions.

---

## Dependency graph

```
Task 1.1 ──► Task 2.1
Task 2.1 ──► Task 2.2

Task 3.1  (independent — can be done any time before Phase 8)

Tasks 1.1, 2.1, 2.2 ──► Phase 4 (Tasks 4.1, 4.2)
Tasks 1.1, 2.1, 2.2 ──► Phase 5 (Task 5.1)
Tasks 1.1, 2.1, 2.2 ──► Phase 6 (Tasks 6.1, 6.2, 6.3)

Phases 4, 5, 6 are independent of each other (different code paths, different test files)

Phase 6 ──► Phase 7 (Tasks 7.1, 7.2)

Tasks 3.1, Phase 4, Phase 5, Phase 6, Phase 7 ──► Phase 8 (Tasks 8.1–8.5)

Phases 1–2 ──► Phase 9 (Task 9.1 — reuses RenderHelper::getType() which must support unions)
Task 9.1 ──► Tasks 9.2, 9.3, 9.4
Phase 8 and Phase 9 can proceed in parallel once Phases 1–7 are complete; Task 9.3 shares
test files with Phase 8 and should be merged together rather than in separate commits.

Phases 1–9 ──► Phase 10 (Tasks 10.1, 10.2 — all callers must be on the new API before getName() is removed)
```

---

## Files changed per task (summary)

| Task | Files |
|---|---|
| 1.1 | `src/Model/Property/PropertyType.php`, `tests/Model/Property/PropertyTypeTest.php` (new) |
| 2.1 | `src/Utils/RenderHelper.php` |
| 2.2 | `src/Utils/RenderHelper.php` |
| 3.1 | `tests/AbstractPHPModelGeneratorTestCase.php` |
| 4.1 | `src/PropertyProcessor/Property/MultiTypeProcessor.php` |
| 4.2 | `src/PropertyProcessor/Property/MultiTypeProcessor.php` |
| 5.1 | `src/Model/Property/Property.php` |
| 6.1 | `src/Model/Schema.php` |
| 6.2 | `src/PropertyProcessor/ComposedValue/AbstractComposedValueProcessor.php` |
| 6.3 | `tests/ComposedValue/CrossTypedCompositionTest.php` (new), `tests/Schema/CrossTypedCompositionTest/*.json` (new) |
| 7.1 | — (verification only) |
| 7.2 | `src/Templates/Validator/ComposedItem.phptpl` |
| 8.1 | `tests/Objects/MultiTypePropertyTest.php` |
| 8.2 | `tests/Objects/EnumPropertyTest.php` |
| 8.3 | `tests/PostProcessor/PatternPropertiesAccessorPostProcessorTest.php` |
| 8.4 | `tests/ComposedValue/ComposedOneOfTest.php`, `tests/ComposedValue/ComposedAnyOfTest.php`, `tests/PostProcessor/PopulatePostProcessorTest.php` |
| 8.5 | `tests/PostProcessor/EnumPostProcessorTest.php` |
| 9.1 | `src/Templates/Model.phptpl` |
| 9.2 | `tests/AbstractPHPModelGeneratorTestCase.php` |
| 9.3 | Same test files as Phase 8, plus `tests/Basic/BasicSchemaGenerationTest.php`, `tests/Basic/DefaultValueTest.php`, `tests/Objects/RequiredPropertyTest.php`, `tests/Objects/ArrayPropertyTest.php` |
| 9.4 | — (verification only; potential fix in `RenderHelper.php` or template if defaults are incompatible) |
| 10.1 | `src/PropertyProcessor/Filter/FilterProcessor.php`, `src/PropertyProcessor/ComposedValue/AbstractComposedValueProcessor.php`, `src/Model/Validator/PassThroughTypeCheckValidator.php`, `src/PropertyProcessor/Property/BaseProcessor.php`, `src/Model/Validator/FilterValidator.php`, `src/Model/Validator/InstanceOfValidator.php`, `src/SchemaProcessor/PostProcessor/AdditionalPropertiesAccessorPostProcessor.php`, `src/PropertyProcessor/Property/ArrayProcessor.php`, `src/SchemaProcessor/PostProcessor/PatternPropertiesAccessorPostProcessor.php` |
| 10.2 | `src/Model/Property/PropertyType.php`, `tests/Model/Property/PropertyTypeTest.php` |

---

## Phase 9 — Native type hints on property declarations

PHP 8.0 supports union types on property declarations (`protected int|string $age;` is valid).
The current `Model.phptpl` line 32 never emits a native type hint on property declarations at all —
only a DocBlock `@var`. This is a pre-existing gap, not a language constraint.

Adding typed property declarations is the natural completion of the union type work. It improves
static analysis accuracy (tools can see the stored type without parsing DocBlocks) and is consistent
with the typed getter and setter already generated.

### Which type to declare on the property

The property declaration holds the **stored value** — the value as it sits in the object between
method calls. This is the **output type** (i.e. `getType(true)`), for the same reason the getter
uses `getType(true)`:

| Property scenario | Stored type | Declaration |
|---|---|---|
| `age: integer`, optional | `int`, nullable | `protected ?int $age;` |
| `age: integer`, required | `int`, not nullable | `protected int $age;` |
| `age: int\|string` (cross-branch union) | `int\|string`, nullable | `protected int\|string\|null $age;` |
| `date: string` with `date-time` filter → `DateTime` | `DateTime` (output type) | `protected ?DateTime $date;` |
| `date: string` with filter, setter accepts `string\|DateTime` | stored as `DateTime` | `protected ?DateTime $date;` |

For the filter case the setter parameter is `string|DateTime` (Scenario B, Phase 5) but the
**stored** value is always `DateTime` — the filter runs during `processXxx()` before assignment.
So the property declaration uses only the output type, not the full setter union.

For Scenario A multi-type properties (`"type": ["number","string","array"]`) the output type equals
the input type (no filter), so the declaration is `protected float|string|array|null $property;`.

For Scenario C cross-branch unions the output type is the widened union, so the declaration is
`protected int|string|null $age;`.

### Task 9.1 — Emit native type hints on property declarations in `Model.phptpl`

File: `src/Templates/Model.phptpl`

Change line 32 from:
```phptpl
{% if property.isInternal() %}private{% else %}protected{% endif %} ${{ property.getAttribute(true) }}
```
To:
```phptpl
{% if property.isInternal() %}private{% else %}protected{% endif %}{% if property.getType(true) %} {{ viewHelper.getType(property, true) }}{% endif %} ${{ property.getAttribute(true) }}
```

This reuses the existing `viewHelper.getType(property, true)` call — identical to the getter return
type — so no new logic is needed in `RenderHelper`. The `{% if property.getType(true) %}` guard
preserves the existing behaviour for untyped properties (`mixed`, no type set) which must remain
untyped on the declaration.

**New tests** (add to `tests/Basic/DefaultValueTest.php` or a new focussed test):

- Untyped property (`"type"` absent or resolves to `mixed`) still has no native declaration type
  hint after Task 9.1:
  ```php
  $this->assertSame([], $this->getPropertyTypeNames($object, 'property'));
  ```
  This guards against the `{% if property.getType(true) %}` guard accidentally being omitted.

**Acceptance:** All existing single-type properties gain a typed declaration matching their getter.
`assertNull(getReturnType(...))` tests are unaffected — those properties have `getType(true) = null`
and still emit no declaration type. All 2039 tests pass.

### Task 9.2 — Add `getPropertyType()` reflection helper to `AbstractPHPModelGeneratorTestCase`

File: `tests/AbstractPHPModelGeneratorTestCase.php`

Existing helpers cover getters (`getReturnType`) and setters (`getParameterType`) but not property
declarations. Add:

```php
protected function getPropertyType($object, string $property): ?\ReflectionType
{
    return (new \ReflectionClass($object))->getProperty($property)->getType();
}

protected function getPropertyTypeNames($object, string $property): array
{
    $type = $this->getPropertyType($object, $property);
    if (!$type) {
        return [];
    }
    if ($type instanceof \ReflectionUnionType) {
        return array_map(fn(\ReflectionNamedType $t) => $t->getName(), $type->getTypes());
    }
    /** @var \ReflectionNamedType $type */
    $names = [$type->getName()];
    if ($type->allowsNull() && $type->getName() !== 'null') {
        $names[] = 'null';
    }
    return $names;
}
```

Note: the existing `getPropertyTypeAnnotation()` helper already extracts the `@var` DocBlock
string; the new `getPropertyType()` returns the Reflection-based native type.

**Acceptance:** All existing tests pass.

### Task 9.3 — Update existing type-hint tests to also assert the property declaration type

For each test method that already asserts getter return type and setter parameter type, add a
parallel assertion for the property declaration:

```php
// Example: single-type optional property
$this->assertEqualsCanonicalizing(
    ['int', 'null'],
    $this->getPropertyTypeNames($object, 'age'),
);

// Example: required property
$this->assertEqualsCanonicalizing(
    ['int'],
    $this->getPropertyTypeNames($object, 'age'),
);

// Example: cross-branch union, nullable
$this->assertEqualsCanonicalizing(
    ['int', 'string', 'null'],
    $this->getPropertyTypeNames($object, 'age'),
);

// Example: filter-transformed (stored as DateTime, not string)
$this->assertEqualsCanonicalizing(
    ['DateTime', 'null'],
    $this->getPropertyTypeNames($object, 'date'),
);
```

Tests to update include those in `DefaultValueTest`, `BasicSchemaGenerationTest`,
`ComposedOneOfTest::testCompositionTypes`, `ComposedAnyOfTest::testCompositionTypes`,
`ComposedAllOfTest::testCompositionTypes`, `ArrayPropertyTest`, `RequiredPropertyTest`,
`MultiTypePropertyTest` (after Task 8.1), `CrossTypedCompositionTest` (Task 6.3), and the
filter-related tests in `PatternPropertiesAccessorPostProcessorTest`.

### Task 9.4 — Verify default value initialisation is compatible with typed declarations

File: `src/Templates/Model.phptpl` line 32

The template currently emits:
```phptpl
protected $age = null;   (when default is null)
protected $count = 0;    (when default is 0)
```

With a typed declaration, PHP requires the default value to be compatible with the declared type.
`protected ?int $count = null;` is valid. `protected int $count = null;` is not (non-nullable type
with null default). Verify that `RenderHelper::getType(property, true)` for a property with a
default value always returns a nullable type when the default is `null`, and a non-nullable type
only when a non-null default is present.

**Acceptance:** No `TypeError` or `Fatal error` from incompatible typed property defaults in any
generated class.

### Dependency

Task 9.1 depends on Phases 1–2 being complete (needs `RenderHelper::getType()` to support unions).
Tasks 9.2–9.4 depend on Task 9.1.
Task 9.3 should be done alongside the relevant Phase 8 test updates (same test files).

## Phase 10 — Remove `PropertyType::getName()` compatibility shim

**Goal:** `getName()` was kept in Task 1.1 so that all existing call sites could continue working
unchanged throughout Phases 2–9 without requiring a big-bang migration. Now that every consumer
of `PropertyType` has been audited and updated where needed (Phases 4–6, risk register items),
`getName()` can be removed.

Retaining `getName()` indefinitely would be a design smell: it silently returns only the first
type name of a union, which is meaningless for multi-type properties and misleading for callers
that have not been updated.

### Task 10.1 — Audit and migrate all remaining `->getName()` call sites

Search `src/` for all occurrences of `->getName()` on a `PropertyType` or on the result of
`->getType()`. For each occurrence, determine the correct replacement:

| Call site | Current | Correct replacement |
|---|---|---|
| `FilterProcessor.php` line 92 | `->getName() === 'array'` | `in_array('array', ->getNames(), true)` |
| `FilterProcessor.php` line 119 | `->getName() !== $typeAfterFilter->getName()` | `->getNames() !== $typeAfterFilter->getNames()` (or compare the first names if the intent is single-type only) |
| `AbstractComposedValueProcessor.php` line 178 | `->getType()->getName()` | `->getType()->getNames()` — collect all names, not just the first |
| `PassThroughTypeCheckValidator.php` line 39 | `->getType()->getName()` | Verify intent: the check compares input type; use `->getNames()[0]` only if single-input is guaranteed, else update the check to handle all names |
| `BaseProcessor::cloneTransferredProperty()` lines 365–366 | `new PropertyType(->getName(), true)` | `new PropertyType(->getNames(), true)` — preserves the full union |
| `FilterValidator.php` multiple lines | `->getName()` for type comparison | Migrate to `getNames()` or `getNames()[0]` depending on whether multi-type is possible at that point |
| `InstanceOfValidator.php` lines 26, 29 | `->getName()` | `->getNames()[0]` if always single-type; `getNames()` if multi-type is possible |
| `AdditionalPropertiesAccessorPostProcessor.php` lines 165, 201 | `new PropertyType(->getName(), true)` | `new PropertyType(->getNames(), true)` |
| `ArrayProcessor.php` line 69 | `new PropertyType(->getName(), false)` | `new PropertyType(->getNames(), false)` |
| `PatternPropertiesAccessorPostProcessor.php` line 77 | `fn(PropertyType $type): string => $type->getName()` | `fn(PropertyType $type): array => $type->getNames()` — adjust the consuming code accordingly |

The `RenderHelper::getType()` single-type path (`return ($nullable ? '?' : '') . $names[0]`)
uses `$names[0]` directly after `getNames()` — this is already correct and does not call `getName()`.

### Task 10.2 — Remove `PropertyType::getName()`

File: `src/Model/Property/PropertyType.php`

Once Task 10.1 is complete and all tests pass, remove the `getName(): string` method entirely.
Update `tests/Model/Property/PropertyTypeTest.php` to remove the `getName()` assertions added in
Task 1.1 and verify that no test references `getName()` any longer.

**Acceptance:** `grep -r 'getName()' src/` returns zero results for `PropertyType` call sites.
All tests pass.

### Dependency

Phase 10 depends on all of Phases 1–9 being complete (all callers must be on the new API before
the shim is removed). It is the final step and can be committed as a standalone cleanup commit.

---

## Risk register

| Risk | Mitigation |
|---|---|
| Phase 4 `PropertyType::union` on multi-type properties: `FilterProcessor` calls `->getName()` at lines 92 and 119 on the result of `$property->getType()` | Temporarily mitigated: `getName()` returns the first name, and multi-type properties never have `array` as their first type in current schemas. **Permanently fixed in Task 10.1** by migrating to `getNames()`-based checks. |
| Phase 4 union `PropertyType` on multi-type properties breaks `AbstractComposedValueProcessor::transferPropertyType()` which calls `->getName()` at line 178 | `getName()` returns first name — safe for now since multi-type composition branches are not produced by current schemas. **Permanently fixed in Task 10.1** by collecting all names via `getNames()`. |
| `"type": ["string","null"]` — `null` in names causes `null\|null` in output | Task 4.2 strips `'null'` from names and sets `nullable = true` before constructing `new PropertyType([...], true)` |
| Phase 5 filter union breaks `PassThroughTypeCheckValidator` which calls `$property->getType()->getName()` at line 39 of `PassThroughTypeCheckValidator.php` | After Phase 5, `getType(false)` returns a union; `getName()` returns the first name (the input type e.g. `'string'`). Temporarily safe since the check compares the input-side type. **Permanently fixed in Task 10.1** by migrating the call site. |
| `cloneTransferredProperty()` in `BaseProcessor.php` calls `->getName()` on the result of `getType()` and `getType(true)` at lines 365–366 to construct new `PropertyType` objects | After Phase 6, root properties may have union `PropertyType`; `getName()` returns the first name only, losing union members. **Permanently fixed in Task 10.1**: `new PropertyType(->getNames(), true)` preserves the full union. |
| Phase 6 type-check validator removal in `addProperty()` too broad — removes `PropertyTemplateValidator` | Guard with `TypeCheckInterface` only; `PropertyTemplateValidator` does not implement `TypeCheckInterface` |
| Phase 7 removal reveals a remaining `TypeError` case not covered by Phases 4–6 | Phase 7 starts with a verification step (Task 7.1); if failures appear, diagnose before removing |
| `SerializationPostProcessor` uses `$property->getType()` for generated serialization code — union type may produce unexpected serialization logic | Run serialization tests specifically after each of Phases 4–6; `SerializationPostProcessor` may need to handle `isUnion()` separately |
