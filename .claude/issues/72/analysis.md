# Issue #72 / PR #74 — Nested Compositions

## Origin

- Issue #72: a user could not generate PHP classes from FedEx's OpenAPI `rate.json` schema.
  Two independent defects were found by the maintainer while investigating:
  1. A fatal `SchemaException: "No nested schema for composed property ... found"` caused by
     deeply nested `allOf` compositions (an `allOf` branch that is itself a `$ref` to a further
     `allOf` definition, several levels deep).
  2. A `oneOf` composition where all branches but one provide only an `example` keyword (no
     `type`/`properties`/any validation keyword). Since the example-only branch carries no
     constraints, it matches any input, defeating the purpose of the `oneOf` — the maintainer's
     own repro showed `{"body": 34}` validating against a schema whose "body" should have been an
     object.
- PR #74 (`issue72_nestedComposition`, base commit `42454eb`, opened 2023-05-30, never merged)
  attempted to fix both defects against the composition architecture as it existed in 2023
  (`AbstractComposedValueProcessor`, `OneOfProcessor` in
  `src/PropertyProcessor/ComposedValue/`). Its own test for defect 1
  (`testNestedAllOf` in `tests/Issues/Issue/Issue72Test.php`) was left
  `$this->markTestSkipped('Not functional yet')` — the fix for defect 2 (skip an
  `oneOf` branch whose JSON is exactly `{"example": ...}`, emitting a warning) shipped in the PR,
  but the nested-`allOf` fix did not actually work and was never completed.

## Why PR #74 itself is not adoptable

The entire composition-handling architecture the PR patches has since been rewritten:
`AbstractComposedValueProcessor` and `OneOfProcessor` no longer exist anywhere in git history of
this branch. Composition is now handled through `AllOf/AnyOf/OneOf/IfValidatorFactory` +
`AbstractCompositionValidatorFactory` (`src/Model/Validator/Factory/Composition/`) and
`SchemaProcessor::transferComposedPropertiesToSchema()` / `transferPropertiesToMergedSchema()`.
None of PR #74's actual diff hunks apply conceptually to this code. Only the *test schemas and
assertions* are worth carrying forward, reconstructed against the current architecture and
current (verified, by actually generating and running classes — not just reading source) behavior
— not the production code changes.

**Correction from an earlier draft of this document**: `src/PropertyProcessor/Filter/
CompositionBranchClassifier` was initially flagged as a candidate site to reuse for detecting
degenerate branches. On closer reading it is the wrong subsystem entirely — it classifies a
branch's keywords into Input/Output/Mixed/Empty *type-spaces* for filter-composition
compatibility checking (does a branch's constraints target a transforming filter's input type or
its output type), which is unrelated to "does this branch carry any validation keyword at all." A
fix for defect 2 must not be built on top of this class.

## Current-master verification (done by generating real classes from reconstructed schemas and
## instantiating them — not by reading source alone)

### Defect 1 — nested `allOf` — partially fixed (crash gone, output silently wrong)

Reconstructed schema: `tests/Schema/Issues/72/NestedAllOf.json` — a `CEO` property built from
`allOf: [$ref basic, {assistance: $ref basic}]`, where `basic` is itself
`allOf: [$ref identification, {yearsInCompany}]`, and `identification` is
`allOf: [$ref contract, {name}]` (three levels of `$ref`-chained `allOf`, plus a recursive
self-reference through `assistance`).

- The fatal `SchemaException` from issue #72 does **not** reproduce anymore. Generation
  completes.
- However, `$company->getCEO()` returns a **raw PHP array** (verified via
  `ReflectionMethod::getReturnType()` → `"mixed"`), not an instance of the generated nested
  class, even though the rendered PHPDoc `@return` annotation lists the (unusable) merged/nested
  class names. The nested property is therefore only usable as an array, silently contradicting
  its own generated documentation — precisely the kind of case CLAUDE.md's "fail loudly, never
  silently generate broken code" rule targets.
- Root cause is not yet fully traced (this needs its own dedicated investigation before a fix can
  be scoped — see implementation-plan.md Phase 3). The likely area is
  `PropertyFactory::createObjectProperty()` / `SchemaProcessor::transferPropertiesToMergedSchema()`
  / `redirectMergedProperty()`, where the `ObjectInstantiationDecorator` wiring appears not to
  propagate when a branch's "nested schema" is itself the product of a further composition rather
  than a plain object schema — but this has not been confirmed by tracing actual execution, only
  inferred from reading the code, so it should not be taken as settled.
