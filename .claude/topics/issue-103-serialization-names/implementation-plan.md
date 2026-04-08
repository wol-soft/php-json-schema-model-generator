# Issue #103 — Implementation plan

See [`analysis.md`](analysis.md) for problem statement and decisions.

## Phase 0 — Vendor copy hygiene

Before touching anything in `vendor/wol-soft/php-json-schema-model-generator-production`:

1. `git fetch composer` and `git fetch origin` in the vendor dir to make
   sure the local `master` is up to date with both remotes.
2. Confirm the only local divergence is the four expected untracked
   attribute classes (`Deprecated.php`, `ReadOnlyProperty.php`,
   `Required.php`, `WriteOnly.php`) plus the local commits that are
   ahead of `origin/master`. **Do not** rebase, pull, push, or stash
   anything without explicit user confirmation.
3. Work in the vendor copy throughout Phases 1, 2 and 3. Once everything
   is finished, tested and verified, the changes get committed in the
   production repo as the final step.

## Phase 1 — Production library: rewrite `SerializableTrait`

**File:** `vendor/wol-soft/php-json-schema-model-generator-production/src/Traits/SerializableTrait.php`

(All changes here must eventually land in the production library repo,
not the generator repo. The generator repo carries it transitively via
composer.)

### 1a. `_getValues` → schema-name keyed loop

1. Add `use ReflectionClass;` and
   `use PHPModelGenerator\Attributes\SchemaName;` imports.
2. Add a static cache:
   ```php
   /** @var array<class-string, array<string, string>> php-name → schema-name */
   private static array $_propertySchemaNames = [];
   ```
3. Add a private helper that lazily populates the cache for a given
   concrete class via reflection, walking `getProperties()`, skipping any
   property whose name starts with `_`, and reading the first
   `#[SchemaName]` attribute on each. Properties without `#[SchemaName]`
   (e.g. internal helpers added by post processors) are skipped — they
   never participate in serialization.
4. Replace the body of `_getValues` so the loop walks the cached
   php-name → schema-name map instead of `get_class_vars`. The new loop:
   - Builds `$modelData` keyed by **schema name**.
   - Reads property values via the **php name** (`$this->{$phpName}`).
   - Looks up custom serializers via the **php name** (unchanged
     `_getCustomSerializerMethod($phpName)` call).
   - Compares `$localExcept` against the schema name.
5. Keep the `_skipNotProvidedPropertiesMap` / `_rawModelDataInput` logic
   intact — it works correctly once Phase 2 makes both sides schema-name
   keyed.

### 1b. `evaluateAttribute` rewrite (additional cleanup, see analysis §
"`evaluateAttribute` rewrite")

1. Add a class-intrinsic capability cache:
   ```php
   /** @var array<class-string, string> */
   private static array $_objectSerializationCapability = [];
   ```
2. Populate it lazily on first encounter of each `$attribute::class` via
   a `match (true)`:
   ```php
   self::$_objectSerializationCapability[$attribute::class] ??= match (true) {
       $attribute instanceof SerializationInterface => 'protocol',
       method_exists($attribute, 'jsonSerialize')   => 'jsonSerializable',
       method_exists($attribute, 'toArray')         => 'toArray',
       method_exists($attribute, '__toString')      => 'stringable',
       default                                      => 'plain',
   };
   ```
   Note: `instanceof SerializationInterface` instead of
   `class_uses(SerializableTrait::class)`. The post processor already
   adds `SerializationInterface` to the implements list of every
   generated class with the trait, and `instanceof` is faster and
   inheritance-safe.
3. Handle the depth-zero case **after** the cache population, so the
   cache key only encodes class-intrinsic capability (not call context):
   ```php
   if ($depth <= 0) {
       return self::$_objectSerializationCapability[$attribute::class] === 'stringable'
           ? (string) $attribute
           : null;
   }
   ```
4. Per-call dispatch on the cached capability + `$emptyObjectsAsStdClass`:
   ```php
   $data = match (self::$_objectSerializationCapability[$attribute::class]) {
       'protocol'         => $emptyObjectsAsStdClass
                               ? $attribute->jsonSerialize($except)
                               : $attribute->toArray($except, $depth),
       'jsonSerializable' => $emptyObjectsAsStdClass
                               ? $attribute->jsonSerialize()
                               : get_object_vars($attribute),
       'toArray'          => $attribute->toArray(),
       'stringable'       => null, // unreachable here; depth-zero handled above
       'plain'            => get_object_vars($attribute),
   };
   ```
   - Case `'protocol'` is the only branch that propagates `$except` and
     `$depth` through, because it is the only branch where we know the
     callee implements our protocol.
   - For non-protocol objects, `$except` and `$depth` are dropped: we
     don't know whether the callee accepts them, and passing
     unrecognised arguments would error in PHP 8+.
   - The `'stringable'` arm is unreachable in this match because the
     depth-zero early return already handled stringables — and
     stringables that are also at non-zero depth don't have any other
     useful serialization, so they never reach this match in the
     'stringable' state. (If they have `__toString` *and* a structured
     method, the structured method ranks higher in the cache populate
     match, so they'd be cached as `'protocol'` / `'jsonSerializable'` /
     `'toArray'` instead.)
