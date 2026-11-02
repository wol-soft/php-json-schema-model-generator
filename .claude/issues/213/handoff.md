# Handoff: finalize generator PR #222 (and production PR #23)

You take over a large, mostly finished piece of work. Read `CLAUDE.md` first: its rules are strict and
the previous session was held to them (clarification policy: open questions one at a time, wait for the
answer; test first, red before green; never narrow a test to evade a bug; complete exception message
assertions; no plan/issue/line references in code; no single-letter or underscore-prefixed locals; `use`
imports for every class; PHPCS; mandatory diff review before staging). Then read the planning notes on
this branch: `.claude/issues/213/implementation-plan.md` (design of the isolation, rejected
alternatives, 16 edge cases), `.claude/topics/*/` (analysis, rejected alternatives, found-along-the-way
lists). They hold the *why* of the design decisions; this file holds what they do not.

## 1. State (2026-11-01, ~18:30 UTC)

Repos and branches

| What | Where | State |
|---|---|---|
| Generator PR #222 | `wol-soft/php-json-schema-model-generator`, branch `claude/fix-213-nested-unevaluated-items`, base `master` @ `5d5e3cf0` | open, 37 commits + the planning-docs commit; code head `0db5f6d3` |
| Production PR #23 | `wol-soft/php-json-schema-model-generator-production`, branch `claude/tuple-exception-instance-pointer` @ `45555181` | open, mergeable, CI green, 5 commits; **has to be merged first** (generator requires production `dev-master`) |
| Generator PR #221 | closed, its content (the fix for #214) lives inside #222 | do not reopen |
| PR #207 | merged on 2026-10-30; it already contains the serialization of filtered items of nested arrays (the user's original request). Its description is stale (still lists that as a known gap) – irrelevant now | nothing to do |

Verified by the previous session at code head `0db5f6d3` (own runs, not agent claims): full suite with
`PHPUNIT_FULL_DRAFT_COVERAGE=1` on PHP 8.4: 10472 tests, 37993 assertions, 0 failures, 2 skipped, 3
incomplete (the skipped/incomplete ones exist on master too); PHPCS clean for the changed `src` files;
the hygiene greps (`Stage `, `Phase `, `decision `, `§`, `#<n>`, `$_x` locals, single letter locals) are
clean (the `#1`/`#2` matches in tests are the runtime message texts `Composition element #1`, `invalid
tuple #1`). **Not done for the last four commits** (step 3b, from an agent): I did not read their diff
line by line. Review `git diff ed430539..0db5f6d3 -- src docs` yourself (about 500 added lines).
CI of the pushed head was still pending when this was written; check it first (`mcp__github__pull_request_read`
`get_status` and `get_check_runs`; Coveralls is a status; the all-drafts job runs only for PRs without a
merge conflict, see section 3).

What PR #222 contains (needed for the new description, see 2.5)

1. Issue #213: `unevaluatedItems` on arrays nested in other arrays (items, tuple indices, additionalItems,
   contains, unevaluatedItems, values of additional/pattern/unevaluated properties), stack-scoped
   evaluation state (`Utils/EvaluatedItemsScope`, `RenderHelper::renderValidators()`); a transforming
   filter in a nested `unevaluatedItems` is rejected with a `SchemaException` when serialization is on.
2. Issue #214: stack overflow of self-referencing tuple arrays.
3. Found while auditing, fixed in the same PR as the user demanded: boolean schemas in tuple `items`;
   shared `$ref` definitions reported the name of the first referencing property; additionalItems /
   unevaluatedItems / contains / additional+pattern+unevaluated property values validated in methods of
   their own (recursion no longer overflows the generator; element variables no longer collide when the
   same kind nests); tuple as `contains` schema (`$modelData` warning); tuple + schema `additionalItems` +
   implicit null (`TypeError`); a **regression of the merged PR #207** (a recursive reference inside a
   `not` branch or an `if` condition no longer generated – proven: generated before #207, failed on
   master); a schema dependency referencing the owning definition; null-only property const fatal; enum /
   const of object values compared as JSON data; `not` on an optional property.
