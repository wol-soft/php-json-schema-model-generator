# CLAUDE.md

## Responding to review notes

When working through a list of review notes, critically evaluate each note before acting on it:

- **Is the note correct?** The reviewer may be mistaken about what the code does, or may be
  operating on a false assumption. If the note is factually wrong, explain why and skip it.
- **Is the proposed fix better than the current approach?** The reviewer's suggestion is a
  starting point, not a mandate. If a different solution is clearly superior, propose it.
- **Is there an even better solution?** Think beyond the note. If the reviewer flags a smell,
  consider whether the right fix is the one they suggest or a deeper redesign.
- **Document the reasoning.** For each note, produce a summary of what action was taken and why
  — including why any note was rejected or handled differently than suggested.

After tackling all notes, provide a summary table: one row per note, action taken, and brief
reasoning.

---

## Learning from reviews

After completing a task that involved responding to code review feedback, scan the reviewer's
corrections and confirmations for patterns not already captured in memory or in this file. For
each non-obvious pattern found, write or update a `feedback` memory file in the project memory
directory and add a pointer to `MEMORY.md`.

What qualifies as worth saving:
- Any correction the reviewer had to make that I should have caught myself.
- Any expectation that surprised me or that I applied incorrectly.
- Any confirmation that a non-obvious approach was right (so it is not silently reversed later).

What does not qualify:
- One-off fixes specific to a single schema or class.
- Anything already stated verbatim in this file.
- Trivially obvious mistakes with no generalizable lesson.

Do this at the end of the session, not during — so it does not interrupt implementation work.

---

## Clarification policy

Before starting any non-trivial task — one that has more than one degree of freedom, including
architectural choices, naming decisions, scope boundaries, approach selection, or any other point
where multiple valid implementations exist — Claude must identify every such ambiguity and ask the
user to resolve it.

Rules:

- When there are multiple clarifying questions to ask, ask them **one at a time**, in order of
  dependency (earlier answers may resolve later questions). Wait for the answer before asking the
  next question. This allows the user to discuss each point in depth without being overwhelmed by
  a wall of questions.
- If new ambiguities emerge during execution that were not foreseeable upfront, pause and ask
  follow-up questions before proceeding past that decision point.
- For high-stakes decisions (architecture, scope, data model, API shape, behaviour changes) always
  block and wait for an explicit answer.
- For low-stakes decisions (minor naming, formatting, trivially reversible choices) Claude may
  proceed with a clearly stated assumption rather than blocking, but must make the assumption
  visible so the user can correct it.
- There must be no silent interpretation or interpolation of under-specified tasks. If something is
  unclear, ask. Do not guess and proceed.
- For multi-phase implementations, **never start the next phase without an explicit go-ahead from
  the user**. After completing a phase, summarise what was done and wait for confirmation before
  proceeding.

When generating a new CLAUDE.md for a repository, include this clarification policy verbatim as a
preamble before all other content.

---

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Install dependencies
composer update

# Run all tests
./vendor/bin/phpunit

# Run a single test file
./vendor/bin/phpunit tests/Basic/BasicSchemaGenerationTest.php

# Run a specific test method
./vendor/bin/phpunit --filter testMethodName

