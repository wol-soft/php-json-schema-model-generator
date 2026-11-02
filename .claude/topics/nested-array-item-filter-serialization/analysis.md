# Nested array item filter serialization

Origin: PR #207 (branch `ccr-8ed40cb1-r82lw0`, still open) documents a deliberate gap in
`docs/source/nonStandardExtensions/filter.rst` (`.. warning::` under "Filters on array items"):

> If the items of an array are arrays themselves (`"items": {"type": "array", "items": {"filter": "dateTime"}}`)
> the filter of the inner items is executed and the transformed values are stored, but the serialization
> doesn't apply the serializer of the filter to them yet.

Pinned by `ArrayPropertyTest::testKnownGapFilteredItemsOfNestedArraysAreNotSerialized`
(fixture `tests/Schema/ArrayPropertyTest/NestedArrayItemsWithTransformingFilter.json`).

## Root cause

`SerializationPostProcessor::addArrayItemsTransformingFilterSerializer()` collects
`ArrayItemFilterScope::declarationsOf($arrayProperty)` and calls `createItemSerializer()` only on the
*direct* item property of each declaration (`transformingFilterOf($itemProperty)`). The item property of a
nested array is itself an array property whose own `ArrayItemValidator`/`ArrayTupleValidator`/
`UnevaluatedItemsValidator` (also inside its own branches) hold the filter - never visited.

`TransformingFilterOutputTypePostProcessor::processProperty()` DOES recurse (type check widening and
`assertItemSlotsAreFilteredOnce` already work per nesting level), which is why the inner values are
stored as `DateTime` and the inner type check accepts them. Only the serializer side is missing, so the
generic `_getSerializedValue()` turns each `DateTime` into `[]` / `{}`.

## Probes (PHP 8.4 at /opt/php84/bin/php, scratchpad `probe/run.php`)

All with `dateTime` + `outputFormat: Ymd` on the innermost items, serialization enabled, mutable.

| Schema shape | `toArray()` today |
|---|---|
| `items: array -> items: filter` (documented gap) | `[[[]]]` |
| three levels deep | `[[[[]]]]` |
| outer tuple index holding an array with filtered items | `[[[]],"x"]` |
| inner array with `allOf: [{items: filter}]` | `[[[]]]` |
| inner array with `anyOf` branch filtering items | `[[[]],[1]]` |
| inner tuple-form items with filter on index 0 | `[[[],1]]` |
| `additionalProperties: {type: array, items: filter}` (ONE level) | `{"a":[[]]}` |
| `patternProperties: {type: array, items: filter}` (ONE level) | `{"d_a":[[]]}` |

So the gap is wider than the warning: every array that is not a named top-level property of the class
(array-valued additionalProperties / patternProperties / unevaluatedProperties) has the same symptom.

`unevaluatedProperties: {type: array, items: filter}` -> `{"a":[[]]}` as well, but ONLY visible with
`UnevaluatedPropertiesAccessorPostProcessor` registered (it creates the `_unevaluatedProperties` backing field;
without it the extras are not stored and `toArray()` is simply empty - by design).

Probe correction: the first unevaluatedProperties probes (`toArray: []`) were run without that post processor.
The input schema was valid; the harness config did not match the intent (store the extras). Re-run with the
post processor: baselines are correct (`unevaluatedProperties: {type: string, filter}` -> `{"a":"20201010"}`,
array without filter -> `["x"]`), only array-with-filtered-items is broken.

## Separate, pre-existing bug found: nested `unevaluatedItems` crashes at runtime

`{type: array, items: {type: array, items: [{type: integer}], unevaluatedItems: {type: string}}}`
(Draft 2019-09, NO filter involved) fatals when the model is constructed:
`Call to undefined method ...::collectUnevaluatedIndices()` + undefined `$_evaluatedItemIndices`.
`UnevaluatedPropertiesPostProcessor::needsActivation()` only inspects the JSON of the schema's own
properties and of composition branches, not the `items` schema of an array property, so the class never
receives `CompositionEvaluationTrait` / `_evaluatedItemIndices`. Independent of this topic; additionally
`_evaluatedItemIndices` is keyed by the array property *name*, so even with activation it could not tell
the evaluated indices of different inner arrays apart (shared by all outer elements).
Top-level `unevaluatedItems` + filter works (`{"tags":[1,"2020-10-10T00:00:00+0000"]}`).