4. Tests (new fixtures under `tests/Schema/<TestClass>/`) and docs (`array.rst`, `filter.rst`,
   `references.rst`, `object.rst`, `enum.rst`, `const.rst`, `null.rst`, `not.rst`).

## 2. Remaining work (in this order)

The user said: all additionally found issues are fixed in this one merge request; fix everything in both
repos. Multi-phase rule of CLAUDE.md: after each step summarise and wait for the go-ahead.

2.1 **Step 3a (not started): two evaluation bugs.**
   - Rejection: `node = {contains: {const: "k"}, anyOf: [{items: {anyOf: [{$ref: node}, {type: string}]}}, {}], unevaluatedItems: false}`
     rejects `["k", ["k"]]` with "unevaluated index #1" although the `items` of the anyOf branch evaluates
     every index. Identical result on master, i.e. not caused by this PR. The same bug class shows
     without recursion: `unevaluatedItems: false` inside a composition branch ignores the `contains`
     credit of the same branch (listed in the recursive-definitions plan).
   - A self-referencing array `allOf` without `unevaluatedItems` exhausts the memory at generation.
     Open decision (ask the user): reject with a `SchemaException` (an `allOf` that requires itself is an
     infinite loop; CLAUDE.md demands to fail loudly) or support it. My recommendation: reject.
   Reproduce red first, then fix, test names that encode the scenario.

2.2 **Step 4 (not started): instance pointers, needs production PR #23.**
   (a) `RenderHelper::validationError()` has to render `(new X(...))->withPlaceholderPropertyName()` for
       the validators of the class-level base property (`MinProperties`, `MaxProperties`,
       `additionalProperties: false`, allOf/anyOf/oneOf/not/if-then-else of an object, schema
       dependencies). The marker must NOT be applied to additional tuple items and not to property
       dependencies; for additionalProperties / patternProperties / propertyNames /
       unevaluatedProperties it is harmless. The marker method is new public API of production PR #23.
   (b) generator tests for `getInstancePointer()` (`->pointer`, not a method): tuple positions (tuple
       keys are 1-based in messages – "invalid tuple #1" – but pointers are 0-based), additional tuple
       items (`/pair/3`), object-level validators (`/obj`, `/obj/b`), schema dependency (`/obj/b`),
       property-level compositions (`/name`, `/tags/0`), composition branches of an array
       (`/tags`, not `/tags/tags`).
   (c) The generator needs the production PR code to run them: in this repo `vendor/wol-soft/php-json-schema-model-generator-production`
       is a git clone; fetch the PR branch there (`git fetch origin claude/tuple-exception-instance-pointer`
       and check it out; `vendor/` is gitignored). Generator CI on these tests stays red until PR #23 is merged
       to production master, so the merge order is: production #23, re-run generator CI, then #222.
   Possible trap: the per-name rule of the production helper (branch exception name equal to the wrapper's
   name) has one known collision – a property literally named like the generated class. Documented there.

2.3 **Findings the user has not decided on (ask ONE question at a time, in this order):**
   1. Scope cap. Findings from the step 3 audit that are not fixed: (a) an object-typed property that
      exists only as a transferred schema-dependency property keeps its instantiation decorator and is
      instantiated/validated even when the property owning the dependency is absent (changes getter
      behaviour); (b) collect-error mode: composition validators still run on a value that already failed
      the type check (noise in the message list); (c) `RefResolver` restates any `SchemaException` raised
      inside a local definition as "Unresolved Reference" (hides the real message). Recommendation to give:
      the user said to fix everything, but (a) changes observable behaviour (high-stakes, ask), (b) and (c)
      change messages that are asserted in many tests and docs; grep first and show the blast radius.
   2. Whether to subscribe to PR #222 afterwards (`subscribe_pr_activity`) for CI and review.