# Run tests with descriptive output
./vendor/bin/phpunit --testdox
```

Tests write generated PHP classes to a session-unique directory `sys_get_temp_dir()/PHPModelGeneratorTest_<id>/Models/` (defined as `MODEL_TEMP_PATH`; the base is `TEST_BASE_DIR`) and dump failed classes to `./failed-classes/` (auto-cleaned on bootstrap). The session directory is cleaned up automatically via a shutdown function when the PHP process exits.

### Running the full test suite

When running the full test suite, always save output to a file so the complete
output is available for analysis without re-running. Use `--display-warnings` to capture warning
details and `--no-coverage` to skip slow coverage collection:

```bash
php -d memory_limit=128M ./vendor/bin/phpunit --no-coverage --display-warnings 2>&1 | sed 's/\x1b\[[0-9;]*m//g' > /tmp/phpunit-output.txt; tail -5 /tmp/phpunit-output.txt
```

Then analyse with: `grep -E "FAIL|ERROR|WARN|Tests:" /tmp/phpunit-output.txt`

After analysis is complete, delete the file: `rm /tmp/phpunit-output.txt`

## Architecture

This library generates PHP model classes from JSON Schema files. The process is a 4-step pipeline:

1. **Schema Discovery** — Scan a source directory for `*.json` files (or use a custom `SchemaProviderInterface`)
2. **Schema Processing** — Parse each JSON Schema into a `Schema` model containing properties and validators
3. **Post-Processing** — Apply post processors to extend/modify the generated class model
4. **Rendering** — Execute the `RenderQueue` to write PHP files to disk

### Entry Point

`ModelGenerator` (`src/ModelGenerator.php`) is the main orchestrator. It accepts a `GeneratorConfiguration` and calls `SchemaProcessor` to process schemas, collecting `RenderJob`s into a `RenderQueue`.

### Core Models

- **`Model/GeneratorConfiguration.php`** — Builder-style config object controlling namespace, immutability, serialization, error collection, filters, etc.
- **`Model/Schema.php`** — Represents one PHP class to be generated; holds properties, validators, traits, interfaces, and used-class imports
- **`Model/RenderJob.php`** — A pending render operation for one class
- **`Model/Property/`** — Property hierarchy: `PropertyInterface` → `AbstractProperty` → `Property`; `CompositionPropertyDecorator` wraps composed properties
- **`Model/Validator/`** — Validator classes (AdditionalProperties, ArrayItem, Composition, Enum, Format, etc.) attached to properties or schemas

### Schema Processing

`SchemaProcessor` (`src/SchemaProcessor/SchemaProcessor.php`) orchestrates property parsing:
- Uses `PropertyFactory` (`src/PropertyProcessor/PropertyFactory.php`) to create and configure each property
- `PropertyFactory` resolves `$ref` references, delegates `object` types to `processSchema`, and for all other types constructs a `Property` directly and applies Draft modifiers
- `ComposedValueProcessorFactory` handles `allOf`, `anyOf`, `oneOf`, `if/then/else`, `not`
- `SchemaDefinitionDictionary` tracks `$ref` definitions to avoid duplicate processing

### Draft System (`src/Draft/`)

The Draft system defines per-type modifier and validator registrations:
- **`DraftInterface`** / **`DraftBuilder`** / **`Draft`** — Draft definition, builder, and built (immutable) registry
- **`Draft_07.php`** — The JSON Schema Draft 7 definition; registers all types, modifiers, and validator factories
- **`Element/Type`** — One entry per JSON Schema type; holds an ordered list of `ModifierInterface` instances
- **`Modifier/`** — `TypeCheckModifier`, `ConstModifier`, `NumberModifier`, `NullModifier`, `ObjectType/ObjectModifier`, `DefaultValueModifier`, `DefaultArrayToEmptyArrayModifier`; each implements `ModifierInterface::modify()`
- **`Model/Validator/Factory/`** — `AbstractValidatorFactory` subclasses keyed to schema keywords (e.g. `MinLengthPropertyValidatorFactory` for `minLength`); run as modifiers when a matching key exists in the schema

`PropertyFactory::applyDraftModifiers` resolves `getCoveredTypes($type)` (which always includes `'any'`) and runs every modifier for each covered type in order.

### Property Processors (`src/PropertyProcessor/`)

- `ComposedValue/` — Processors for composition keywords (`allOf`, `anyOf`, `oneOf`, `if/then/else`, `not`)
- `Filter/` — Custom filter processing
- `Decorator/` — Property decorators (ObjectInstantiation, PropertyTransfer, IntToFloatCast, etc.)

### Post Processors (`src/SchemaProcessor/PostProcessor/`)

Post processors modify the `Schema` model after initial processing. They are split into:
- **Internal (always applied):** `CompositionValidationPostProcessor`, `AdditionalPropertiesPostProcessor`, `PatternPropertiesPostProcessor`, `ExtendObjectPropertiesMatchingPatternPropertiesPostProcessor`
- **Optional (user-configured):** `BuilderClassPostProcessor`, `EnumPostProcessor`, `PopulatePostProcessor`, `SerializationPostProcessor`, `AdditionalPropertiesAccessorPostProcessor`, `PatternPropertiesAccessorPostProcessor`

### Templates

Code is rendered from PHP template files (`.phptpl`) in:
- `src/Templates/` — Main class template and type-specific validator/decorator templates
- `src/SchemaProcessor/PostProcessor/Templates/` — Templates for each post processor

### Schema Providers (`src/SchemaProvider/`)

Implement `SchemaProviderInterface` to supply schemas from custom sources. Built-in: `RecursiveDirectoryProvider`, `OpenAPIv3Provider`.

### Testing Patterns

`AbstractPHPModelGeneratorTestCase` is the base class for all tests. Tests generate model classes into a temp directory and then instantiate/exercise them to verify validation behavior. The `tests/manual/` directory contains standalone scripts excluded from the test suite.

#### Test case consolidation

Each call to `generateClassFromFile` triggers a code generation pass, which is the dominant cost in the test suite. **Minimise the number of distinct `generateClassFromFile` calls** by combining assertions that share the same schema file and `GeneratorConfiguration` into a single test method.

Rules:

- Group assertions by `(schema file, GeneratorConfiguration)` pair. All assertions that can use the same generated class belong in one test method.
- A single test method may cover multiple behaviours (e.g. key naming, round-trip, `$except`, custom serializer) as long as they all operate on the same generated class. Use clear inline comments to separate the logical sections.
- Only split into separate test methods when the behaviours require genuinely different configurations, or when combining them would make the test too complex to understand at a glance.
- The goal is the balance between runtime efficiency (fewer generations) and readability (each method remains comprehensible). Avoid both extremes: a single monolithic test and a proliferation of single-assertion tests.

### JSON Schema style

In test schema files (`tests/Schema/**/*.json`), every object value must be expanded across multiple lines — never written inline. Use this style:

```json
"name": {
  "type": "string"
}
```

Not this:

```json
"name": { "type": "string" }
```

Boolean and scalar values (`false`, `true`, `null`, numbers, strings) on a single line are fine.

### Schema error handling

Detect and reject invalid or contradictory schemas early, during the schema processing pipeline,
by throwing `SchemaException`. Do not silently generate broken or misleading code. This applies to
every detectable invalid case — including `allOf` branches with contradictory types for the same
property, duplicate property names with unresolvable type conflicts, and any other schema structure
that cannot produce a correct PHP model. Fail loudly at generation time so the developer sees the
problem immediately rather than receiving silently incorrect generated code.

### Filter callable classes must be in the production library

A `FilterInterface::getFilter()` callable is embedded verbatim in generated PHP code and is
called at runtime — without the generator package being present. Any class referenced in
`getFilter()` must therefore live in `php-json-schema-model-generator-production`, not in this
generator package. Using a generator-package class as a filter callable will produce generated
code that fails at runtime whenever the generator is not installed.

If a production-library class lacks the required type hints (needed for reflection-based type
derivation), the fix is to add or update the callable in the production library, not to create
a wrapper class here.

### Staging changes

After finishing an implementation task, always stage all relevant changed files for commit using
`git add`. Do not wait for the user to ask — stage immediately when the work is done.

Never add `.claude/` files (issues, topics, memory, etc.) to git unless the user explicitly asks.
These are working notes for the session and must not appear in commits.

**Always review the diff against the repo rules before staging.** Before running `git add`,
inspect the full diff (`git diff` for unstaged work, plus `git diff --staged` afterwards) and
verify every change conforms to the rules in this file. In particular, run the recovery procedure
from the "No implementation-plan references in code" rule: grep the diff for `Stage `, `Phase `,
`decision `, `§`, and `#` followed by a number, and rewrite every match in source, test, or
prod-lib code (test data providers, DocBlocks, and inline comments included) before staging.
This review is *mandatory*, not optional — staging without it lets violations slip into commits.