5. Keep the existing trailing logic that converts `[] + $emptyObjectsAsStdClass`
   into `new stdClass()`.

### 1c. Run the production library's own test suite

1. `cd vendor/wol-soft/php-json-schema-model-generator-production && ./vendor/bin/phpunit`
   (or whatever the production lib's test runner is — verify before
   running).
2. Fix any regressions before moving on.

**Acceptance for Phase 1:**
- Reflection cache populated once per concrete class.
- Capability cache populated once per nested-attribute class.
- Schema property `product_id` produces a `'product_id'` key in
  `toArray()`.
- Schema property `productId` produces a `'productId'` key (unchanged).
- Custom `serializeFoo()` overrides keep working under their PHP-name form.
- Depth budget propagates through nested protocol-speaking models.
- An object that has only a foreign-signature `toArray()` no longer
  receives `$except` as an argument.
- All existing production library tests pass.

## Phase 2 — Generator: fix `_skipNotProvidedPropertiesMap`

**File:** `src/SchemaProcessor/PostProcessor/Internal/SerializationPostProcessor.php`

1. In `addSkipNotProvidedPropertiesMap` change the `array_map` callback
   from `$property->getAttribute(true)` to `$property->getName()` so the
   stored values are schema names.
2. The accompanying internal `Property` named `'skipNotProvidedPropertiesMap'`
   stays as-is (its own *name* is fine, only its *value contents* change).

**Acceptance:**
- For a schema with an optional `product_id` property that is not
  provided at construction, the property is dropped from `toArray()`
  output (currently it leaks in as `null`).
- Existing tests with all-camelCase schemas continue to pass unchanged.

## Phase 3 — Test coverage in the generator repo

**Files:** new test class under `tests/Issues/Issue/Issue103Test.php`,
schemas under `tests/Schema/Issues/103/`, and an extension to the
existing `tests/Basic/PhpAttributeTest.php` for the leading-digit case.

### 3a. Extend `PhpAttributeTest` for the leading-digit case

The existing `tests/Schema/PhpAttributeTest/BasicSchema.json` already
covers `my property`, `slash/property`, and `tilde~property`. Extend it
with a leading-digit property (e.g. `123name`), then update
`PhpAttributeTest::testDefaultAttributes` to assert that the
`SchemaName` attribute on the corresponding generated property holds
the literal `'123name'` (not `'numeric_property_123name'`).

This is the only verification needed for the leading-digit case at the
attribute level. The serialization test in 3b only needs to prove that
`toArray` reads from `SchemaName`; it does not need to retest every
weird-name shape.

### 3b. New `Issue103Test` for serialization

Enumerated edge cases that **must each have an explicit test**:

1. **Snake_case schema property** (`product_id`) — `toArray()` returns
   `['product_id' => …]`, `toJSON()` returns the snake_case key, and the
   output round-trips through the constructor without validation errors.
2. **Kebab-case schema property** (`my-thing`) — same expectations.
3. **Schema property with a space** (`my property`) — same expectations,
   because `NormalizedName::from` would otherwise produce a totally
   different PHP identifier.
4. **`$except` filter takes a schema name** — passing `'product_id'`
   excludes the property; passing the camelCase form does **not** (this
   is the documented break).
5. **`_skipNotProvidedPropertiesMap` regression test** — schema with an
   optional `product_id` property and `setImplicitNull(false)`. Construct
   without that property; serialize; assert the key is **absent** from
   the output (currently leaks in because the diff compares mismatched
   name forms).
6. **Custom `serializeFoo` override** — generated class with an optional
   `serializeProductId` method on a subclass; assert the override is
   invoked and its return value lands under the `product_id` key.
7. **Nested object with non-camelCase property names** — verify recursion
   works with the new schema-name keys at every depth.
8. **`patternProperties` and `additionalProperties`** — sanity check that
   their merged keys are unchanged and untouched by the new logic.
9. **Pure-camelCase schema** — sanity test that nothing changes for
   schemas where the schema name and PHP name already coincide
   (regression guard for the existing test suite). This may already be
   implicitly covered by `BasicSchemaGenerationTest`; only add a
   dedicated case if it isn't.