- Test: `Issue72Test::testDeeplyNestedAllOfCompositionInstantiatesNestedObject` pins the
  current (broken) behavior — return type `mixed`, raw array returned — so a future fix flips
  this assertion to expect an instantiated object with working getters.

### Defect 2 — `oneOf`/`allOf` branches with no real constraints — not fixed, and entangled with a
### second, broader gap that must be closed first

This defect turned out to be more specific — and more dangerous to fix naively — than it first
appeared. Four independently reproduced scenarios, not one, are needed to understand it:

#### 2a. Root-level `oneOf`, example-only branch reached via `$ref` → crash

Schema: `tests/Schema/Issues/72/OneOfExampleRoot.json` (matches PR #74's original repro shape:
`oneOf: [$ref name, $ref nameExample]`, `nameExample` being `{"example": {...}}`).

`SchemaProcessor::transferComposedPropertiesToSchema()` (~line 511-532) requires every composed
branch to have a nested schema, unconditionally, and throws `SchemaException: "No nested schema
for composed property ... found"` otherwise.

**Root cause, confirmed by direct reproduction, not by reading source alone**: this is
specifically a `$ref` problem. `AbstractCompositionValidatorFactory::inheritPropertyType()`
copies the parent schema's `type` (forced to `object` at the schema root) into every branch that
declares no `type` of its own — this is what normally prevents an untyped branch from being a
problem. But per JSON Schema Draft 7 semantics, a `$ref` sibling keyword is ignored once the
reference is resolved (`$ref` replaces the whole schema object), so the type
`inheritPropertyType()` injects next to `{"$ref": "#/definitions/nameExample"}` is silently
dropped during resolution. Debug instrumentation confirmed the resolved branch JSON reaching
`transferComposedPropertiesToSchema()` is exactly `{"example": {"label": "ABC"}}` — no injected
`type` survived. The referenced definition itself declares no type, so it never gets a nested
schema, and the unconditional check throws.

**An inline (non-`$ref`) example-only branch at the schema root does NOT crash** — confirmed by
direct reproduction (`tests/Schema/Issues/72/OneOfExampleRootInline.json`). Type inheritance
applies normally to inline branches, the branch becomes an empty `{"type": "object"}` nested
schema, and generation succeeds. This means defect 2a is not "any untyped root branch crashes" —
it is specifically "an untyped branch reached only through `$ref` crashes, because type
inheritance can never reach it." Test:
`Issue72Test::testRootLevelOneOfWithReferencedExampleOnlyBranchAcceptsConformingInput`.

#### 2b. Root-level `oneOf`, example-only branch written inline → generates, but always over-matches

Once past the crash (inline branch, or a hypothetical fix to 2a), the branch is a legitimate but
empty `type: object` schema that matches every object-shaped value. For `oneOf` — which requires
*exactly one* branch to match — this means any input that also satisfies a real branch is
rejected for matching two branches instead of one. Confirmed via
`tests/Schema/Issues/72/OneOfExampleRootInline.json`:
`Issue72Test::testRootLevelOneOfWithInlineExampleOnlyBranchAcceptsConformingInput`.

#### 2c. Nested (property-level) `oneOf`, example-only branch reached via `$ref` → two symptoms, both live

`tests/Schema/Issues/72/OneOfExampleNested.json` — the same example-only pair, nested inside a
`wrapper` property instead of at the schema root (property-level composition does not go through
`transferComposedPropertiesToSchema()`, so it never crashes at generation time regardless of
`$ref`).

- **Valid, conforming data is rejected**: `{"wrapper": {"label": "Hannes"}}` throws
  `OneOfException: "... matched 2 elements"` for the same over-matching reason as 2b. Test:
  `Issue72Test::testNestedOneOfWithExampleOnlyBranchAcceptsConformingObject`.