The same review applies to changes pushed to a coordinated production-library checkout: source
code in `php-json-schema-model-generator-production` is bound by the same rules as code in this
repo. Planning artefacts under `.claude/` are the only place plan references may live.

### Pre-existing rule violations in touched files

Whenever you edit, read, or otherwise touch a file as part of any task, sweep it for *all*
pre-existing violations of the rules in this file — implementation-plan references, single-
letter variables, leading-backslash class references, missing `use` imports, copy-pasted
docblocks, PHPCS errors visible in the local run, and anything else CLAUDE.md forbids — and
fix every one in the same change. Do not leave a known violation sitting just because it
predates your edit; "broken windows" is exactly how decay accumulates and the rule erodes.

Scope: this is about files you *touch*, not a codebase-wide audit. If you edit a method,
scan the whole file (not just the surrounding lines) and fix everything visible. If
fixing the pre-existing violations would balloon the diff into an unrelated refactor,
flag it (and only then) before proceeding — that is the only escape hatch. Default is:
clean it up.

The rule applies symmetrically to the production-library checkout when you edit anything
there.

### Reading files

Always use the dedicated `Read` tool to read file contents. Never use `sed`, `head`, `tail`, `cat`, or `awk` to read or extract portions of files. The `Read` tool supports `offset` and `limit` parameters for reading partial files when needed.

