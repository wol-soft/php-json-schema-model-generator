# CLAUDE.md

## Clarification policy

Before starting any non-trivial task — one that has more than one degree of freedom, including
architectural choices, naming decisions, scope boundaries, approach selection, or any other point
where multiple valid implementations exist — Claude must identify every such ambiguity and ask the
user to resolve it.

Rules:

- Ask all foreseeable clarifying questions upfront in a single batch before work begins.
- If new ambiguities emerge during execution that were not foreseeable upfront, pause and ask
  follow-up questions before proceeding past that decision point.
- For high-stakes decisions (architecture, scope, data model, API shape, behaviour changes) always
  block and wait for an explicit answer.
- For low-stakes decisions (minor naming, formatting, trivially reversible choices) Claude may
  proceed with a clearly stated assumption rather than blocking, but must make the assumption
  visible so the user can correct it.
- There must be no silent interpretation or interpolation of under-specified tasks. If something is
  unclear, ask. Do not guess and proceed.

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

Tests write generated PHP classes to `sys_get_temp_dir()/PHPModelGeneratorTest/Models/` and dump failed classes to `./failed-classes/` (auto-cleaned on bootstrap).

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
- Uses `PropertyProcessorFactory` to instantiate the correct processor by JSON type (String, Integer, Number, Boolean, Array, Object, Null, Const, Any, Reference)
- Convention: processor class name is `PHPModelGenerator\PropertyProcessor\Property\{Type}Processor`
- `ComposedValueProcessorFactory` handles `allOf`, `anyOf`, `oneOf`, `if/then/else`, `not`
- `SchemaDefinitionDictionary` tracks `$ref` definitions to avoid duplicate processing

### Property Processors (`src/PropertyProcessor/`)

- `Property/` — One processor per JSON Schema type
- `ComposedValue/` — Processors for composition keywords
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
  writing any code, so the plan is committed alongside the first code change.
- Every implementation plan must include a dedicated documentation update step. Before finalising
  the plan, audit `docs/source/` (RST), `README.md`, and any other user-facing docs for content
  that would be affected by the change, and add a plan phase that updates those docs. Do not skip
  this even if the doc changes appear minor.
- Commit the plan files together with related code changes so the reasoning is always traceable in
  git history.
- Update the plan file(s) as the work progresses — record decisions made, phases completed, and
  any pivots in approach.
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

#### Test coverage

Every identified edge case must have a corresponding test. During planning, enumerate all edge
cases explicitly (in the implementation plan). Before marking work done, verify that each
enumerated edge case is covered by at least one test.

For pull requests, check the qlty.sh coverage report by constructing the URL from the current PR
number:

```
https://qlty.sh/gh/wol-soft/projects/php-json-schema-model-generator/pull/<PR_NUMBER>/coverage
```

Review the coverage report and address any uncovered lines in changed or new code.

### Union type style

When rendering union types in generated PHP code, use one space before and after the pipe:

```php
int | string | null
```

Not:

```php
int|string|null
```
