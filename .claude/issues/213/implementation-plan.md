# Issue 213 - unevaluatedItems of an array nested inside the items of another array

Working notes, never staged. Stage only explicit paths (`.claude/` is partly tracked: settings.local.json).

## Bug (verified)
Generated class for `tags: array<items: array(items:[int], unevaluatedItems:string)>` fatals on
`new Model(['tags' => [[1, 'a']]])`: `$this->_evaluatedItemIndices` undefined and
`collectUnevaluatedIndices()` undefined (no CompositionEvaluationTrait).

Cause 1: UnevaluatedPropertiesPostProcessor (needsActivation / activateArrayPropertyTracking /
assertNoUnsupportedDownPropagation) only inspects `$schema->getProperties()` + their composition
branches, never the nested array properties (items / tuple / additionalItems / contains /
unevaluatedItems) nor the validation properties of schema-level additionalProperties /
patternProperties / unevaluatedProperties.

Cause 2: `_evaluatedItemIndices` keyed by the NAME of the array property. The nested property of
schema-form `items` has the SAME name as the outer property, so levels and elements share one entry.

## Found along the way (pre-existing)
- Probe: `tags: {contains: {type: string}, unevaluatedItems: false}` mutable: after
  `new ['a']`, `setTags([1, 'b'])` is ACCEPTED (stale credit of index 0 from the previous pass),
  while `new [1,'b']` is rejected. RenderHelper::hasUnevaluatedItemsValidator() only recognises
  the schema-form UnevaluatedItemsValidator, so the reset of `_evaluatedItemIndices[<name>]` in
  Model.phptpl is never emitted for the `false` form. Same mechanism as this task (state must not
  leak between passes) and the nested scope needs the same predicate -> fixed in this change with
  its own test.

## Design decision
Chosen: stack-scoped evaluation state. Every chain which validates ONE element of an enclosing
container (schema-form items, tuple index, additionalItems, contains, unevaluatedItems, schema-level
additionalProperties / patternProperties / unevaluatedProperties) and whose property carries an
unevaluatedItems validator (directly or in a composition branch) is wrapped:
```
$savedEvaluatedItemIndices = $this->_evaluatedItemIndices['<name>'] ?? [];
$this->_evaluatedItemIndices['<name>'] = [];
try { <validators of the element> } finally { $this->_evaluatedItemIndices['<name>'] = $saved...; }
```
Key stays the property name (no change for the named property, serializer, ArrayContains, validators).

Rejected alternatives:
1. Per-location static key (JSON pointer) + reset at chain start, without restore: levels with the same
   name no longer collide, but a recursive definition (the same location live twice) and a branch
   `items` of the same array (nested chain runs between the sibling `contains` credit and the
   outer unevaluatedItems check) would wipe the credits of the outer array. Needs key plumbing into
   branches / contains / validators / serializer as well. More code, still not correct.
2. Runtime path key (`tags/3/1`): exact per-instance identity and would allow serialization of
   filters per inner array, but the outer index lives in a different PHP scope than the inner
   validators (extracted method for ArrayItem, IIFEs for composition / tuple / contains), every
   one of them would need an extra parameter or `use` entry. Disproportionate.
3. Reset after the check (cleanup at the end of the unevaluatedItems validator): not reached if an
   earlier validator throws in direct-exception mode; the next element would see stale credits.
4. Reset without finally: `continue` in UnevaluatedItems.phptpl (collectErrors) and exceptions
   skip the restore; the following element and the outer array read polluted state.
5. Rejecting nested unevaluatedItems with a SchemaException: user decided on real support.

Filters inside nested unevaluatedItems (transforming filter, serialization enabled):
the credits of a nested array are discarded when its scope ends (that is what makes the
elements independent), the serializer of the nested level could not know which indices the keyword
evaluated. Persisting them per inner array needs the runtime path key (alternative 2). Decision: reject at
generation time with a SchemaException (only when serialization is enabled - without serialization
nothing reads the indices and the transform works). See SerializationPostProcessor.

## Edge cases (scope: IN unless noted)
1. issue schema `[[1,'a']]` accepted; wrong types -> full messages (direct + collectErrors)
2. independence: `[[1,'a','b'],[2,3]]` second inner array index 1 must be rejected (leak check);
   `[[1,'a'],['x']]` tuple violation of 2nd; `[[1,'a','b'],[2]]` accepted
3. unevaluatedItems:false on nested (full message, getUnevaluatedItems)
4. three levels deep
5. nested array as tuple index of outer array (distinct name)
6. nested array held by additionalProperties / patternProperties value
7. composition branches on the nested array crediting indices (allOf tuple, contains in sibling
   branch) + slot key collisions between outer/nested compositions
8. contains sibling on nested array credits matched indices
9. setter re-validation, state not leaking; immutable model
10. object elements with own class: own unevaluatedItems keeps working, enclosing class NOT activated
11. nested array in additionalItems / contains / unevaluatedItems schema of an outer array
12. outer credits survive a nested scope (anyOf branch with nested items + contains)
13. recursion through $ref (same location live twice)
14. down-propagation rejection (branch unevaluatedItems + sibling claims) also for nested arrays
15. filters in nested unevaluatedItems: SchemaException when serialization enabled
16. stale credits: contains + unevaluatedItems:false setter (top-level, pre-existing)
Out of scope: boolean schema in tuple items (known gap), self-referencing tuple (#214).

## Phases
1. failing tests (red) 2. post processor activation 3. scope in templates 4. stale-credit fix
5. filter rejection 6. docs (filter.rst warning, array.rst) 7. full suite all drafts, phpcs, formatting,
   diff review, commits, push, PR

## Found along the way, unrelated (report, do not fix)
- Tuple array with schema-form additionalItems + implicit null enabled: `new Model([])` (property absent)
  -> TypeError array_keys(): Argument #1 must be of type array, null given (generated additionalItems
  validator runs on null). Reproduced on unmodified origin/master with
  `{tupleSlot: {type: array, items: [{type: integer}], additionalItems: {type: array}}}` and
  setImplicitNull(true). Pinned by a known-gap test in tests/Objects/TupleArrayPropertyTest.php.
- Tuple failures are numbered 1-based (`invalid tuple #1` for tuple index 0); documented in array.rst, left alone.