### Variable naming

Never use single-character variable names. All variables must have meaningful, descriptive names
that convey their purpose. For example, use `$typeName` instead of `$t`, `$validator` instead of
`$v`, `$property` instead of `$p`.

Never prefix local variables with an underscore. The underscore prefix is reserved for *class
member* identifiers (instance properties, internal methods like `_validateTags`) where it marks
the symbol as internal-by-convention. Applying the same prefix to local variables blurs the
member/local distinction without adding information. Use the plain name instead:
`$branchContainsMatches`, not `$_branchContainsMatches`. The rule applies symmetrically to
variables declared inside generated template code that escape into the generated PHP output —
local variables inside template-emitted closures and IIFEs are still local PHP variables and
must not carry the underscore prefix.

### PHP import style

Always add `use` imports for every class referenced in a file, including global PHP classes such as
`TypeError`, `InvalidArgumentException`, `RuntimeException`, `stdClass`, and PHP Reflection classes.
Never reference them with a leading backslash (`\TypeError`); import and use the short name instead.

### Issue and topic tracking

For every GitHub issue or non-trivial investigation topic being worked on, create a dedicated
directory at `.claude/issues/<number>/` (for GitHub issues) or `.claude/topics/<slug>/` (for
freestanding investigations). Store all analysis, design notes, and implementation plans there as
Markdown files.

Rules:

- Create the directory and at least a stub `implementation-plan.md` (or `analysis.md`) before
  writing any code. The plan is working context for the current session, not a git artefact.
- Every implementation plan must include a dedicated documentation update step. Before finalising
  the plan, audit `docs/source/` (RST), `README.md`, and any other user-facing docs for content
  that would be affected by the change, and add a plan phase that updates those docs. Do not skip
  this even if the doc changes appear minor.
- **Never add planning documents to git — not even on feature branches.** Files under
  `.claude/issues/` and `.claude/topics/` are working notes for Claude's use only. Never stage or
  commit them. If they appear in `git status`, run `git restore --staged <file>` immediately.
- Update the plan file(s) as the work progresses — record decisions made, phases completed, and
  any pivots in approach.
- **Record every non-obvious design decision as it is made**: state the option chosen, every
  alternative that was considered and rejected, and the reasoning that ruled each alternative out.
  A rejected alternative that is not recorded can be silently re-introduced in a later session
  when context is compressed. The record must be specific enough that a cold reader can reconstruct
  *why* the chosen approach is correct — not just *what* it is. Example: "Classifying `type`
  against `$outputTypes` was considered and rejected: a branch `{type: integer}` under a
  `stringToInt` filter must validate the raw input, so routing it through output-type matching
  would allow string `'50'` to pass a `type: integer` check post-transform."
- Once a topic is **ready to merge**, delete the entire `.claude/issues/<number>/` or
  `.claude/topics/<slug>/` directory and commit that deletion as the final commit on the branch,
  **before** merging to `master`. The tracking files are working notes and must never land on
  `master`.

Example layout for issue #110:

