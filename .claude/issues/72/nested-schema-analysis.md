# In-depth analysis: the nested-schema mechanism and the Phase 3 root cause

Purpose: before writing any further Phase 3 code, establish (a) what `nestedSchema` actually
means today, (b) every place that produces or consumes it and whether each use is sound, (c) why
every quick fix attempted so far failed, mechanically, and (d) what the root-cause fix has to
look like. This document is the foundation for the Phase 3 implementation plan.

All claims below are either verified by direct code reading (file/method named) or by running
generation probes (marked **[verified empirically]**). Nothing is inferred from memory of old
behavior.

---

## 1. What `nestedSchema` is

`PropertyInterface::setNestedSchema(Schema)/getNestedSchema(): ?Schema` attaches a generated
class (`Schema` = "one PHP class to be rendered") to a property. `PropertyProxy` (and therefore
`CompositionPropertyDecorator`, which extends it) delegates both calls to the wrapped property —
so a `$ref` proxy exposes the nested schema of the *definition* property it points to, and the
signal is shared across every use site of that definition.

### 1.1 Producers — exactly two

1. **`PropertyFactory::createObjectProperty()`** (`src/PropertyProcessor/PropertyFactory.php`):
   set when a property's JSON declares an **explicit `type: object`**. `processSchema()` builds
   the nested class; the property then gets, via `wireObjectProperty()` → `ObjectModifier`:
   - the nested `Schema`,
   - an `ObjectInstantiationDecorator` (renders `is_array($value) ? new ClassName($value) : $value`),
   - a concrete `PropertyType` (the class name),
   - an `InstanceOfValidator`,
   - namespace/import wiring.

   Note the routing in `PropertyFactory::create()`: **only** the literal `type: object` key
   reaches this path. A property whose JSON is `{"allOf": [...]}` (no `type`) routes to
   `createTypedProperty(..., 'any')` — even though `SchemaProcessor::processSchema()` itself is
   perfectly capable of processing composition-rooted schemas (it does so for every root-level
   class, line ~115: accepts `anyOf/allOf/oneOf/if/$ref` without `type: object`).

2. **`SchemaProcessor::createMergedProperty()`**: for a property-level `allOf`/`anyOf`, builds a
   synthetic `Property('MergedProperty', ...)` carrying the merged class (`..._Merged_...`), with
   its own nested schema + `ObjectInstantiationDecorator` + concrete type. Crucially this synthetic
   property is **not the outer property** — it hangs off the `ComposedPropertyValidator` as the
   `mergedProperty` template value (passed **by reference**, populated later in an `onResolve`
   callback in `AllOf/AnyOfValidatorFactory`). The outer property itself gets only a
   `CompositionTypeHintDecorator` (PHPDoc) — no nested schema, no type, no decorator.

### 1.2 The semantic gap, stated precisely

A property whose object-ness is **implied by composition** — `{"allOf": [...object branches...]}`
with no literal `type: object`, the standard style of real-world schemas like the FedEx one from
issue #72 — ends up with:

- `getNestedSchema()` → `null`
- `getType()` → `null`
- no instantiation decorator
- its actual object schema (the merged class) reachable **only** through its
  `ComposedPropertyValidator`'s `mergedProperty` reference, which nothing outside the validator
  and its render template can see.

Every consumer below that treats `getNestedSchema()` as a signal therefore mis-classifies these
properties. This is the single root cause behind the Phase 3 test failure and several silent
correctness gaps found during this analysis.

---

## 2. Consumer inventory — every read of `getNestedSchema()`, its intended meaning, verdict

Four distinct meanings are in circulation. Legend:
- **(A) "definitively an object"** — the value of this property can only ever be an object.
- **(B) "object merging is owned elsewhere"** — skip scalar-type logic for this property/branch.
- **(C) "has enumerable named properties"** — iterate `getNestedSchema()->getProperties()`.
- **(D) "has a generated class"** — class-name/imports/instantiation mechanics.