10. **Round-trip test** — `new $class($model->toArray())` produces an
    equal model for a schema mixing camelCase, snake_case, kebab-case,
    and spaces.
11. **Depth budget propagation** — nested model with a known depth
    budget. Pre-fix, depth resets per nesting level so a deep model
    serializes fully; post-fix, depth is shared across levels so the
    same `$depth` value cuts off at a predictable level. Test the
    cut-off behaviour at a specific depth value to lock the new
    semantics in.
12. **`evaluateAttribute` capability cache correctness** — serialize the
    same nested-model class first at depth 0, then at a positive depth.
    Pre-fix (with the prototype's bug), the depth-zero call would have
    poisoned the cache and the second call would return null. Post-fix
    (with the split capability cache + per-call dispatch), both calls
    return the correct value. This guards against regressions if the
    cache structure is ever refactored.

## Phase 4 — Documentation

**Files:**
- `docs/source/gettingStarted.rst` — Serialization methods section
  (~line 257-285): add an explicit note that the keys in the resulting
  array / JSON are the **original JSON Schema property names**, not the
  PHP attribute names. Mention that `$except` takes schema names.
- `README.md` — if it has any serialization example, audit and update.
- Changelog / upgrade notes — add an entry under the next release that
  describes the breaking changes, links #103, and gives the
  `resolveSerializationHook` workaround for users who genuinely need the
  old camelCase keys. Three breaking-changes lines:
  1. `toArray` / `toJSON` / `jsonSerialize` keys are now schema names.
  2. `$except` takes schema names.
  3. `$depth` budget is now correctly shared across nested models
     (previously reset per level).

This phase is **mandatory** per `CLAUDE.md` ("Every implementation plan
must include a dedicated documentation update step").

## Phase 5 — Final verification and production-repo commit

1. Run PHP CodeSniffer on every changed file in both repos:
   `./vendor/bin/phpcs --standard=phpcs.xml <changed files>`.
2. Run the full generator test suite per the CLAUDE.md instructions:
   `php -d memory_limit=128M ./vendor/bin/phpunit --no-coverage --display-warnings 2>&1 | sed 's/\x1b\[[0-9;]*m//g' > /tmp/phpunit-output.txt; tail -5 /tmp/phpunit-output.txt`,
   then `grep -E "FAIL|ERROR|WARN|Tests:" /tmp/phpunit-output.txt`, then
   `rm /tmp/phpunit-output.txt`.
3. Walk back through the enumerated edge cases in Phase 3 and confirm
   each has at least one passing test.
4. Move the finished `SerializableTrait.php` from the vendor working
   copy into the production library repo (separate working tree). The
   user owns the production-repo commit and PR; this plan does not
   create them.
5. Delete the `.claude/topics/issue-103-serialization-names/` directory
   as the final commit on the branch in the generator repo, per
   CLAUDE.md.

## Open questions

None — all clarifying questions resolved before plan finalisation.

## Progress log

- 2026-04-07 — initial analysis and plan written.
- 2026-04-07 — clarifications resolved (no compat mode, schema-name
  `$except`, static cache, `_skipNotProvidedPropertiesMap` fix bundled
  in, leading-digit case folded into existing `PhpAttributeTest`).
- 2026-04-07 — `evaluateAttribute` rewrite scoped in (capability cache,
  `instanceof SerializationInterface`, `jsonSerialize` not `toJSON`,
  `__toString` stays as depth-zero fallback only, no `$phpNames`
  parameter); awaiting user go-ahead before Phase 0.
- 2026-04-07 — Phases 0–4 complete. Vendor fast-forwarded to origin/master
  (08806fd). `SerializableTrait` rewritten with `$_propertySchemaNames` and
  `$_objectSerializationCapability` caches; fallback to `get_class_vars` map
  for non-generated classes (e.g. exception hierarchy) added during Phase 1c
  when the production library's own tests revealed the gap.
  `addSkipNotProvidedPropertiesMap` switched to `getName()`.
  `PhpAttributeTest` extended with `123name` leading-digit case; stale
  `testBuiltinAttributes` count assertion (from pre-existing oversight after
  2f9538a) also fixed. 14-test `Issue103Test` created. Docs updated.
- 2026-04-07 — All method/property names in `SerializableTrait` renamed to
  drop underscore prefix (user direction). `#[Internal]` attribute introduced
  in production library for property-level exclusion. Two `.phptpl` templates
  updated (`AdditionalPropertiesSerializer`, `PatternPropertiesSerializer`) to
  call the new names. All 2264 generator tests and 30 production tests pass.
  Phase 5 complete.