- **Non-conforming, nonsensical data is still silently accepted** — this is the literal defect
  issue #72 reported (there: `{"body": 34}` validating against a schema whose body should be an
  object). Confirmed directly: `new $className(['wrapper' => 42])` and
  `new $className(['wrapper' => 'garbage'])` both succeed and return the raw scalar unchanged. A
  bare integer or string satisfies neither the real "name" schema nor a sensible reading of the
  property, but it matches *only* the always-true example branch (exactly 1 match), so `oneOf`
  accepts it. Test:
  `Issue72Test::testNestedOneOfWithExampleOnlyBranchRejectsNonConformingScalarInput`
  (`#[DataProvider]`, bare integer and bare string).

  **This is the important, easy-to-miss point**: fixing only the "valid data gets rejected"
  symptom (2c/2b, e.g. by making the always-true branch not count toward oneOf's match tally)
  without also excluding it from the branch list entirely would not fix this half — an
  always-true branch that is *excluded from counting but still present* would need explicit
  design; only *removing* the branch from the composition's branch array before validator
  construction fixes both symptoms simultaneously.

#### 2d. Why the fix must not reuse the existing "always-true branch" machinery

`AbstractCompositionValidatorFactory` already has infrastructure for literal JSON boolean branches
(`oneOf: [..., true]`): `createAlwaysTrueBranchProperty()` / `markAsAlwaysTrueBranch()`. It looks
superficially like exactly what defect 2 needs (a branch that always matches), but it is the
wrong mechanism to reuse:

- A literal `true` branch **deliberately** participates in match-counting — that is
  spec-correct: JSON Schema's own semantics for `oneOf: [{"type":"string"}, true]` really do make
  every string fail (matches both the string branch and the always-true branch) and every
  non-string pass (matches only the always-true branch). Someone who explicitly writes `true` as
  a branch is presumably choosing that.
- An `example`-only (or otherwise annotation-only) branch is, per the issue reporter and the
  maintainer's own comment, almost certainly an authoring accident (OpenAPI documentation data
  pasted into a `oneOf` list as if it were a real alternative), not an intentional "matches
  anything" branch.
- Consequently, the fix for defect 2 must **exclude** matching degenerate branches from the
  composition's branch array entirely, before any validator/match-counting logic runs — mirroring
  PR #74's original `continue;` in its (now-defunct) branch-collection loop — not mark them as
  "always-true" participants. Marking-as-always-true would leave `oneOf` over-rejecting (2c/2b
  persists) and would make `anyOf` trivially always-true (silently accepts anything — literally
  reintroducing the original bug for `anyOf`-shaped compositions), since `anyOf` only needs one
  match and an always-counted true branch always provides it.

#### 2e. A separate, broader gap this investigation surfaced: `allOf` object-vs-scalar type conflicts are not detected anywhere

While verifying that "relax the unconditional nested-schema check in
`transferComposedPropertiesToSchema()`" (the seemingly obvious fix for 2a) is safe, a genuinely
contradictory `allOf` was tested: one branch requires an object shape, the other requires a plain
`string` (`tests/Schema/Issues/72/AllOfConflictingObjectAndScalar.json` at the schema root,
`PropertyLevelAllOfConflictingObjectAndScalar.json` nested in a property). No value can ever
satisfy both simultaneously — this is exactly the class of contradiction CLAUDE.md requires
`SchemaException` to catch at generation time.

- `AbstractCompositionValidatorFactory::transferPropertyType()` / `transferAllOfType()` already
  implements a dedicated, well-worded diagnostic for this
  ("`Property '%s' is defined with conflicting types in allOf composition branches`"), but **only
  scalar-typed branches are visible to it** (`getType() !== null`). An object-shaped branch
  (resolved via a nested schema rather than a `PropertyType`) is excluded from the intersection
  computation entirely, so the conflict is invisible to this diagnostic regardless of whether it
  fires at the root or nested inside a property. Existing coverage
  (`tests/Schema/CrossTypedCompositionTest/*`, `PropertyLevelAllOfConflictingTypes.json`) only
  exercises scalar-vs-scalar conflicts (e.g. `integer` vs `string`), never object-vs-scalar, which
  is why this gap was not previously caught.
- **At the schema root**: the `string` branch has no nested schema, so
  `transferComposedPropertiesToSchema()`'s unconditional check throws the same confusing generic
  "No nested schema for composed property..." error as defect 2a — which, by accident, still
  fails loudly, just with a misleading message that gives the schema author no hint that the real
  problem is conflicting types. Test:
  `Issue72Test::testRootLevelAllOfWithConflictingObjectAndScalarTypesThrowsConflictingTypesException`.