```
.claude/issues/110/
  analysis.md               ← initial investigation and option evaluation
  implementation-plan.md    ← phased plan, updated as phases complete
  union-type-preparation.md ← supplementary preparatory notes
  union-type-test-coverage.md
  phase6-merger-analysis.md
```

### PHP version compatibility

Before writing any code, check `composer.json` for the minimum PHP version (`require.php`). All
source code must be compatible with that version. Do not use language features
introduced in a later PHP release.

### Code review and implementation quality

Apply these standards both while implementing and as a final review before considering work done.

#### Code quality

Before committing, run PHP CodeSniffer on all changed files and resolve every reported issue:

```bash
./vendor/bin/phpcs --standard=phpcs.xml <changed-files>
```

The project uses a custom `phpcs.xml` based on PSR-12. When a new rule triggers an issue for the
first time, ask the user whether the rule should be applied or disabled, then update `phpcs.xml`
accordingly before proceeding.

For pull requests, also check the qlty.sh issues page by constructing the URL from the PR number:

```
https://qlty.sh/gh/wol-soft/projects/php-json-schema-model-generator/pull/<PR_NUMBER>/issues
```

The scan on that page must be triggered manually via the button in the UI before results are
visible. Review all reported issues and resolve or consciously justify every relevant finding.

#### Architectural fit

New code must fit naturally into the existing pipeline and responsibility model:
- Schema processing logic belongs in processors; post-processing modifications belong in post
  processors; validation rules belong in validators; rendering logic belongs in templates.
- When in doubt, ask where analogous existing behaviour lives and follow that pattern.

#### General solutions over workarounds

Prefer solutions that address the underlying problem at the right level of abstraction. A fix
that works for one specific schema shape but breaks or ignores others is not acceptable. Before
implementing, ask: "Does this solution handle the general case, or only the example at hand?" If
only the specific case, redesign until the solution is general.

#### Never narrow test scope to evade failures

When a test exposes a real bug, fix the bug — do not simplify, remove, or replace the test with
one that avoids the failing scenario. A failing test is evidence of a defect; discarding it hides
the defect rather than resolving it.

This applies in both directions:
- Never swap a schema or assertion for a simpler variant just because the original triggers an
  error in the implementation. The original schema is the spec; make the implementation handle it.
- Never stub out, skip, or weaken assertions to make a test green. If the assertion is wrong,
  fix the assertion with an explicit justification; if the implementation is wrong, fix the
  implementation.

When the straightforward test case surfaces a deeper issue (object instantiation, type conflict,
priority ordering, etc.), that is precisely the issue that needs solving. Open it as a tracked
topic if it cannot be addressed immediately, but keep the test in place and marked as expected to
fail (`@expectedExceptionMessage`, `$this->expectException(...)`) until the fix lands.

#### How to handle every bug found during development

This rule generalises the test-evasion rule above to every bug, however it is discovered —
through a failing test, while reading code, during a debugging session, or as a side-observation
in an unrelated task.

**Every bug must be acknowledged explicitly the moment it is found.** State, in the user-facing
response, *exactly* what the bug is — the failure mode, the code path, and a minimal reproducer
or pointer to one. Do not let bugs surface implicitly as test failures the user has to dig out
of the log; surface them in prose.

**Write a test that reproduces the bug *before* fixing it.** This applies whether the bug is
about to be fixed in the same change (path 1 below) or deferred (path 2). The test name encodes
the specific scenario so a regression surfaces immediately as a named, self-explaining failure
rather than a cryptic assertion error elsewhere. When deferring, the test is marked failing
(`$this->expectException(...)` plus an explicit assertion of the *current wrong* behaviour, or
`#[Test] #[ExpectedFailure]` if the framework supports it) so the gap is visible in CI until
the fix lands. When fixing in the same change, the test starts red and turns green as the fix
lands — the diff carries proof that the change actually closes the reported scenario, not just
that other tests still pass. Sequence: reproduce → confirm red → fix → confirm green. Do not
skip the "confirm red" step; a test that was always green is no evidence of anything.

**Every acknowledged bug takes one of three paths. Choose explicitly, never silently:**