2.4 **Final verification** (all of it, by yourself, not by an agent's claim): full suite with
   `PHPUNIT_FULL_DRAFT_COVERAGE=1`, `phpcs --standard=phpcs.xml` over `src` and changed tests, the hygiene
   greps over the full diff against `master` (CLAUDE.md "No implementation-plan references"), RST lint
   (a PyPI `docutils` venv works), the CLAUDE.md "Test coverage" check (every edge case of the plans has a
   test). Coveralls is a GitHub status (`get_status`): it dropped before by 0.03% because of three
   unreachable null-branches; those were proven dead by temporarily replacing the branch with a `throw`
   and running the suite, then removed. Do the same for any new defensive branch.

2.5 **Rewrite the PR #222 description** (it describes only #213 and is stale). Group by section 1. The
   body must end with the attribution lines your own system instructions require, and the PR needs the
   closing `Fixes #213` / `Fixes #214`. Mention that production #23 must be merged first.

2.6 **Before the merge**: delete `.claude/issues/` and `.claude/topics/` (everything under them that is
   tracked) and commit the deletion as the final commit on the branch. These files were committed on
   explicit user request only to hand this work over; `.claude/settings.local.json` is tracked on master
   and must stay. Never rebase or force-push this branch (it contains a merge commit; the history is
   referenced by the PR discussion).

## 3. Environment and process knowledge that is in no file

- PHP: the repo needs `>=8.4` (`composer.json`). The earlier container had PHP 8.4.11 built from source
  in `/opt/php84/bin`; `php` on the default PATH was 8.3 and unusable. Always run
  `PATH=/opt/php84/bin:$PATH ./vendor/bin/phpunit ...` (and phpcs); `GeneratedCodeFormattingTest` shells out
  to `vendor/bin/phpcs` and fails with the wrong PHP. If `/opt/php84` does not exist in your container,
  you have to install PHP 8.4 first (use `read_documentation` topic `environment.dependencies` /
  `environment.setup_script`; suggest to the user to put it into the environment setup script).
- Test modes: default = latest draft only (quick); `PHPUNIT_FULL_DRAFT_COVERAGE=1` = all drafts, the mode of
  the CI "pull-request" job. A PR with a merge conflict does not get that CI job, which once hid a Draft-07
  failure: merge `master` into the branch (merge, never rebase) whenever GitHub shows a conflict.
- Network policy of the earlier container: coveralls.io, pecl, GitHub tarballs blocked; PyPI works. There is
  no PHP coverage driver, so coverage is judged from the Coveralls status plus the throw-probe above.
- Probing the generator: do not build probe scripts against the working tree; snapshot with
  `git archive <rev> src` and register it first with `$loader->addPsr4('PHPModelGenerator\\', '<snapshot>/src', true)`.
  The generator output directory must exist and be empty; `ValidationException::getInstancePointer()`
  returns an object (use `->pointer`). `dependentRequired`/`dependentSchemas` are NOT implemented by the
  generator, a probe with them tests nothing (this fooled the previous session once).
- Agents: the previous session delegated steps to subagents in git worktrees (with a `vendor/` of symlinks
  for phpunit/sebastian/squizlabs plus copies, then `composer dump-autoload`). Their claims were wrong at
  least once ("the `not` + recursion schema generated on master": false, it was a regression of #207), so
  verify claims with your own run, and read their diffs. Subagents hit the 5-hour usage limit several times;
  their work stays on disk and is resumed with `SendMessage`. If you use agents, tell them the CLAUDE.md
  rules explicitly, they do not reliably follow them.
- Design traps worth remembering (details and rejected alternatives are in the plans): the evaluation state
  stays keyed by property name and is made safe by save/reset/restore in a `finally`; filter declaration
  identity = file + JSON pointer of the filter keyword (`$ref` builds separate validator objects); an
  unresolved `PropertyProxy` read throws `UnresolvedPropertyException extends Error` on purpose, not a
  `LogicException` (`RefResolver` catches `Exception` and would restate it as "Unresolved Reference"); the
  expected side of type mismatch messages uses internal type names (`int`) but the "got" side is the
  verbatim `gettype()` word (`integer`) – CLAUDE.md "Exception message conventions".
- Commit/PR conventions: commit messages and the PR body end with the attribution lines of *your* session
  (the system instructions give them); branch pushes are `git push -u origin claude/fix-213-nested-unevaluated-items`
  and every GitHub comment needs the Claude Code footer. Do not stage `.claude/` again after the cleanup.
- The user reads progress in the Claude app. The previous session kept replies short, surfaced every bug in
  prose with a reproducer before fixing it, and always named the verification it ran. Do the same.