- **Nested inside a property**: there is no unconditional-nested-schema check on this path at
  all, so generation succeeds silently. At runtime the generated validator can never be
  satisfied by any input — a well-formed object is rejected for not being a string, a well-formed
  string is rejected for not being an object, and every other value is rejected for both. This is
  not silent *acceptance* of bad data (every input is actually rejected, so the author will notice
  something is very wrong the first time they use the class), but it gives no diagnostic at
  generation time, only a maximally confusing "nothing validates, ever" outcome discovered at
  runtime. Test:
  `Issue72Test::testPropertyLevelAllOfWithConflictingObjectAndScalarTypesThrowsConflictingTypesException`
  (`#[DataProvider]`, well-formed object / well-formed string / neither).

**Why this matters for the defect-2 fix specifically**: the obvious-looking fix for 2a — relax
`transferComposedPropertiesToSchema()`'s unconditional nested-schema requirement so a branch
without one just contributes no properties — would, if implemented naively, silently swallow this
*already zero-diagnostic* object-vs-scalar `allOf` contradiction at the schema root too (turning
today's confusing-but-loud crash into a fully silent success, exactly the outcome the property-
level path already produces). This is not a hypothetical: it is the same code path
(`transferComposedPropertiesToSchema()` is shared by every `ComposedPropertiesValidatorFactoryInterface`
implementation — `allOf`, `anyOf`, `oneOf`, `if`), so any relaxation implemented purely in terms
of "does this branch have a nested schema" cannot distinguish "this is an accidental
example-only/annotation-only branch that should be excluded" from "this is a genuinely
conflicting-typed `allOf` branch that must fail generation."

## Design conclusions (resolved with the user; nothing implemented yet)

1. **Exclusion is deliberately narrow, not a general "annotation keyword" classifier.** Only a
   branch whose `$ref`-resolved JSON is exactly `{"example": <anything>}` (the literal shape from
   issue #72/PR #74) is excluded from the branch array before any validator/type-inference logic
   runs, for `anyOf`/`oneOf`/`if` branches. This is a deliberate, explicitly whitelisted DX
   affordance, not an inference from "is this keyword an annotation" — per the user, JSON Schema
   does not define annotation-only branches as matching nothing; `{}`, `{"example": ...}`, and any
   other assertion-free schema are all equally "matches everything" per spec, so silently
   expanding the exclusion to more keyword shapes by concept (rather than by an explicit,
   individually-reasoned whitelist entry) would be a spec violation. A bare `{}` branch is
   therefore **not** excluded and keeps its full spec-correct (if odd) effect on matching.
2. **Independently of exclusion, warn on any vacuous branch.** Any composition branch — whitelisted
   or not, `allOf` included pending confirmation — that carries no validation/assertion keyword at
   all after `$ref` resolution emits a generation-time warning, so authors are alerted even to
   vacuous branches the exclusion whitelist does not (and should not) silently rewrite.
3. This fix must **not** be implemented as "treat as an always-true branch" (existing
   `createAlwaysTrueBranchProperty` machinery) — see 2d above.
4. Defect 2a's crash (root + `$ref`-reached empty branch) cannot be fixed by blanket-relaxing
   `transferComposedPropertiesToSchema()`'s nested-schema requirement, because the exact same
   code path is the only thing currently causing a genuinely contradictory root-level `allOf`
   (2e) to fail at all, however confusingly. Resolved with the user: Phase 0 first closes the 2e
   gap in `transferAllOfType()` (make object-shaped branches visible to the type-intersection
   computation) as a prerequisite in this same effort, so contradictions are caught by their own,
   correctly-worded diagnostic before the relaxation in Phase 1 can let anything through silently.
5. Defect 1 (nested-allOf instantiation) is architecturally unrelated to defect 2's fix — it is
   about `ObjectInstantiationDecorator` wiring, not composition-branch classification — and needs
   its own trace/investigation before it can be scoped (not yet started in any depth).

No implementation has been made. See `implementation-plan.md` for the phased plan and the open
questions to resolve with the user before implementation starts.