1. **Fix it in the same change.** Default path. If the bug is reachable from the work in
   progress and fixing it does not balloon the diff into an unrelated refactor, fix it now and
   note the fix in the response.
2. **Defer with a tracked artifact.** Only when the fix is genuinely out of scope (different
   subsystem, requires user direction on architecture, blocked on external work). Deferral
   requires *both*:
   - A tracking entry: a `.claude/topics/<slug>/` plan stub, a `@expectedException` test
     fixture marked failing, OR an entry in the active plan's post-implementation review list
     — whichever fits the current workflow.
   - A surfacing mechanism in the codebase: the failing test, a `throw new \LogicException(...)`
     at the unreachable site, or a documented assertion. Comments alone are not enough — a
     comment without an enforcement mechanism rots silently.

   State the deferral and the chosen tracking + surfacing mechanism in the same response that
   announces the bug.
3. **Reject it as not-a-bug.** When closer reading reveals the apparent bug is correct behaviour
   under a constraint you missed. Explain the constraint in the response and update any
   misleading comment, test, or doc that suggested otherwise.

**Routing around a bug is forbidden.** Removing a test, swapping a fixture for one that does not
trigger the bug, narrowing a test's assertion to skip the affected output, choosing an
alternative implementation path purely to avoid touching the buggy subsystem, or deciding "this
edge case is rare so I'll not test it" — all of these are silent suppression. They are explicitly
not in the deferral path: deferral requires the bug to remain *visible*, just not yet fixed.

**A bug downstream of your change is still your bug.** When your edit causes a previously-passing
test to fail, the test is reporting a real defect in your change — even if the test was
"unrelated" before. Do not dismiss it as "pre-existing brittleness"; the failure path is now in
scope. Either your change has a bug, or the test was wrong all along and now is the right time
to fix it (explicitly, with justification). The same applies symmetrically: when you discover an
existing bug while reading code, the "broken windows" rule from "Pre-existing rule violations in
touched files" still applies — flag it, then decide between paths 1, 2, and 3.

**Why this matters.** Silent bug suppression is the most insidious form of code rot because each
individual instance looks like a reasonable scope-management decision. Over a long session the
cumulative effect is a codebase where everyone knows "you can't go that way" and the deferred
defects compound. Surfacing every bug — every time, in prose, with a path forward — is the only
discipline that prevents this.

#### No implementation-plan references in code

Do not embed references to implementation-plan phases, section numbers, decision identifiers,
issue numbers, or source-code line numbers in comments, docblocks, filenames, test fixture
descriptions, or any other artifact that lands in the repository. These references decay
immediately (plans get restructured, phases complete, sections renumber, line numbers shift)
and add noise without adding meaning to a reader who does not have the plan open in another
tab.

**Patterns that violate the rule** — anything in this category must be rewritten:
- `Phase N`, `Phase N's`, `phase N landed`
- `decision N.N`, `Decision N.N`, `per decision N.N`
- `§N.N`, `§N.N.N`, `section N.N`, `§N.N's matrix`
- `Per §N.N`, `Follows §N.N`, `the §N.N test list`
- Issue numbers (`#123`) used as a stand-in for an explanation
- Specific line numbers in the codebase (`lines 130-158`, `line 429`)
- References to documents under `.claude/` from anywhere outside `.claude/`

**Examples:**
- ❌ `// Phase 2 guarantees anyOf/oneOf have uniform spaces`
- ✅ `// Static rejection guarantees anyOf/oneOf have uniform spaces`
- ❌ `* Emission policy follows the §3.5.2.1 / decision 0.10 matrix`
- ✅ `* Emission policy: emit when the keyword's reach is non-empty (additionalProperties absent or true)`
- ❌ `// Dead-code rows from §4.1: additionalProperties: false or {schema}`
- ✅ `// additionalProperties: false / {schema} leave the unevaluated bucket permanently empty`
- ❌ `* Covers FilterValidator::runCompatibilityCheck lines 130–158`
- ✅ `* Validates the zero-overlap rejection path in FilterValidator`
- ❌ `* exercises FilterProcessor line 429 (else branch of classifyValidatorAdjustments)`
- ✅ `* exercises the else branch of classifyValidatorAdjustments`
- ❌ `// Decision 0.3: Also harvest inline branch property names`
- ❌ `// Decision 0.6 unconditional rollback`
- ❌ `// Phase 3's UnevaluatedPropertiesValidator can query...`
- ❌ `// not with inline branch — Decision 0.6: slot permanently success=false`

