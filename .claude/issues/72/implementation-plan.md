# Issue #72 / PR #74 — Implementation Plan

## Status: tests landed, fix not yet implemented (paused for user go-ahead)

Per user decision, this pass only:
1. Verified which of PR #74's two reported defects still reproduce on current master.
2. Reconstructed representative schemas and characterization tests on branch
   `claude/mr-74-review-adoption-3p8hdm`, pinning today's (buggy) behavior so a future fix has a
   concrete, already-passing-when-fixed target.
3. On a second, deeper pass (requested explicitly: "rethink the proposed changes carefully to
   ensure they don't cause problems in scenarios we currently don't have in mind"), corrected the
   root-cause understanding of defect 2 (it is narrower and more specific than first described —
   see analysis.md §2a-2d) and discovered a related, broader gap (§2e: `allOf` object-vs-scalar
   type conflicts are not detected anywhere in the codebase, at root or property level) that the
   obvious fix for defect 2a would otherwise silently make worse. Four additional characterization
   tests were added as a direct result.
4. Documented the patch each defect still needs, without applying any of it.

## What has landed on this branch

Schemas (`tests/Schema/Issues/72/`):
- `NestedAllOf.json` — 3-level `$ref`-chained `allOf`, plus a recursive `assistance`
  self-reference (reconstructed from PR #74's schema of the same name).
- `OneOfExampleRoot.json` — root-level `oneOf`, example-only branch reached via `$ref`
  (reconstructed from PR #74's `OneOfExample.json`, unchanged in shape).
- `OneOfExampleRootInline.json` — same pair, but the example-only branch is inline, not `$ref`'d
  (new — isolates defect 2a's `$ref`-specific root cause from defect 2b's over-matching).
- `OneOfExampleNested.json` — the example-only pair nested inside a `wrapper` property instead of
  at the schema root (new — isolates defect 2c from 2a/2b).
- `AllOfConflictingObjectAndScalar.json` / `PropertyLevelAllOfConflictingObjectAndScalar.json` —
  root-level and property-level genuinely-contradictory `allOf` (object shape vs. plain `string`)
  (new — surfaces the §2e gap).

**Revised per explicit review feedback**: `tests/Issues/Issue/Issue72Test.php` was originally
written as characterization tests pinning today's *buggy* behavior (asserting the crash/wrong
output actually happens), on the reasoning that a skipped/incomplete test wouldn't fail loudly if
something regressed further before the real fix landed. The user overrode this: tests must assert
the *correct*, desired behavior directly and stay red (failing) until the corresponding phase
lands, rather than asserting current wrong behavior and needing a rewrite later. All tests below
were rewritten accordingly — each one is now exactly what should be true once its phase is done,
no further rewrite needed when the fix lands, only removal of the "not yet implemented" docblock
note:

| Test | Currently | Needs |
|---|---|---|
| `testDeeplyNestedAllOfCompositionInstantiatesNestedObject` | red (`getCEO()` is a raw array, not object) | Phase 3 |
| `testRootLevelOneOfWithReferencedExampleOnlyBranchAcceptsConformingInput` | red (crashes at generation) | Phase 1 |
| `testRootLevelOneOfWithInlineExampleOnlyBranchAcceptsConformingInput` | red (over-matches, rejected) | Phase 2 |
| `testNestedOneOfWithExampleOnlyBranchAcceptsConformingObject` | red (over-matches, rejected) | Phase 2 |
| `testNestedOneOfWithExampleOnlyBranchRejectsNonConformingScalarInput` | red (still silently accepted — the literal original bug) | Phase 2 |
| `testRootLevelAllOfWithConflictingObjectAndScalarTypesThrowsConflictingTypesException` | **green already** | Phase 0 (done) |
| `testPropertyLevelAllOfWithConflictingObjectAndScalarTypesThrowsConflictingTypesException` | **green already** | Phase 0 (done) |

Consequence: the full test suite (`vendor/bin/phpunit`) will report failures for as long as
Phases 1-3 remain unimplemented — this is expected and intentional, not a regression to
investigate. Verified: 2871 passing + exactly these 6 intentional failures, nothing else affected.

## Remaining work (open questions resolved; implementation not yet started — needs final
## go-ahead)

### Open questions — resolved

1. **Scope of §2e** (allOf object-vs-scalar type conflicts undetected): **fix as a prerequisite
   in this same effort.** Phase 0 below extends `transferAllOfType()`'s type-intersection check to
   see object-shaped branches, closing the general gap rather than special-casing only the shape
   Phase 1 needs safe.
2. **Degenerate-branch definition — resolved narrowly, deliberately not generalized:**
   exclusion from the composition's branch array applies **only** to the literal `example`-only
   shape described in issue #72 (a branch whose `$ref`-resolved JSON is exactly
   `{"example": ...}`), treated as a named, explicitly whitelisted DX affordance — not a general
   "annotation keyword" classifier. Any future addition to that whitelist (e.g. `examples`,
   `$comment`) must be deliberately added and reasoned about individually, not inferred by a
   generic "is this keyword an annotation" rule: per the user, generalizing the exclusion by
   concept rather than by explicit whitelist would be a spec violation (JSON Schema does not
   define "annotation-only branches match nothing" — `{}`, `{"example": ...}`, and any other
   assertion-free schema are all equally "matches everything" per spec; excluding them from a
   composition's branch list is a deliberate, opinionated DX override of that spec meaning, and
   must stay opt-in per keyword rather than silently expanding).
   A bare `{}` branch is therefore **not** excluded — it keeps its full (odd but spec-correct)
   effect on `oneOf`/`anyOf` matching.
   **Independently of exclusion**, any composition branch — whitelisted-excluded or not, `allOf`
   included — that carries no validation/assertion keyword after `$ref` resolution must emit a
   generation-time warning, so schema authors are alerted to a vacuous branch even when its
   default (non-excluded) spec-correct behavior is left alone. This is a low-stakes addition to
   Phase 2 (warn) that was not explicitly discussed but follows directly from "no exception should
   pass silently" — flagged here rather than assumed silently, per project convention; correct me
   if `allOf` should be excluded from the warning since a vacuous `allOf` branch is functionally
   harmless there (unlike `anyOf`/`oneOf`/`if`).

### Phase 0 — DONE — make `allOf` object-vs-scalar type conflicts detectable

Implemented in `AbstractCompositionValidatorFactory` (`src/Model/Validator/Factory/Composition/
AbstractCompositionValidatorFactory.php`).

**Root cause was more specific than originally described**: it is not that
`transferAllOfType()`'s intersection math excludes object-shaped branches (though that is also
true) — `transferPropertyType()` itself returns *immediately*, before `transferAllOfType()` is
ever called, whenever *any* composition branch has a nested schema. So the moment an `allOf` mixes
one object-shaped branch with one scalar-typed branch, the entire type-transfer/conflict-detection
step was skipped, not just the intersection computation within it.