## Other known gap from PR #207 (not part of this topic)

Boolean schema in tuple-form `items` crashes generation with a TypeError
(`TupleArrayPropertyTest::testKnownGapBooleanSchemaInTupleItemsCrashesTheGenerator`).

## Environment

PHP 8.3 is the default `php`; phpunit needs 8.4 -> `/opt/php84/bin/php ./vendor/bin/phpunit`.

## Decisions taken with the user

1. Scope (asked): nested items at any depth (schema-form, tuple, composition branches) AND array-valued
   additionalProperties / patternProperties / unevaluatedProperties. Rejected: "only items" and "only one level"
   (would leave a documented gap behind for the same root cause).
2. Nested `unevaluatedItems` (asked): neither fixed nor rejected here - tracked as GitHub issue #213
   (https://github.com/wol-soft/php-json-schema-model-generator/issues/213). Surfacing mechanism required by
   CLAUDE.md for a deferral: a test reproducing the crash, marked as the known failing behaviour.
   Rejected: reject-at-generation (turns a runtime crash into a hard error for schemas that may be valid, user
   did not pick it) and fix-here (touches validation + per-inner-array index tracking, different subsystem).

## Edge cases to cover (probe BEFORE deciding behaviour)

- 2 / 3 levels; outer tuple index holding an array; inner tuple-form items with a filtered index
- inner array with allOf (always active) / anyOf / oneOf branch filtering items (guard by output type)
- inner array declared inside an outer branch (conditional must propagate along the path)
- outer `items` that is a composition of types (`anyOf: [array-of-dates, string]`): non-array element passes through
- nullable inner array (`type: [array, null]`), empty inner arrays, mixed list `[[], ['2020-10-10']]`
- already-transformed input through setter and constructor (`[[new DateTime]]`), serialize -> reconstruct round trip
- additionalProperties / patternProperties / unevaluatedProperties holding arrays (one and two levels)
- immutable and mutable configuration; toArray / toJSON / $except
- array-typed item / property with a transforming filter of its own AND filtered inner items: PROBED, cannot occur.
  `FilterProcessor` rejects a transforming filter on any property whose type contains `array` at every level
  (top-level property, `items`, additionalProperties value: "Applying a transforming filter to the array property
  <name> is not supported"). Existing test only covers the top-level case (`TransformingFilterTest`, property
  `list`); the design relies on the invariant "a nested array level never has a filter of its own", so add
  tests for the item and holder variants.
- recursive array structure (`$ref` cycle) must not make the serializer builder loop: recursive schema-form array
  (`node = array of $ref node`) generates and serializes today -> regression test, builder needs an identity guard
- nested unevaluatedItems filter on additionalProperties-array values: same family as #213, verify and add to #213

## Further bugs found while probing (acknowledged, not caused by this topic)

### A. Recursive tuple array overflows the stack in the GENERATOR (pre-existing, no filter involved)
Schema (`recursiveTupleNoFilter.json`):
`definitions.node = {type: array, items: [{type: string}, {$ref: "#/definitions/node"}]}`, `properties.tree = {$ref: node}`.
`generateModels()` dies in php-micro-template `Render.php:584` with "Maximum call stack size ... reached.
Infinite recursion?". Reproduced against the sources of the PR's base commit (f1e1ad9) -> NOT introduced by PR #207.
The schema-form variant (`items: {$ref: node}`) generates fine. Consequence for this topic: a recursive array with a
filtered leaf is not expressible today (leaf next to the recursion needs either a tuple, which crashes, or a
composition branch with a filter, which is rejected: "A filter keyword inside a anyOf composition branch is not
supported"). Path chosen: DEFER (different subsystem: tuple validator rendering). Tracking: this note + a pinned
test; GitHub issue only after the user agrees.

### B. Nested unevaluatedItems crash -> GitHub issue #213 (decided with the user, see above)

## Design (decided without the user, low stakes - state as assumption)

Per array level one generated private method; the named property's `_serialize{Property}()` stays the entry point.
- Slot kinds: `leaf` (serializer call, optional output-type guard) or `nested` (call of the method of the inner
  array level, wrapped in `is_array($value)`; the inner level may be absent or a different type when a branch is
  inactive or the item schema is a composition of types).
- Guard propagation: a leaf is guarded by the output type when ANY declaration on the path from the property to the
  leaf is conditional (anyOf/oneOf/then/else). Reason: an inactive outer branch leaves the inner values raw even
  if the inner declaration itself is unconditional.
- Holders (additionalProperties, patternProperties, unevaluatedProperties): their serializers apply the
  serializer of a transforming filter per entry today. Extended: when the validation property is an array with
  filtered items the entry is run through the same level method. A holder property can never have both (invariant above).
- Dynamic slot (`unevaluatedItems` filter via `_evaluatedItemIndices`) stays root-only: below the root the indices
  are not tracked per inner array (see #213).

Rejected alternatives:
- Unrolled nested foreach loops generated inline: cannot terminate on a `$ref` cycle (needs identity guard AND
  gives up the cycle), variable names must be depth-suffixed, template needs recursion. A method per level
  handles cycles for free and keeps each template small.
- Runtime helper in the production library (`SerializableTrait`): needs a coordinated release of a second
  repository and hides generation-time knowledge (guards, options) behind a runtime lookup.
- Reusing the `_serialize<Name>` prefix for level methods: `SerializableTrait::_getCustomSerializerMethod()` resolves
  `'_serialize' . ucfirst($phpPropertyName)` for every property, so a level method named e.g. `_serializeTagsItems`
  would be taken for the serializer of a property called `tagsItems`. Use a prefix outside `_serialize*`.
- Name by `spl_object_id`/hash: breaks `GeneratedOutputDeterminismTest`. Use a deterministic per-schema counter.

## Phase B decisions and findings (implementation)

### Bug in my first implementation, found by a probe (recursive array with a filtered leaf)
`tree = array of anyOf[$ref tree, array of dateTime]` generated, but `toArray()` still gave `[[[]],...]`.
`buildArrayLevel()` used `$itemsSerializer ??= createSlot(...)`: the first declaration of the 'items' slot
(branch 1, the recursive nested level) was kept and the leaf of branch 2 was dropped. Before the nested levels
existed at most one declaration could contribute per slot, so `??=` was safe; with nested levels it is not.
Fixed in this change (test written first: `testRecursiveArrayWhichEndsInAnArrayOfFilteredItemsIsSerialized`).

### Slots merge their contributions (finishSlot)
A slot collects: the nested level methods of every declaration + at most one filter. Serialization of a value:
`is_array($value) ? <nested levels applied one after the other> : <filter, guarded by its output type>`.
Rejected alternatives:
- keep the first contribution (`??=`): hides the filter of later branches (the bug above)
- `if/elseif` chain rendered in the template: needs an index/first flag the template engine does not offer and
  a second place that knows the guard rules; the ternary composes into the existing {call, guard} pair, so
  templates (items, additional, pattern, unevaluated) stay unchanged in shape
- reject every slot with two contributions: would reject the legitimate "tree of arrays of dates" schema

### The "filtered once" rule counts filters of nested levels (ArrayItemFilterScope)
`assertItemSlotsAreFilteredOnce` compared only the direct filter of an item property. Alternatives whose filters sit
at different depths (`anyOf: [array of arrays of L1, array of arrays of L2]`) passed it, and the nested level methods
(each guarded by the output type) would apply the first level's serializer to a value the second branch produced.
Now every slot carries the SET of filter declarations reachable below it; two declarations of one slot conflict
unless their sets are equal.
Identity of a declaration = file + JSON pointer of the filter keyword. The object identity of the FilterValidator
was tried first and rejected: `$ref` resolution builds a validator of its own per reference, so the recursive tree
reached the same keyword with two different objects (pointer `/definitions/tree/items/anyOf/1/items/filter` both
times, verified with a debug print) and was rejected as "filtered more than once". Filter identifier
(token + option hash) was also rejected: two separate identical declarations must stay a conflict.
Consequence: a definition referenced twice for the same slot counts as one declaration (harmless, idempotent).

### Naming
Level methods: `Schema::reserveMethodName('_nestedItemsSerializer' . <property><Items|IndexN>...)`. Deterministic by
construction (function of the candidates requested for the class), prefix outside `_serialize*`.

### Verified facts
- Output of the unevaluatedProperties holder shows `'name' => null` for an unprovided declared property because
  the test harness enables implicit null by default - unrelated to this change; expectation corrected, input unchanged.
- Nested property names equal the array property's name (`tags`), error messages of nested levels say 'tags'.