This rule applies equally to DocBlocks in test files: do not reference specific line numbers
of the code under test, decision identifiers from the plan, or section numbers anywhere in the
plan. Line numbers shift whenever the file is edited, and section/decision numbers decay
whenever the plan is restructured. Describe *what the code does or why* instead.

**Recovery procedure when this rule is violated:** before staging a change, grep the diff for
`Phase `, `decision `, `§`, and `#` followed by a number. Rewrite every match found in source
or test files to a self-contained explanation of the rule or behaviour.

Describe *what the code does or why* — not where it came from in a planning document.

#### Name the rejected alternative in non-obvious comments

When a decision is non-obvious — especially one where a "natural correction" would silently
re-introduce a wrong approach — the comment must name the rejected alternative and explain why it
fails, not just assert the chosen approach.

- ❌ `// type keyword is always classified as Input`
- ✅ `// Always Input — do NOT classify against outputTypes. A branch {type: integer} under a
  //   stringToInt filter must validate the raw input; treating it as Output would allow string
  //   '50' to pass a type: integer check post-transform.`

The same decision must also be covered by a test whose name encodes the specific scenario, so
that any regression surfaces immediately as a named, self-explaining failure rather than a
cryptic assertion error.

#### Test coverage

Every identified edge case must have a corresponding test. During planning, enumerate all edge
cases explicitly (in the implementation plan). Before marking work done, verify that each
enumerated edge case is covered by at least one test.

#### Exception message assertions

Always assert the **complete** exception message, not just a substring. Construct the expected
message in full using the same inputs the code under test uses. Use regex (via
`expectExceptionMessageMatches` or `assertMatchesRegularExpression`) only for genuinely dynamic
parts that cannot be predicted upfront (e.g. file paths, uniqid suffixes).

Never use multiple `assertStringContainsString` calls on the same exception message when the full
message can be constructed. A single `assertSame($expectedMessage, $exception->getMessage())` is
both stronger and self-documenting.

When the expected exception message spans multiple lines (e.g. an `ErrorRegistryException`
joining several sub-errors with `"\n"`, or any nested-exception format that embeds newlines),
**always write the expected value as a heredoc**, never as a `sprintf` call with `\n` escapes
or as concatenated `.` string fragments. Heredoc preserves the literal layout of the message
exactly as it will appear at runtime, so the test source reads as the message and a diff
against the actual output is line-by-line. Use the variable-interpolating `<<<MSG ... MSG;`
form when the message embeds dynamic class names or other runtime values; use the literal
`<<<'MSG' ... MSG;` form only when no interpolation is needed.

Inline the heredoc directly into the `assertSame` call rather than assigning it to a local
variable first — the assertion reads as a single self-contained statement that places the
expected message next to the actual one, which is what a reader is comparing.

For pull requests, check the qlty.sh coverage report by constructing the URL from the current PR
number:

```
https://qlty.sh/gh/wol-soft/projects/php-json-schema-model-generator/pull/<PR_NUMBER>/coverage
```

Review the coverage report and address any uncovered lines in changed or new code.

### Docblock content

Write docblocks only when they add information beyond what the code already expresses.

- **Omit** class-level `@package` tags and `Class ClassName` lines — the namespace declaration and class keyword already carry that information.
- **Omit** method docblocks whose prose just restates the method name (e.g. `/** Returns the foo. */` above `getFoo(): Foo`). Write a docblock only when it explains *why* something is done, a non-obvious contract, or a type constraint that native PHP cannot express (e.g. `@return SomeClass[]`).
- **Do not copy-paste** identical `@param` descriptions across multiple methods. Each docblock should describe what is specific to that method's use of the parameter.

### Union type style

When rendering union types in generated PHP code, use one space before and after the pipe:

```php
int | string | null
```

Not:

```php
int|string|null
```