| # | Site | Meaning | Verdict on the implementation as-is |
|---|------|---------|-------------------------------------|
| 1 | `ObjectModifier::modify` (reads it to wire decorator/type/instanceof) | D | Sound. Runs only on the explicit-object path; correct there. |
| 2 | `FilterValidator::runCompatibilityCheck` line 154: `nestedSchema !== null → typeNames = ['object']` | **A** | Sound **under today's producers** (both producers guarantee object-ness). This is the site that makes naive broadening of the producer set dangerous: an `anyOf` outer property is *not* definitively object even when one branch is. The fallback path (schema-declared `type`, else `detectDeadFilterViaAllOfConstraints`) is a deliberately more careful analysis for exactly the "type implied by composition" case — the shortcut must never pre-empt it for properties that are only *possibly* objects. **[verified empirically: setting nested schema on an anyOf outer property produced a false-positive "Filter dateTime is not compatible with property type object" on a schema that must generate]** |
| 3 | `FilterProcessor::addExtendedInstanceOfCheckForObjectBranches` line 256 (branch has nested schema with **no** properties → add post-transform instanceof guard) | C+D | Sound. Empty-object branches accept any object; the guard re-checks filter output types. Relies correctly on "generated class exists, no declared properties." |
| 4 | `AbstractCompositionValidatorFactory::getCompositionProperties` line ~311 (strip `InstanceOfValidator` from empty-object branches) and line ~338 (suppress type-hint decorator for merged object branches) | C, B | Sound for its purpose. |
| 5 | `AbstractCompositionValidatorFactory::transferPropertyType` line 523 (early return when any branch has a nested schema; Phase 0's `assertNoObjectScalarTypeConflict` hooks here) | B | Sound as a guard, but **blind to composition-implied-object branches**: an allOf mixing a scalar branch with a `$ref`-to-allOf-only branch is NOT caught by Phase 0 (no branch has a nested schema) and falls through to scalar intersection, which sees only the scalar branch. Collateral gap of the root cause. |
| 6 | `IfValidatorFactory` line 391 (skip type merge when then/else have nested schemas) | B | Sound; same blindness caveat as #5 for implied-object then/else branches (false "conflict" risk is avoided today only because implied-object branches have `getType() === null`). |
| 7 | `PropertyMerger::merge` lines 58-59 (no-op when either side has a nested schema) | B | Sound; same blindness caveat — two implied-object properties of the same name would be "merged" via scalar union logic on `null` types (currently a silent no-op by luck of both types being null). |
| 8 | `SchemaProcessor::redirectMergedProperty` line 319 (count object branches: 0 → null, 1 → redirect, ≥2 → build merged class) | **B/C — the Phase 3 trigger** | Sound logic, wrong input: an implied-object branch counts as "not an object branch", so a two-level `allOf` chain miscounts its branches, redirecting where it should merge (or building a merged class missing a branch's properties). This is where the Phase 3 defect enters. |
| 9 | `SchemaProcessor::transferPropertiesToMergedSchema` line 340 (skip branches without nested schema) | C | Same blindness: implied-object branch properties are silently absent from the merged class. |
| 10 | `SchemaProcessor::transferComposedPropertiesToSchema` line 523 (root-level; Phase 1 changed crash → skip for `type === null` branches) | C | **Phase 1 introduced a new silent failure here [verified empirically]**: a root-level `allOf` with a `$ref` branch to an allOf-only definition now generates a class silently missing that branch's properties (`getName`/`getSalary` gone, only `getAge` present). Pre-Phase-1 this was the loud "No nested schema" crash. The Phase 1 skip is correct for genuinely vacuous branches but wrong for implied-object branches — it cannot currently tell them apart because both have `nestedSchema === null && getType() === null`. **Must be fixed by Phase 3, and is an argument that Phase 3 is not optional.** |
| 11 | `SchemaProcessor::exclusiveBranchPropertyNeedsWidening` line 713 | C | Blindness caveat only; benign today (treats implied-object branch as having no properties → may widen unnecessarily). |
| 12 | `SchemaProcessor::checkCrossBranchDefaultConflicts` line 880 | C | Blindness caveat: defaults inside implied-object branches escape conflict detection. |
| 13 | `AbstractComposedPropertyValidator::hasNestedSchemaWithProperties` / `setupBranchDefaultHelpers` (lines 48/84) | C | Blindness caveat: branch defaults in implied-object branches don't get the branch-default machinery. |
| 14 | `PropertyAttributeSynthesizer` (lines 75/88/158) | C | Blindness caveat: attributes (JsonPointer/JsonSchema) not synthesized for properties contributed via implied-object branches. |
| 15 | `CompositionValidationPostProcessor::generateValidatorPropertyMap` line 66 | C | Blindness caveat: setter revalidation wiring misses properties from implied-object branches (mutable models only). |
| 16 | `CompositionRequiredPromotionPostProcessor` lines 76/99 (`?->getJsonSchema()...['required'] ?? []`) | C | Blindness caveat: `required` inside an implied-object branch is silently treated as "nothing required". |
| 17 | `PropertyFactory::processBaseReference` line 392 (root `$ref` must resolve to object definition) | C/D | **Directly affected**: a root-level `$ref` to an allOf-only definition throws "A referenced schema on base level must provide an object definition" even though it *is* one. Untested/unverified, flagged as a probable additional symptom. |

**Summary verdict**: no consumer is individually mis-implemented; every site reads the signal in a
way that is coherent *given what the producers currently guarantee* (nested schema ⇒ definitively
object with a generated class and enumerable properties). The defect is exclusively on the
**producer side**: one legitimate class of object-shaped properties never gets the signal, and
there is no second signal to compensate. The consumers then fail *in unison*, which is why the
symptom count kept growing the closer we looked (Phase 3 test, root-level silent drop, required-
promotion, attribute synthesis, setter revalidation, base-$ref rejection...).

---

## 3. The runtime half: how instantiation actually works, and why the `is_object($proposedValue)` gate is not the bug

`ComposedItem.phptpl` (rendered into every composed validator):

1. Per branch: `viewHelper.resolvePropertyDecorator(compositionProperty)` renders the **branch
   property's own decorators** — for an explicit-object branch this is its
   `ObjectInstantiationDecorator`, so `$value` becomes a branch-class instance *inside* that
   branch's try-block; then the branch's validators (including its `InstanceOfValidator`) run.
   `$proposedValue ??= $value` captures the (possibly instantiated) value; `$value` is reset to
   the original array after every branch.
2. After all branches, if a `mergedProperty` exists:
   `if (is_object($proposedValue)) { $value = array_merge(original, modifiedValues); resolve
   mergedProperty decorator → is_array($value) ? new Merged(...) : $value } else { $value =
   $proposedValue }`.

Two important conclusions from tracing this **[verified against generated code for both a
working explicit-object schema and the failing implied-object schema]**:

- The `is_object($proposedValue)` gate is **per-value dispatch, not a schema-shape proxy**: for a
  mixed `anyOf` (object branch | string branch) it correctly instantiates for object inputs and
  passes scalars through. It is *not* wrong and should not be "fixed" at the template level —
  doing so would break mixed-type compositions (this rules out the "Option B / template fix"
  considered earlier, on correctness grounds rather than just blast-radius grounds).
- The gate never fires for implied-object compositions **only because step 1 never instantiates**:
  an implied-object branch (proxy to an allOf-only definition) has no instantiation decorator, so
  no branch ever proposes an object, so the merged decorator is skipped and the raw array survives.
  The runtime failure is a direct downstream effect of the same missing producer-side signal.

### 3.1 Why the two attempted fixes failed — the decorator dual-context problem

A property's decorators render in **two different contexts** with incompatible ordering needs:

- **Context (a): as a branch inside another composition** — `resolvePropertyDecorator(branch)`
  inside `ComposedItem.phptpl`, *before* that branch's validators. Instantiation here is exactly
  what the cascade needs.
- **Context (b): the property's own value pipeline** — decorators render around the property's
  own validator chain. For explicit-object properties instantiation-then-InstanceOf is the
  designed order. For a *composition outer property*, the `ComposedPropertyValidator` itself owns
  instantiation (via `mergedProperty` at the *end* of its template); an instantiation decorator on
  the outer property fires **before** the composed validator, coercing the raw array into the
  merged class so every per-branch `InstanceOfValidator` (expecting branch classes) fails.
  **[verified empirically: full wiring broke 40 tests with exactly this "Invalid class ... got
  ..._Merged_..." signature]**

Because decorators live on the (shared, `$ref`-deduplicated) definition property and are rendered
in both contexts, "just add the decorator to the outer property" is structurally unable to work.
Any correct design must either (i) separate the two contexts (per-use-site decorators on the
`CompositionPropertyDecorator` wrapper, which is created fresh per composition use — currently
impossible since `PropertyProxy::addDecorator` delegates to the shared property), or (ii) make
context (b) rendering skip instantiation decorators for properties governed by a composed
validator.

### 3.2 The allOf/anyOf asymmetry

- `allOf` with ≥1 object branch ⇒ the property value **must** be an object ⇒ the outer property
  is *definitively* object (meaning A may be granted).
- `anyOf`/`oneOf` with an object branch ⇒ the value **may** be an object ⇒ meaning A must NOT be
  granted to the outer property (FilterValidator regression proves this), even though meanings
  B/C/D apply to the *branch*.

Any fix that wires a single boolean-ish signal (`setNestedSchema`) onto outer properties without
distinguishing composition kind repeats the FilterValidator regression. This asymmetry is the
second reason the quick fixes failed.

---

## 4. Root-cause statement

> The pipeline has no representation for "this property is object-shaped by composition." The
> only object signal (`nestedSchema`) is produced solely for the literal `type: object` shape and
> simultaneously carries four meanings (definitively-object / merge-ownership / enumerable
> properties / generated class). For composition-implied objects, the equivalent information
> exists — as the merged property — but is buried inside the `ComposedPropertyValidator` where
> only the render template can reach it. Every consumer that keys off `nestedSchema` therefore
> mis-handles composition-implied objects, each with its own symptom.

`PropertyFactory::create()`'s routing (`type: object` string match) is where the asymmetry is
born; `createMergedProperty()` is where the compensating information is produced but hidden.

---

## 5. Design directions for the fix (assessment, no decision — input for the implementation plan)

### Direction 1 — dedicated second signal: expose the merged schema on the property

Add to `PropertyInterface` something like `getObjectSchema(): ?Schema` (naming TBD) defined as
"the schema of the generated class representing this property's object shape, whether explicit
(`nestedSchema`) or composition-derived (merged property's nested schema)", populated by
`createMergedProperty()` on the outer property alongside the existing hidden reference; plus a
distinct query for meaning A ("definitively object") that accounts for the allOf/anyOf asymmetry
(e.g. only allOf outer properties / explicit objects return true).

- Consumers then migrate **selectively**: meaning-C consumers (#5, 8-16) read the new accessor;
  meaning-A consumer (#2, FilterValidator) reads the definitively-object query; meaning-B guards
  can use either as appropriate; meaning-D stays on `nestedSchema`.
- Pros: additive, each consumer's semantics becomes explicit, no change to what `nestedSchema`
  means (no risk to consumers not migrated), directly fixes #8/#9/#10 (the structural half of
  Phase 3) and the collateral gaps.
- Cons: does not by itself fix the runtime half (§3.1) — branch-context instantiation still needs
  a mechanism; touches many consumer sites (though each change is small and independently
  testable).

### Direction 2 — normalize at the source: route composition-only schemas through the object path

Change `PropertyFactory::create()` routing so `{"allOf": [...]}`-style property schemas (allOf
only, or any composition whose branches are provably all-object?) are processed like object
properties (`processSchema` handles composition roots already). The composition would then be
handled *inside* the nested class (as it is for root-level classes today) instead of on the outer
property.

- Pros: attacks the asymmetry at its birthplace; outer property becomes a plain object property
  (all four meanings correct for free, including runtime instantiation); mirrors the existing,
  well-tested root-level treatment of composition-rooted schemas. Conceptually the most unifying:
  "a property-level composition of objects behaves exactly like a root-level composition."
- Cons: highest behavioral impact — class naming changes (`..._Merged_...` vs nested-class
  names), `shouldSkip()` in the composition factories (which currently suppresses property-level
  processing for explicit-object properties to avoid double validation) would need extension, and
  the whole property-level merged-property machinery would be bypassed for these shapes; needs
  careful mapping of which existing tests encode the current merged-class naming/behavior as
  intended behavior. Riskiest to land, but arguably the "right" long-term shape. Cannot handle
  mixed-type `anyOf` (those must stay on the current outer-property mechanism), so the routing
  condition needs to be precise — probably "composition where the property cannot be anything but
  an object", which is itself only decidable after branch resolution (chicken-and-egg with $refs;
  needs feasibility investigation).

### Direction 3 — per-use-site branch decorators (runtime half only)

Give `CompositionPropertyDecorator` its own local decorator list (override
`addDecorator`/`resolveDecorator`/`getDecorators` instead of delegating), so composition
factories can attach an instantiation decorator to *a branch use-site* without contaminating the
shared definition property's own pipeline (solves §3.1 context conflict). The branch decorator
would instantiate the branch's merged class when the branch is an implied object.

- Pros: precisely targets the runtime gap; no shared-property contamination; small surface.
- Cons: only the runtime half — must be combined with Direction 1 for the structural half; care
  needed with the existing synchronous-vs-deferred resolution timing (merged property exists only
  after all branches resolve, so the decorator must resolve the class name lazily, similar to the
  existing by-reference `mergedProperty` template value).

### Likely overall shape

**Superseded by the Direction 2 deep dive in §8** (requested by the maintainer with the explicit
reasoning that if Direction 2 is the best long-term investment, it should be tackled now rather
than building a temporary bridge with Directions 1/3 plus a later refactoring). Conclusion of §8:
Direction 2 is feasible, structurally superior, and makes Directions 1 and 3 unnecessary for the
object-composition cases; a small companion fix remains for scalar nested compositions (§9.4).

---

## 6. Additional findings that must not get lost

1. **Phase 1 silent drop at root level [verified empirically]** (§2 #10): must be re-guarded as
   part of Phase 3 — the skip is only legitimate for branches with no object shape at all. Until
   Phase 3 lands, this is a live silent-correctness regression relative to the pre-Phase-1 loud
   crash for exactly one shape: root-level composition with a `$ref` branch to a composition-only
   definition. A characterization test should pin the corrected behavior (branch properties
   present) as part of Phase 3.
2. **Phase 0 blindness** (§2 #5): the object-vs-scalar allOf conflict check does not fire when the
   object side is composition-implied. Once the new signal exists, `assertNoObjectScalarTypeConflict`
   should consume it.
3. **`processBaseReference` rejection** (§2 #17): root-level `$ref` to an allOf-only definition
   probably throws a false "must provide an object definition" — verify and cover in Phase 3.
4. The collateral meaning-C consumers (#11-16) each deserve one targeted test once the signal
   exists (required-promotion, cross-branch defaults, attribute synthesis, setter revalidation,
   widening) — enumerate them as explicit edge cases in the implementation plan per CLAUDE.md's
   test-coverage rule.
5. `Schema` reuse caveat: definition properties are shared via `$ref` proxies and
   `processedSchema`/`processedMergedProperties` are deduplicated by signature — any new signal
   must be set on the shared property exactly once and must be safe under signature-based reuse
   (same reason `createMergedProperty` caches by signature).

---

## 7. What was explicitly ruled out (with evidence)

- **Wiring `ObjectInstantiationDecorator` + type onto composition outer properties**
  (first attempt): breaks context (b) ordering; 40 test failures with premature merged-class
  coercion. See §3.1.
- **`setNestedSchema` alone on outer properties** (second attempt): grants meaning A to
  properties that are not definitively objects (anyOf redirect case) → FilterValidator false
  positives; 2 test failures. See §2 #2 and §3.2.
- **Relaxing the `is_object($proposedValue)` template gate**: the gate is correct per-value
  dispatch for mixed-type compositions; loosening it would instantiate merged classes for values
  that legitimately took a scalar branch. See §3.

---

## 8. Deep dive: Direction 2 feasibility (requested follow-up)

Question under investigation: is routing composition-only property schemas through the object
path feasible **now**, as the root-cause fix, instead of bridging with Directions 1/3 and
refactoring later?

**Verdict up front: yes — feasible, and it is the only direction that makes the `nestedSchema`
contract (§10) actually true instead of compensating for it being false. Recommended.** The rest
of this section is the evidence, the precise routing rule, what changes observably, and the
risks.

### 8.1 The key discovery that makes Direction 2 small: the target mechanism already exists

Direction 2 initially looked like a big refactor. It is not — because the generator **already
normalizes naked compositions into object classes in one place**: at the schema root,
`inheritPropertyType()` injects the parent's `type: object` into every untyped branch, which
routes that branch through `createObjectProperty()` → a real nested class whose own (root-level)
processing handles the branch's internal composition. **[verified empirically]**: a root-level
`allOf` branch `{anyOf: [{required: [q]}, {minProperties: 2}]}` generates a dedicated branch
class and validates correctly (empty object rejected, valid object accepted) — the generated
code instantiates the branch class inside the composed validator, exactly the pattern Phase 3
needs.

So the entire "Direction 2 machinery" — composition-rooted class generation
(`processSchema` accepts `anyOf/allOf/oneOf/if/$ref` roots), property transfer
(`transferComposedPropertiesToSchema`), base-validator rendering, per-branch instantiation — is
existing, tested, load-bearing code. Direction 2 only extends *where the normalization decision
is made*: today it happens implicitly via type inheritance (and therefore breaks exactly where
inheritance cannot reach — `$ref` branches lose injected siblings per Draft 7, and untyped
*property* schemas have no parent type to inherit). Direction 2 makes the decision explicit and
`$ref`-safe.

### 8.2 Proposed routing rule (conservative, decidable)

In `PropertyFactory::create()`, route a property schema through the object path
(`createObjectProperty()`-equivalent: `processSchema` + `wireObjectProperty`) when **all** of:

1. no `type` key (explicit types keep their current routing, including `type: object`);
2. no `filter` key (the filter-composition subsystem — input/output space classification,
   `CompositionBranchClassifier`, dead-filter detection — is purpose-built for compositions
   living *on* the filtered outer property and must keep owning those schemas; this exclusion
   also preserves the specialized dead-filter diagnostics that the earlier `setNestedSchema`
   attempt broke);
3. the composition keyword is `allOf` (initial scope — see 8.5 for anyOf/oneOf);
4. **every** branch is statically object-shaped after resolving `$ref` chains: explicit
   `type: object`, or object-implying keywords (`properties`, `required`, `patternProperties`,
   `additionalProperties`, `propertyNames`, `minProperties`, `maxProperties`, `dependencies`)
   without a conflicting type, or recursively a composition satisfying this same rule
   (multi-level chains), or boolean `true`/vacuous branches (which impose nothing and are
   handled by Phases 1/2 semantics inside the class).

Anything not matching → **current path, unchanged**. This fallback is what makes Direction 2
safe to land incrementally: it only claims schemas where object-ness is *certain* — precisely
the set where granting meaning A is correct — so the FilterValidator/anyOf asymmetry problem
(§3.2) cannot re-occur by construction.

Decidability of rule 4: branch `$ref`s must be peeked at statically. The machinery exists —
`SchemaDefinitionDictionary::getDefinition()` + `JsonSchema::navigate()` yield the raw JSON of a
definition (including external files via `parseExternalFile`). A small recursive resolver with a
visited-set (cycle → bail out to the old path; a top-level-`$ref`-cyclic composition is
degenerate anyway) covers it. Recursion through branch `properties` is *not* followed — only
branch-top-level `$ref`/composition nesting — so the check is shallow and cheap.

### 8.3 Why the whole Phase 3 symptom set collapses, by construction

Walking the issue #72 chain under the rule: `contract` (explicit object) → class, as today.
`identification` = `allOf: [$ref contract, inline object]` → both branches object-shaped →
re-routed → a real class whose root-level processing transfers `salary` + `name` and validates
the composition internally. `basic` → same, now seeing `identification` as a genuine object
property (nested schema present on the definition property). `CEO` → same. Every level is a
plain object property with nested schema, concrete type, instantiation decorator,
`InstanceOfValidator` — all four meanings of §2 are *true*, not simulated:

- Phase 3 test (raw array → object with getters): fixed — the outer property is an object
  property; `getCEO(): CEO_class` with working getters at every level.
- §3.1 dual-context problem: **dissolves**. Both rendering contexts want instantiation because
  the property genuinely is an object property in both. No decorator conflict is possible.
- §2 #10 (Phase 1 root-level silent drop) **[the verified regression]**: fixed — the `$ref`
  branch to a composition-only definition now has a nested schema, so
  `transferComposedPropertiesToSchema` transfers its properties instead of skipping.
- §2 #17 (`processBaseReference` false rejection): fixed the same way.
- All meaning-C consumers (#5, #8-#16: required-promotion, cross-branch defaults, attribute
  synthesis, setter revalidation, widening, merged-schema transfer): fixed **globally**, because
  definitions are re-routed *at definition-property creation* — so even a mixed `anyOf:
  [$ref allOfOnlyDef, {type: string}]` (which itself stays on the old path, correctly) has a
  branch proxy that exposes a real nested schema. Verified reasoning for the runtime of that
  mixed case: branch 1's decorators (now genuine object-property decorators, rendered per-branch
  by `ComposedItem.phptpl`) instantiate the definition class; `redirectMergedProperty` finds one
  object branch → redirect; string inputs take the scalar branch via the per-value
  `is_object($proposedValue)` gate — the exact flow that already works for explicit-object
  branches today.
- Phase 0 blindness (§2 #5): an `allOf` mixing a scalar branch with a `$ref`-to-allOf-only
  branch — the routing rule rejects it (not all branches object-shaped) → old path → the branch
  now *has* a nested schema → Phase 0's `assertNoObjectScalarTypeConflict` fires. Fixed without
  touching Phase 0.

### 8.4 Observable behavior changes (intended, must be documented + tests migrated)

1. **Class naming**: property-level all-object `allOf` currently yields `..._Merged_...`
   classes (or a redirect to a branch class); re-routed schemas yield regular nested-class names
   derived from the property/definition name. Affected assertions include
   `ComposedAllOfTest::testIdenticalMergedSchemaIsRedirected` (asserts `_Merged_CEO` PHPDoc) —
   note its *semantic* core survives: CEO/CFO have identical JSON → identical signature →
   `generateModel`'s signature dedup returns the same class, so `getCEO()::class ===
   getCFO()::class` still holds; only the name pattern changes. Class *count* changes too (no
   separate `_Merged_` class alongside the outer machinery).
2. **`$ref`-with-siblings** (`{"$ref": X, "properties": ...}`): the `JsonSchema` constructor
   rewrites these into `allOf: [{$ref X}, {...}]` — meaning this widely-used shape feeds the new
   routing rule whenever X and the siblings are object-shaped. That is the *desired* unification
   (these produce `_Merged_` classes today) but multiplies the affected-test surface
   (`RequiredReferencePropertyInComposition`, `IdenticalNestedSchema*`, `ArrayPropertyCombinedObject`,
   PhpAttribute pointer tests, ...). The migration audit must run the full suite and classify
   every failure as naming-only vs. behavioral.
3. **Error shapes**: outer-level "Invalid value for CEO declined by composition constraint"
   with per-branch details becomes a nested-class instantiation error carrying the same
   composition details from inside the class (the root-level composition error format — already
   the format users see for root compositions today). Tests asserting the exact property-level
   format for all-object compositions need migration.
4. **PHPDoc/native types**: getters gain real class return types instead of `mixed` +
   multi-class `@return` unions — strictly better, but assertions on the old annotations change.

### 8.5 Scope boundaries and residuals

**Revised after the cross-keyword verification in §8.8** (maintainer challenge: limiting the
evaluation to `allOf` is dangerous without knowing how the other keywords behave — confirmed
correct, all keywords are affected).

- **Outer-keyword re-routing** applies to `allOf` only: it is the only keyword where the
  composition itself implies a single object class for the property value. oneOf's current
  value semantics (`mergedProperty => null`; the value is the *matched branch's* instance, not a
  merged class) is a deliberate, defensible design — re-routing oneOf would change the runtime
  value identity. anyOf's merged-class-at-runtime semantics equally live on the outer property
  correctly.
- **Definition-level re-routing is what fixes the other keywords** (§8.8): the broken piece for
  `anyOf`/`oneOf`/`if`/`not` is not the outer machinery (verified correct with explicit object
  branches) but the *branches* being composition-implied definitions. Re-routing happens where
  the definition property is created, so every keyword's branches become genuine object
  properties and the existing (gold-verified) outer machinery takes over unchanged.
- **Mixed-type compositions and filter-bearing properties**: stay on the old path permanently —
  the old machinery is *correct* for them (per-value dispatch, filter space classification);
  after Direction 2 each mechanism handles exactly the cases it is suited for.
- **Scalar nested compositions**: NOT fixed by Direction 2 (routing rule rejects them) — and
  they are currently a *complete validation hole* (§9, reported as GitHub issue #167).
  Companion fix required; see §9.4.

### 8.8 Cross-keyword verification: all composition keywords are broken with implied-object branches

Method **[verified empirically, both halves]**: for each keyword, first generate the
*explicit-object-branch* variant to establish the gold-standard behavior the implied variant
must match, then generate the *implied-object-branch* variant (branches are `$ref`s to
allOf-only definitions) and compare. Schemas now live in `tests/Schema/Issues/72/`
(`NestedAnyOf.json`, `NestedOneOf.json`, `NestedIfThenElse.json`, `NestedNot.json`) with
red-until-fixed tests in `Issue72Test`.

| Keyword | Gold standard (explicit object branches) | Actual with implied-object branches | Failure mode |
|---|---|---|---|
| `anyOf` | merged-class instance; non-matching input rejected | **everything accepted** (incl. `{}` and bare `42`), value stays raw array/scalar | silent over-acceptance — the literal issue #72 symptom |
| `oneOf` | matched branch's class instance; both/neither rejected | **everything rejected** ("matched 2 elements" — both stripped branches trivially match) | total over-rejection |
| `if/then/else` | taken branch's class instance; branch-violating input rejected | condition routes, but the taken branch **enforces nothing**; value stays raw array | silent over-acceptance |
| `not` | value stays raw array (no class represents "everything except X" — validation only); forbidden-matching input rejected | **everything rejected** (stripped forbidden-branch always "matches" → not always fails) | total over-rejection |

Mechanism is identical in all four cases and identical to the allOf case: the implied-object
branch has no generated class, so (a) its composed validator is stripped
(`getCompositionProperties()` filter — the §9 mechanism) leaving the branch without any
validation, and (b) no per-branch instantiation ever happens.

Consequences for the fix decision:

1. The maintainer's caution was justified: an allOf-only fix would leave four silently- or
   loudly-broken keywords behind.
2. Direction 2 **does** cover all keywords — but through its *definition-level* half, not the
   outer-keyword half: the branches in all four probes are `$ref`s to composition-only
   definitions; re-routing those definitions into object classes gives every keyword real object
   branches, and the gold probes prove the outer machinery is already correct for real object
   branches. The outer-keyword re-routing (8.2 rule 3) remains allOf-scoped.
3. The `not` gold standard settles a contract question (§10): `not` values legitimately stay
   raw arrays — `not` has no value-representing class — so the fix must NOT try to instantiate
   there; only validation must be restored.
4. Inline (non-`$ref`) implied-object branches — e.g. `anyOf: [{allOf: [...]}, ...]` written
   inline — are NOT covered by definition-level re-routing and need the routing predicate
   applied to inline branch schemas as well (same predicate, applied when
   `getCompositionProperties()`/`PropertyFactory::create()` processes an inline branch that is
   itself composition-only). **[verified empirically]**: inline variants
   (`NestedAnyOfInline/NestedOneOfInline/NestedIfThenElseInline/NestedNotInline.json`) fail
   identically to their `$ref` counterparts — same mechanism, same failure modes — and are now
   covered by the same red-until-fixed tests via schema-file data providers.

### 8.9 Mixed compositions: implied-object branch + scalar branch **[verified empirically]**

Maintainer question: do `oneOf`/`anyOf`/`if-then-else` with one (implied) object branch and one
scalar branch need different checking? Verified by gold-vs-implied comparison
(`Nested{AnyOf,OneOf,IfThenElse}MixedScalar.json`, `NestedAllOfMixedScalarConflict.json`):

| Shape | Gold (explicit object branch + `{type: string}`) | Current with implied branch |
|---|---|---|
| `anyOf` mixed | object → branch-class instance; string → string; `42`/`{}` → rejected | object → raw array; **`42` and `{}` silently accepted** |
| `oneOf` mixed | same as anyOf gold | **fully inverted**: valid string → *rejected* ("matched 2" — stripped branch + string branch), invalid `42` → *accepted* (matches only the stripped branch); object → raw array |
| `if/then/else` (object-condition, implied then, scalar else) | object → then, instance; string → else; violations rejected | then-branch enforces nothing: `{}` accepted; object → raw array; else path works |
| `allOf` mixed (unsatisfiable per spec) | n/a — must be a generation-time conflict | **silently generates an inverted model**: accepts plain strings (implied branch enforces nothing), rejects the objects the implied branch describes; the conflict is invisible to Phase 0 (implied branch has neither nested schema nor type) |

Answer to the question: **no separate mechanism is needed** — the gold rows prove the existing
outer machinery (per-value dispatch, redirect/merge, conditional routing) already handles mixed
compositions correctly once the object branch is a genuine object property. The fix surface is
identical to §8.8: make implied branches real (definition-level + inline-branch re-routing).
The one exception is `allOf`-mixed, which is not a runtime-dispatch case but an unsatisfiable
schema: it must produce the same generation-time "conflicting types" `SchemaException` as the
explicit equivalent (Phase 0), which the new signal makes detectable
(`testAllOfMixingImpliedObjectAndScalarBranchThrowsConflictingTypesException`, red).

### 8.10 When exactly is the object type implied? (routing predicate refinement)

Maintainer question: under which circumstances do we imply object — also when no `type` is
present but object validators are? Refined predicate for "branch/schema is object-shaped",
superseding 8.2 rule 4:

1. Explicit `type: object` → object (existing behavior, not re-routed at property level unless
   composition-only — explicit objects already work).
2. No `type`, has composition keyword(s), all branches object-shaped (recursive) → implied
   object. **[verified: currently broken everywhere, §8.8]**
3. No `type`, no composition, but **object-constraining keywords present** (`properties`,
   `required`, `patternProperties`, `additionalProperties`, `propertyNames`, `minProperties`,
   `maxProperties`, `dependencies`) → the "bare object-validator" case. **[verified empirically,
   `Nested{OneOf,AnyOf}BareObjectValidators.json`]**: currently equally broken — the bare
   branch is processed as untyped, its object validators (registered on `Type('object')`) never
   run, so it matches everything: bare-`oneOf` rejects every value including valid ones;
   bare-`anyOf` accepts every value including an empty object that fails `required` in both
   branches (spec-invalid).
   **Semantic subtlety**: for *oneOf*, strict-spec semantics (a non-object matches every bare
   branch vacuously → "matched 2" → rejected) and object-implied semantics (a non-object matches
   none → rejected) give **identical accept/reject outcomes** — only the failure reason differs,
   so the fix is decision-free there. For *anyOf* they **diverge on non-object values**: strict
   spec accepts `42` (vacuous match on both branches), object-implied rejects it.
   **RESOLVED by the maintainer: strict spec.** Non-object values in a bare-validator `anyOf`
   are accepted via vacuous matches; overriding spec semantics must remain a narrowly
   whitelisted opt-in (consistent with the Phase 2 example-only whitelist ruling), and authors
   who mean objects can declare `type: object`. Pinned by
   `testAnyOfWithBareObjectValidatorBranchesAcceptsNonObjectValuePerSpec` (green today — the
   right outcome, currently for the wrong reason — and guards against a future object-implied
   fix silently flipping it). Consequence for the §8.10 predicate: **bare object-validator
   subschemas are NOT object-implied for value acceptance**; their fix is limited to making the
   object validators actually run against object values (per-branch validation restoration, the
   #167 family), not to typing the branch. Implementation may add a generation-time warning that
   such branches do not constrain non-object values.
4. `type` is an array (e.g. `["object", "string"]`) → NOT implied-object (not exclusively
   object-valued); never re-routed.
5. Everything else (scalar types, scalar compositions, vacuous/boolean branches) → not object.

Also verified while probing: `required`/`properties` DO constrain actual objects even under
strict spec (an empty object fails a bare `{required: [name]}` branch) — the vacuous-match
question exists only for non-object values.

### 8.6 Risks and open implementation questions

1. **Static `$ref` peek side effects**: resolving a `$ref` for the routing decision may trigger
   `parseExternalFile`/`processTopLevelSchema` slightly earlier than today. The same calls
   happen during normal resolution moments later, and the external-schema machinery was made
   order-independent (issue #116 work), so this should be a no-op in effect — but it needs a
   dedicated test with external-file refs in both discovery orders.
2. **Test migration volume**: the categories in 8.4 — an estimated 15-25 test methods asserting
   `_Merged_` naming, class counts, or property-level composition error formats. Bounded,
   enumerable via a full-suite run on a spike branch, but not small. Per CLAUDE.md, each change
   must be classified as intended-behavior-change (documented) — never silently weakened.
3. **Unsatisfiable edge**: `{type: object}` parent with a *scalar* nested composition branch
   (`allOf: [{anyOf: [{type: string}]}]` under an object parent) is unsatisfiable per JSON
   Schema; today's inheritance-based path produces a cross-typed nested class. Routing rule 4
   rejects it (branch not object-shaped) → old path → unchanged behavior; a loud Phase-0-style
   rejection for this shape is a possible follow-up, not a Direction 2 blocker.
4. **`shouldSkip()` interplay**: none needed — the re-routed property enters `processSchema`,
   whose nested class processes the composition as a `BaseProperty` (not skipped), identical to
   root-level today. Verified by code path reading (`generateModel` forces `type: 'base'`).

### 8.7 Effort estimate and comparison to bridging (1+3)

Direction 2: one routing predicate + static branch resolver in `PropertyFactory` (new,
self-contained), zero new signals, zero consumer migrations, plus the test-migration audit.
Directions 1+3 bridging: new interface surface (`getObjectSchema()` + definitively-object query
+ per-use-site decorators), ~16 consumer sites individually assessed/migrated, the runtime
lazy-class-name decorator mechanism — and *all of it becomes obsolete* the day Direction 2
lands, while the test-migration cost of Direction 2 remains payable then anyway. The bridge is
not cheaper; it is deferred-cost plus throwaway work. This confirms the maintainer's instinct:
tackle Direction 2 now.

---

## 9. Nested compositions in general (maintainer note 1)

How the current implementation handles a composition keyword *inside* a composition branch —
same-keyword (`allOf` in `allOf`) and mixed (`anyOf`/`oneOf` inside `allOf` branches).

### 9.1 Two mechanisms, split by where the nesting occurs

- **Root level / explicit-object outer property**: nested compositions work **via the
  normalization described in §8.1** — the untyped inner-composition branch inherits
  `type: object`, becomes its own class, and that class's root-level processing handles the
  inner composition (recursively, to any depth). **[verified empirically — correct rejection
  and acceptance]**. Caveat: this breaks for `$ref` branches (inherited type dropped — the
  §2a mechanism) and produces semantically questionable classes when the inner composition is
  scalar under an object parent (unsatisfiable schema, 8.6.3).
- **Property level with an untyped outer property** (`p: {allOf: [{anyOf: [...]}, ...]}`):
  **completely broken — a silent validation hole [verified empirically]**. The nested
  composition validators are added to the branch properties by the factories, then **stripped**
  by `getCompositionProperties()`'s `filterValidators` (removes `ComposedPropertyValidator`
  from every branch) on the assumption — valid only for object branches — that "the nested
  composition will be validated in the object generated for the nested composition via
  instantiation". With no object class, nothing validates: generated branch bodies are
  literally empty. A property `allOf: [{anyOf: [string≥5|int]}, {oneOf: [int|bool]}]` accepts
  `true`, `"ab"`, `"abcdef"` — every invalid value. The same stripping exists at root level in
  `ComposedPropertyValidator::withoutNestedCompositionValidation()` (there it strips
  `AbstractComposedPropertyValidator`, i.e. also `if`), harmless there only because root
  branches become objects first.
- **Asymmetry**: the property-level filter removes exactly `ComposedPropertyValidator`;
  `ConditionalPropertyValidator` (if/then/else) is a sibling class and **survives** — nested
  `if` inside a property-level branch validates (`NestedIfInComposition.json` test), nested
  `allOf`/`anyOf`/`oneOf`/`not` silently don't. Nothing in the code marks this asymmetry as
  intentional.

### 9.2 On flattening as a strategy

Agreed with the maintainer's assessment: same-keyword flattening (`allOf`-in-`allOf` →
concatenate branches) is sound *as an optimization* for that one case but is not a general
solution — it cannot express `anyOf`-in-`allOf` (the inner disjunction must be evaluated as a
unit), loses per-branch JSON pointers and error granularity, and creates a second, divergent
evaluation path that every future feature (defaults promotion, attribute synthesis, filter
classification) would have to mirror. The general model must be **recursive evaluation of a
branch as a self-contained schema** — which is exactly what the §8.1 normalization already does
for object branches (branch → class → internal validation) and what Direction 2 extends. No
flattening should be introduced.

### 9.3 What Direction 2 fixes here, and what it does not

- Object-shaped nested compositions (the issue #72 chain, and any mix of
  `anyOf`/`oneOf`-of-objects inside `allOf`-of-objects): fixed by construction — each nesting
  level becomes a class; inner compositions are handled by that class's own processing,
  including mixed keywords (the root-level machinery already supports every keyword).
- **Scalar nested compositions: not fixed** — routing rule 4 rejects them, and they remain the
  §9.1 validation hole. This needs the companion fix below.

### 9.4 Companion fix for the scalar hole (separate, small, independent of Direction 2)

The strip in `getCompositionProperties()` (and its root-level twin
`withoutNestedCompositionValidation()`) must become conditional: remove a branch's composed
validator **only when the branch has a nested schema** (an object class exists that re-validates
the composition on instantiation); keep it otherwise. Implementation risk to verify: rendering a
nested `ComposedItem` inside a branch body may collide on the template's closure-captured
by-reference variables (`$succeededCompositionElements`, `$compositionErrorCollection` are
`use (&...)`-captured — a nested composed validator's closure would clobber the outer counters).
`ComposedPropertyValidator extends ExtractedMethodValidator`, so rendering the nested validator
as its extracted method call (own scope) is the likely correct form — needs a spike. This fix
also restores spec-correct behavior for the root-level `$ref`-to-scalar-composition branches
once they stop being silently skipped (Phase 1 path) — the two fixes meet at the same
"does the branch have a class that re-validates?" question.

---

## 10. The `nestedSchema` contract (maintainer note 2): proposed definition

### 10.1 Definition (to adopt in the `PropertyInterface` docblock)

> `nestedSchema` is the **single generated PHP class whose instances represent the value of
> this property whenever the value is an object**. It is an identity/representation link, not a
> validation container. `null` means "no single class represents this property's object values"
> — either the property is not object-valued at all, or (anyOf/oneOf) its object values are
> represented by *branch-owned* classes, each reachable via the corresponding branch property
> of the property's composed validator.

Per shape:

| Property shape | `nestedSchema` | Rationale |
|---|---|---|
| Explicit `type: object` | its class | as today |
| `allOf` of objects | the composition class (Direction 2) / today: **wrongly null** | allOf has exactly one value representation — the combined class; this is the case Direction 2 makes truthful |
| `oneOf`/`anyOf` with ≥1 object branch | **null**, by definition — not an array | there *is no single class*; the runtime value is an instance of the matched branch's class (oneOf's `mergedProperty => null` semantics today, and anyOf's redirect/merge machinery for multi-object cases produces a class for the *merged validation result*, which remains reachable via the validator, not the property) |
| Mixed object/scalar composition | null | not exclusively object-valued |

### 10.2 Why null — and not an array — for multi-class compositions

The temptation is to make `nestedSchema` a list for oneOf/anyOf. Rejected:

1. Every existing consumer (§2) is written against "0..1 class"; an array forces all ~16 sites
   to define per-site multi-class semantics, most of which have none (what is "the" class to
   instantiate for meaning D? which class's properties for meaning C when branches conflict?).
2. The multi-class information **already exists with better structure**: the composed
   validator's `getComposedProperties()` gives each branch *with* its class, its JSON pointer,
   its branch schema — strictly richer than a flat schema list. Consumers that genuinely need
   "all possible classes" (none do today; the closest is type-hint rendering, which already
   walks branches via `CompositionTypeHintDecorator`) should ask the validator.
3. Under this contract `nestedSchema !== null` ⇒ "value is exclusively object-typed" stays a
   sound inference — which is exactly what `FilterValidator` (meaning A) depends on. An array
   value would silently break that inference's meaning.

### 10.3 Sufficiency check against the known limitations

With the contract adopted and Direction 2 making it true for allOf:

- Meanings A, B, D (§2): served directly by `nestedSchema` under the contract — no new data
  needed.
- Meaning C consumers: served at *branch* level (each branch property carries its class per the
  contract; implied-object branches get real classes via definition re-routing). No consumer
  needs a new accessor.
- The one place that conceptually wants "the merged class of an anyOf" —
  `createMergedProperty`'s runtime instantiation — already has it internally (the
  `mergedProperty` reference) and, per the contract, correctly does *not* publish it on the
  outer property.

Conclusion: **no additional property-level data structure is required.** The fix is not "richer
data on the property" but "make the existing 0..1 contract true for every property that
qualifies" (Direction 2) plus the scalar-hole companion fix (§9.4), which needs no property
data at all.
