# YAML schema support - implementation plan

Design decisions with rejected alternatives: see `analysis.md` -> "Decisions (user)".
Status: design agreed, no code written. Each phase needs an explicit go-ahead.

## Blocker

`composer update` fails in the cloud session: `wol-soft/php-json-schema-model-generator-production`
is required as `dev-master` from GitHub and composer cannot authenticate. Without vendor/ no test,
phpcs or probe can run. Must be resolved before Phase 1 (e.g. attach the production repo to the
session so composer can fetch it, or provide a GitHub token for composer).

## Low-stakes assumptions (stated to user, correctable)

- `Utils\Json\JsonSourcePosition` -> `Utils\SourcePosition` (format-neutral; returned by
  `SchemaFormatInterface::locatePointer`). JSON lexer/locators stay in `Utils\Json`, YAML locator in
  `Utils\Yaml`.
- `Utils\JsonSchema` (RFC 6901 pointer helper) keeps its name: JSON Pointer is format-independent and
  is what `SourceSchema::navigate()` uses for YAML too.
- `SchemaException::invalidJson()` is replaced by a format-neutral named constructor used by every
  format's `decode()` (e.g. `SchemaException::invalidSource($file, $formatName, ?SourcePosition)`).
  Message wording follows the CLAUDE.md exception conventions; grep both repos' tests/docs.
- Alias / merge-key paths in YAML locate to the alias / `<<` site; deeper -> null.

## Phase 1 - core rename + extension-agnostic class names (no behaviour change for JSON)

- Hard rename `Model\SchemaDefinition\JsonSchema` -> `SourceSchema` (src, tests, docs).
- `SchemaProcessor::process()` / `processTopLevelSchema()`: strip any extension
  (`pathinfo($file, PATHINFO_FILENAME)`) instead of `str_ireplace('.json', ...)`.
  Test: reproduce `Pet.yaml`-style naming red first (with a non-.json extension through a custom
  provider), then green.
- `JsonSourcePosition` -> `SourcePosition`.

## Phase 2 - format abstraction, JSON implementation

- `SchemaProvider\Format\SchemaFormatInterface`: `getName()`, `getFileExtensions()`,
  `decode(string $file, string $content): array`, `locatePointer(string $content, string $pointer): ?SourcePosition`.
- `JsonFormat`: current json_decode + `JsonSyntaxErrorLocator` + `JsonPointerLocator` logic.
- `SourceSchema` carries the format instance (required 3rd ctor arg); `SchemaException` asks
  `$schema->getFormat()->locatePointer(...)`. Phase 1 renames only; the arg is added here.
- All providers: `SchemaFormatInterface $format = new JsonFormat()` ctor arg; shared
  read+decode helper replaces the 4 copies; `RecursiveDirectoryProvider` scans
  `$format->getFileExtensions()`.
- `RefResolverTrait::getRef`: decode with provider format; extension not in
  `getFileExtensions()` -> SchemaException (mismatch rejection). Refs without extension / URLs:
  no check, decode with provider format.

## Phase 3 - YAML implementation

- composer `suggest`: symfony/yaml. `YamlFormat` throws clear SchemaException if missing.
- `YamlFormat::decode`: `Yaml::parse($content, Yaml::PARSE_DATETIME)`; ParseException -> SchemaException
  with `getParsedLine()`; reject top-level non-array; walk tree, reject DateTimeInterface / INF / NAN
  with location.
- `Utils\Yaml\YamlPointerLocator`: block maps/seqs, flow collections, quoted keys, comments,
  multi-line scalars; never throws; unresolvable -> null.

## Phase 4 - documentation

- `docs/source/gettingStarted.rst`: provider table (format arg), YAML section, rawSource/line-number
  paragraph (now per format), custom provider example (`SourceSchema`, format).
- `README.md`: provider/format mention, symfony/yaml suggestion.
- Upgrade note for the breaking `JsonSchema` -> `SourceSchema` rename (custom providers, custom
  `ClassNameGeneratorInterface`).
- Grep docs for `JsonSchema(`, `*.json`, `invalidJson`, "JSON-Schema file" wording.

## Edge cases -> tests

- YAML: directory provider, single file, OpenAPI; internal refs; external `./x.yaml`, `./x.yaml#/Frag`;
  whole-file ref inside base dir -> class `Pet` not `PetYaml`; `.yml` + `.yaml`, upper-case extension.
- Mixed ref (YAML -> .json, JSON -> .yaml) -> mismatch SchemaException (full message).
- Anchors + merge keys decode correctly; error inside aliased node -> alias-site location.
- Unquoted date/datetime, `.inf`, `.nan` -> SchemaException with line.
- Syntax error, duplicate key, multi-document, empty file, top-level scalar -> SchemaException with line.
- Semantic schema error in YAML (e.g. contradictory allOf) -> correct line/column.
- Locator: flow mapping, quoted keys with `/` and `~` (pointer escaping), comments, block scalars.
- symfony/yaml missing -> clear SchemaException (hard to test in-suite; consider a seam).
- JSON regression: all existing tests green, positions unchanged.

## Resolved points

- `SourceSchema` format arg is required: `new SourceSchema($file, $json, $format, $pointer, $rawSource)`.
- Extensionless refs / URLs: no mismatch check, decode with provider format.