Fix: `transferPropertyType()` still returns early when a nested-schema branch is present (object
merging is legitimately handled elsewhere), but for `allOf` specifically it first calls a new
`assertNoObjectScalarTypeConflict()`, which throws the existing "conflicting types in allOf
composition branches" `SchemaException` (now factored into a shared
`throwConflictingAllOfTypesException()` helper, reused by `transferAllOfType()`'s original
scalar-vs-scalar check) if any sibling branch has a scalar `getType()` that isn't an always-true
branch. `anyOf`/`oneOf` are deliberately untouched — a value satisfying either an object shape or
a scalar type is legitimate union semantics there, not a conflict.

Verified: `testRootLevelAllOfWithConflictingObjectAndScalarTypesThrowsConflictingTypesException`
and `testPropertyLevelAllOfWithConflictingObjectAndScalarTypesThrowsConflictingTypesException` (both
renamed from their "confusing crash" / "unsatisfiable at runtime" pre-fix names) now assert the
correct, clear diagnostic at generation time in both the root and property-level cases. Full suite
(2871 tests) passes with no regressions — audited ~90 existing schema files using `allOf` for any
that might legitimately mix a nested-schema branch with a scalar-typed one before implementing;
none do (the combination is inherently either "merging multiple object branches" or "intersecting
multiple scalar branches" in every existing test, never a genuine mix), and the full run confirms
it.

### Phase 1 — DONE — relax the nested-schema requirement in `transferComposedPropertiesToSchema()`
### for the specific $ref-dropped-type case (defect 2a)

Implemented in `SchemaProcessor::transferComposedPropertiesToSchema()`
(`src/SchemaProcessor/SchemaProcessor.php`).

**Key refinement over the original plan wording**: the relaxation could not be "any branch without
a nested schema" — an existing, deliberate test
(`tests/ComposedValue/ComposedAllOfTest.php::testNoNestedSchemaThrowsAnException`, schema
`NoNestedSchema.json`: root-level `allOf` with a single `{"type": "integer", ...}` branch) requires
this exact crash to keep happening for a branch that has an **explicit, conflicting scalar type**.
That case has zero nested-schema branches at all, so it never reaches Phase 0's
`assertNoObjectScalarTypeConflict()` (which only fires when a nested-schema branch coexists with a
scalar one) — Phase 0 alone does not make blanket relaxation safe here.

Fix: the check now only skips (treats the branch as contributing no properties) when the branch
has **neither** a nested schema **nor** an explicit type (`getType() === null`) — a genuinely
untyped/degenerate branch, matching everything. A branch with an explicit type but no nested
schema still throws the original message unconditionally, regardless of composition kind,
preserving `testNoNestedSchemaThrowsAnException` and any equivalent case untouched.

Implementation detail: skipping a branch still had to feed the same shared
"`$resolvedPropertiesCallbacks === $totalBranches` → run cross-branch checks" bookkeeping the
nested-schema path uses, otherwise skipping a branch would silently stop that bookkeeping from
ever reaching its threshold when it wasn't the last one resolved. Extracted into a shared
`finalizeComposedBranchResolution()` helper, called from both the nested-schema path (after
transferring its properties) and the new skip path (immediately, since there is nothing to wait
for).

Verified: `testRootLevelOneOfWithReferencedExampleOnlyBranchAcceptsConformingInput` no longer
crashes at generation — it now converges into the same `OneOfException: matched 2 elements` state
as the already-known Phase 2 cases (expected; Phase 2 still needed to actually exclude the branch
and turn this green). Full suite: same 2871 passing / 3 errors / 3 failures as before this phase,
confirmed to be the exact same 6 test methods, no new or different failures anywhere.

### Phase 2 — DONE — exclude the whitelisted `example`-only branch shape from `anyOf`/`oneOf`/`if`,
### and warn on any vacuous branch generally

Implemented in `AbstractCompositionValidatorFactory::getCompositionProperties()`
(`src/Model/Validator/Factory/Composition/AbstractCompositionValidatorFactory.php`).

**Safety design — why the check had to be gated on `isResolved()`**: `getCompositionProperties()`
constructs each branch's `CompositionPropertyDecorator` and would naively want to inspect its
resolved JSON immediately to decide exclusion. But a branch that is part of a genuinely recursive
`$ref` chain (referencing an ancestor still mid-construction) resolves to a `PropertyProxy` whose
`getJsonSchema()` can fatal ("Call to a member function ... on null") if called before the chain
settles — which is exactly why the pre-existing code only ever inspects branch state inside
`onResolve()` callbacks, never synchronously right after construction. The fix: only inspect
`getJsonSchema()` when `$compositionProperty->isResolved()` is already `true` at that point (which
`ResolvableTrait::onResolve()` fires synchronously for, since `CompositionPropertyDecorator`'s
constructor wires its own resolution to the wrapped property's). This is safe with no loss of
coverage: a branch whose resolved shape is vacuous (no properties/items/etc. to recurse through)
can never itself be mid-resolution — recursion requires structural content a vacuous branch by
definition lacks. Branches still pending resolution are simply left uninspected (correctly, since
they can never be the vacuous shape being checked for).

**`$ref` vs. inline wrinkle, discovered by testing, not anticipated in the plan**: the narrow
exclusion check (`isExampleOnlyBranch()`) initially only matched a resolved branch JSON of exactly
`{"example": ...}` (single key). This fixed the `$ref`-based test cases but left
`testRootLevelOneOfWithInlineExampleOnlyBranchAcceptsConformingInput` (the schema-root, non-`$ref`
variant) still failing. Root cause: `inheritPropertyType()` (called earlier in each factory's
`modify()`, before `getCompositionProperties()` runs) mutates the raw branch JSON in place,
injecting the parent's `type` into every branch declaring none of its own — for every branch,
regardless of `$ref` usage. A `$ref` branch loses that injected sibling once resolved (Draft 7
ignores keywords sitting next to `$ref`), landing back at exactly `{"example": ...}` — but an
inline branch keeps the injected type, resolving to `{"example": ..., "type": "object"}` instead.
Both are the literal same author-written shape; only our own internal bookkeeping differs
depending on how the branch happens to be written. `isExampleOnlyBranch()` was extended to also
accept the `{"example": ..., "type": "object"}` shape specifically — not a broadening of the
whitelist by concept (still exactly one named, explicit shape plus its one known mechanical
variant), but flagged here for the user to double-check given how firm the "explicit whitelist,
not by concept" instruction was.

**Warning scope**: implemented to fire for every composition keyword (`allOf` included) per the
plan's stated assumption, since it changes no behavior and is purely informational. Not
re-confirmed with the user in this pass — still open for correction if `allOf` should be excluded
from it.

Verified: `testRootLevelOneOfWithInlineExampleOnlyBranchAcceptsConformingInput`,
`testNestedOneOfWithExampleOnlyBranchAcceptsConformingObject`, and
`testNestedOneOfWithExampleOnlyBranchRejectsNonConformingScalarInput` (both data sets) all now
pass, alongside the already-passing Phase 0 tests and
`testRootLevelOneOfWithReferencedExampleOnlyBranchAcceptsConformingInput` (fixed as a side effect
of the same change, since Phase 1 had already converged it into the same "matched 2" state the
others were in). Only `testDeeplyNestedAllOfCompositionInstantiatesNestedObject` (Phase 3) remains
red. Full suite: 2871 tests, exactly that one failure, no regressions anywhere else.

### Phase 3 — fix nested-object instantiation for multi-level `allOf` chains (defect 1)

**Status: root cause fully analyzed, direction chosen, implementation plan to be built — see
`nested-schema-analysis.md` in this directory for the complete investigation.** Key facts:

- Two quick-fix attempts were made and **reverted** (tree is clean at the Phase 2 state): full
  decorator wiring broke 40 tests (decorator dual-context ordering conflict), `setNestedSchema`
  alone broke 2 filter tests (grants "definitively object" to anyOf outer properties) — details
  in nested-schema-analysis.md §3 and §7.
- Root cause: the pipeline has no representation for "object-shaped by composition";
  `nestedSchema` is produced only for literal `type: object` and ~16 consumer sites all
  mis-handle composition-implied objects (full inventory: §2).
- **Verified regressions/holes found during analysis, raising Phase 3's priority**: (a) Phase 1's
  skip turned the old loud root-level crash into a silent property drop for `$ref` branches to
  composition-only definitions (§2 #10); (b) property-level nested compositions inside untyped
  outer properties (`allOf` containing `anyOf`/`oneOf` branches without explicit object types)
  validate **nothing** — a complete silent validation hole (§9.1).
- Chosen direction (maintainer decision after the §8 deep dive): **Direction 2** — route
  statically all-object composition-only property schemas through the existing object path,
  making the `nestedSchema` contract (§10) true instead of compensating for it; plus the small
  independent companion fix for the scalar nested-composition hole (§9.4). Directions 1/3
  (bridge signal + per-use-site decorators) are dropped as throwaway work (§8.7).

**Cross-keyword verification complete (maintainer-requested)**: all other composition keywords
are equally broken with composition-implied object branches — `anyOf` silently accepts
everything, `oneOf` rejects everything, `if/then/else` enforces nothing on the taken branch,
`not` rejects everything (full matrix and mechanism: nested-schema-analysis.md §8.8, gold
standard established from the explicit-object-branch equivalents). Eight new red-until-fixed
tests + four schemas landed in `Issue72Test` (`NestedAnyOf/NestedOneOf/NestedIfThenElse/
NestedNot`); 2 of the 10 new test cases are green today (oneOf/not rejections — right result,
wrong reason). Direction 2 covers all keywords via its definition-level re-routing half; the
outer-keyword re-routing stays allOf-scoped. The scalar nested-composition validation hole was
reported as GitHub issue #167.

**Second cross-verification round (maintainer-requested)**: inline implied-object branches fail
identically to `$ref` variants (now covered by the same tests via schema-file data providers);
mixed compositions (implied-object + scalar branch) need no separate mechanism — the gold
machinery handles them once branches are real — except `allOf`-mixed, which is unsatisfiable and
must become a generation-time conflict (red test added); bare object-validator branches
(`properties`/`required` without `type`) are equally broken, with one open semantic fork: for
`anyOf`, strict spec accepts non-object values via vacuous branch matches while object-implied
semantics reject them (nested-schema-analysis.md §8.9-§8.10; the refined implied-object
predicate lives in §8.10). Issue72Test now: 43 cases, 26 red-until-fixed, 17 green; full suite
2906 tests, no impact outside Issue72Test.

**Analysis completeness check (maintainer-requested) — two final gaps found and closed**:
(a) array-items context verified — single-level implied items work today, multi-level implied
items are broken identically to properties (two more red tests,
`NestedAllOfInArrayItems.json`); other `PropertyFactory::create()` contexts (dependencies,
contains, patternProperties values) are covered by the same mechanism argument and deferred to
the implementation test matrix (§8.11). (b) The §8.10 predicate had to become **three-valued** —
object-asserting vs. object-describing vs. not-object — to reconcile the strict-spec decision
with the pinned instantiation expectations for bare-validator branches (§8.10, asserting/
describing distinction). The bare-validator semantics fork was resolved by the maintainer:
strict spec. Issue72Test after all rounds: 46 cases, 31 red-until-fixed, 15 green; full suite
2908 tests, all reds confined to Issue72Test.

The anyOf bare-validator fork is resolved (strict spec); the remaining stated-but-unconfirmed
assumptions are the `filter`-keyword exclusion from re-routing (§8.2 rule 2) and the vacuous-
branch warning firing for `allOf` too — both are visible in this plan and cheap to change.

### Phase 3 implementation plan (Direction 2)

The sub-phases are ordered so each lands green-by-construction on everything except the
Issue72Test reds it is meant to flip, and each is independently commit-able. File pointers
reference the current tree.

**P3.1 — `nestedSchema` contract + object-shape resolver (no behavior change).**
- Document the §10 contract on `PropertyInterface::setNestedSchema()/getNestedSchema()`.
- New self-contained resolver (suggested: `src/PropertyProcessor/ObjectShape/`) classifying a
  `JsonSchema` per §8.10 as `NotObject | ObjectDescribing | ObjectAsserting`:
  explicit `type: object` → asserting; `type` array or scalar type → not-object; no type +
  object-constraining keywords only → describing; composition → recursive over branches
  (allOf: asserting if ≥1 branch asserting, describing if all describing/vacuous;
  anyOf/oneOf: asserting only if ALL branches asserting; boolean/vacuous branches neutral),
  resolved through `$ref` chains via the schema dictionary with a visited-set (cycle or
  unresolvable → NotObject, conservative bail-out). `filter`-bearing schemas → never asserting
  (stay on the filter-composition machinery).
- Unit tests for the resolver against every shape in the §8.10 table, including cycles and
  external-file `$ref`s in both discovery orders (§8.6.1).

**P3.2 — re-route object-asserting composition-only schemas through the object path.**
- `PropertyFactory::create()`: when the schema has no `type`/`filter` key, has `allOf` (initial
  outer-keyword scope), and the resolver says ObjectAsserting → route through the
  `createObjectProperty()` path (`processSchema` handles composition roots; `generateModel`
  forces `type: base` internally). Applies automatically to definitions (fixing implied
  *branches* of every keyword), inline branches, array items, and root-level `$ref` targets.
- Re-guard the Phase 1 skip in `transferComposedPropertiesToSchema()`: a skipped branch must be
  NotObject-shaped; ObjectAsserting/Describing branches now carry nested schemas or restored
  validation, so the §2 #10 silent drop closes.
- Expected test flips: allOf chain (`testDeeplyNestedAllOfCompositionInstantiatesNestedObject`),
  array items pair, the anyOf/oneOf/if/not implied tests whose branches become real object
  properties, and the mixed-composition tests except allOf-mixed (P3.4). Migration audit: run
  the full suite; classify every failure as naming-only (`_Merged_` assertions,
  `testIdenticalMergedSchemaIsRedirected` — semantic core must survive via signature dedup) or
  behavioral (investigate before touching the test); document each intended change.

**P3.3 — restore branch validation where no re-validating class exists (companion fix, #167).**
- Make the `ComposedPropertyValidator` strip in `getCompositionProperties()` (and its root-level
  twin `withoutNestedCompositionValidation()`) conditional on the branch having a class that
  re-validates on instantiation; otherwise keep the nested composed validator.
- Spike first: nested `ComposedItem` rendering collides on by-reference closure variables
  (`$succeededCompositionElements` etc.) — render retained nested validators via their
  extracted-method form (own scope), see §9.4.
- ObjectDescribing branches: generate the guarded representation class (validators run only
  against object values; instantiation via the existing `is_array(...)` decorator idiom) so the
  bare-validator tests flip (strict-spec matched-counts + instantiation, §8.10). Conservative
  default for describing-only compositions: guarded validation without outer re-routing.
- Fixes the #167 scalar hole; add the #167 reproduction schema as a regression test here.
- Resolve the `if` asymmetry (§9.1) explicitly: same conditional rule for
  `ConditionalPropertyValidator` instead of the accidental class-hierarchy split.

**P3.4 — conflict detection for implied shapes (Phase 0 extension).**
- `assertNoObjectScalarTypeConflict()` (and the transferPropertyType guard) consume the resolver:
  an ObjectAsserting branch conflicts with a scalar-typed sibling in `allOf` even without a
  nested schema. Flips `testAllOfMixingImpliedObjectAndScalarBranchThrowsConflictingTypesException`.
- Add the generation-time warning for describing-only branches ("does not constrain non-object
  values"), scope per the standing assumption (all keywords) unless corrected.

**P3.5 — consumer sweep + edge matrix.**
- Walk the §2 consumer table: with re-routing, meaning-C consumers see real nested schemas —
  add one targeted test each for required-promotion, cross-branch defaults, attribute
  synthesis (JsonPointer/JsonSchema), setter revalidation (mutable models), widening, and the
  §8.11 contexts (dependencies — watch `SchemaDependencyValidator`'s own decorator —, contains,
  patternProperties values). Plus serialization round-trip and Populate/Builder post processors
  on a re-routed property.
- Re-verify FilterValidator behavior on mixed anyOf with now-real object branches (the §3.2
  regression scenario) — must stay green.

**P3.6 — documentation + cleanup.**
- `docs/source/combinedSchemas/{allOf,anyOf,oneOf}.rst` + `not`/`if` pages: document
  composition-implied objects, the strict-spec bare-validator semantics, and the changed class
  naming for re-routed schemas; changelog entry (breaking: generated class names for
  property-level all-object `allOf`).
- Optional follow-up (separate decision): extend outer-keyword re-routing beyond `allOf`.
- Final commit before merge: delete `.claude/issues/72/` per the standing exception note.

### Phase 4 — documentation

Once the above land, audit `docs/source/combinedSchemas/{allOf,anyOf,oneOf}.rst` for any
documented limitation around nested compositions, example-only branches, or allOf type conflicts
that needs updating, and add a changelog/release-notes entry per the project's existing
convention.

## Rejected alternatives (recorded for future sessions)

- **Porting PR #74's actual code changes directly**: rejected — the classes it patches
  (`AbstractComposedValueProcessor`, `OneOfProcessor`) no longer exist; the entire composition
  architecture was rewritten after 2023. Only the test *schemas* were salvageable.
- **Treating "no nested schema" as uniformly safe to relax across all composition kinds**:
  rejected after direct verification — the exact same crash currently is the only thing causing a
  genuinely contradictory root-level `allOf` to fail at all (§2e). A blanket relaxation would
  silently convert that already-confusing-but-loud failure into a fully silent one.
- **Reusing `createAlwaysTrueBranchProperty()`/`markAsAlwaysTrueBranch()` for degenerate
  (example-only) branches**: rejected — that mechanism is for literal JSON `true`, which
  deliberately participates in match-counting (spec-correct). An accidentally-included
  example-only branch needs to be excluded from the branch array entirely; marking it
  "always-true" instead would leave `oneOf` over-rejecting and make `anyOf` silently accept
  anything (see analysis.md §2d).
- **Treating defect 2a and defect 2b/2c as the same fix**: rejected — 2a is specifically a
  `$ref`-sibling-type-loss generation-time crash (an inline equivalent branch does not crash at
  all); 2b/2c are runtime over-matching/under-rejection problems requiring branch exclusion. They
  need related but distinct patches (Phase 1 vs Phase 2 above).
- **Reusing `CompositionBranchClassifier` for degenerate-branch detection**: rejected — on closer
  reading it is a filter input/output type-space classifier, unrelated to "does this branch carry
  a validation keyword." An earlier draft of analysis.md incorrectly named it as a candidate site;
  corrected.
- **Writing the Issue72Test assertions as `markTestSkipped`/`markTestIncomplete`** (as PR #74 did
  for its own untested nested-`allOf` case): rejected in favor of asserting the exact current
  (buggy) output/exception. A skipped test would not characterize *what* is currently broken and
  would not fail loudly if some unrelated change accidentally altered the behavior before the
  real fix lands; asserting today's concrete behavior turns this into a proper characterization
  test per CLAUDE.md's guidance to mark tests "expected to fail... until the fix lands" via
  explicit exception/value assertions.
