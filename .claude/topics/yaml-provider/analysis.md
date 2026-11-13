# YAML schema provider (esp. OpenAPI v3 YAML) — feasibility analysis

Status: evaluation only, no code written. Open decisions listed at the end.

## How the core consumes provider output

The pipeline never reads schema files itself. Exactly three seams touch "file format":

1. `SchemaProviderInterface::getSchemas()` yields `JsonSchema($file, array $decoded, $pointer, ?$rawSource)`.
   Core works only on the decoded PHP array -> format-agnostic.
2. `SchemaProviderInterface::getRef()` for external `$ref`s. Implemented by `RefResolverTrait`,
   which hard-codes `json_decode` + `SchemaException::invalidJson`.
3. `JsonSchema::$rawSource` -> `SchemaException` runs `JsonPointerLocator` (a JSON lexer) over it
   to add "at line X, column Y". JSON-only by construction; `null` is explicitly supported.

Plus one format leak in the core itself:

4. `SchemaProcessor::process()` / `processTopLevelSchema()` derive the fallback class name with
   `str_ireplace('.json', '', basename($file))`. A `Pet.yaml` would become class `PetYaml`.
   Not hit by OpenAPI `components/schemas` entries (the provider injects `$id = <key>`, which wins
   in `ClassNameGenerator`), but IS hit by whole-file external refs inside the base dir
   (`$ref: './schemas/Pet.yaml'`, very common in split OpenAPI specs) because
   `SchemaDefinitionDictionary::parseExternalFile` calls `processTopLevelSchema` for them.

## Probe results (symfony/yaml 7.x, scratchpad)

Parsing only — the full generator could not be run in this session because the generator's own
`composer update` failed (`dev-master` production lib from GitHub: "Could not authenticate against
github.com"). Parser behaviour observed:

| YAML input | PHP value | JSON-equivalent? |
|---|---|---|
| `born: 2020-01-01` | `int(1577836800)` | NO - silent semantic corruption |
| `stamp: 2020-01-01T10:00:00Z` | `int(1577872800)` | NO |
| same with `Yaml::PARSE_DATETIME` | `DateTimeImmutable` | NO (object) |
| `yes/no/on/off` | strings | yes (YAML 1.2 semantics; ext-yaml/libyaml would give bools) |
| `~`, `null` | `null` | yes |
| `.inf`, `.nan` | `INF`, `NAN` | NO - `json_encode` fails ("Inf and NaN cannot be JSON encoded") |
| `{}` / `[]` | `[]` / `[]` | same ambiguity as `json_decode(..., true)`, already handled in core |
| `200: ok` | int key 200 | same as json_decode |
| anchors / `<<` merge keys | resolved transparently | yes |
| duplicate key | `ParseException` "Duplicate key ... at line 2" | stricter than JSON (last-wins) - fine |
| syntax error | `ParseException`, `getParsedLine()` available | line available, but not via `invalidJson` |

Consequence: a YAML provider must normalise to the JSON data model and reject what JSON can't
express, otherwise core receives inputs a JSON provider can never produce
(e.g. `ClassNameGenerator` uses `md5(json_encode($json))` -> `json_encode` returns false on INF).

## Verdict

YAML support can be done entirely in the provider layer for everything that matters, **except**
one tiny core change (class-name extension stripping, item 4). Everything else is either
provider-local or an optional enhancement.

| Concern | Where | Size |
|---|---|---|
| Parse YAML, yield `JsonSchema` | provider | small |
| Normalise / reject non-JSON values (timestamps, INF/NAN, objects) | provider | small |
| External `$ref` to `.yaml`/`.yml` | `RefResolverTrait` (provider layer) | small refactor |
| YAML parse errors with line number | `SchemaException` named ctor (additive) | tiny |
| Class name from `*.yaml` filename | `SchemaProcessor` (core) | one-liner x2 |
| line/column for semantic errors in YAML | core (`rawSource` -> locator abstraction) + own YAML pointer locator | large, defer |

## Proposed design

- Extract the duplicated "read file -> decode -> validate is array -> raw source" logic (present 4x:
  `RecursiveDirectoryProvider`, `SingleFileProvider`, `OpenAPIv3Provider`, `RefResolverTrait`) into a
  loader abstraction in `src/SchemaProvider/`, e.g. `SchemaFileLoaderInterface::load(string $file):
  JsonSchema`-ish, with `JsonFileLoader` (current behaviour, keeps rawSource) and `YamlFileLoader`
  (symfony/yaml, rawSource = null, JSON-data-model normalisation). Loader selected by extension.
- `RefResolverTrait::getRef` delegates decoding to the loader -> mixed JSON/YAML refs work.
- symfony/yaml as composer `suggest`, not `require`; `YamlFileLoader` throws a clear exception if the
  class is missing. Not ext-yaml (YAML 1.1 bool semantics, PECL install).
- Pass `rawSource: null` for YAML. Never feed YAML text to `JsonPointerLocator`.

## Edge cases to cover with tests (if implemented)

- internal `#/components/schemas/X` refs, external `./x.yaml`, `./x.yaml#/Frag`, YAML -> JSON ref,
  JSON -> YAML ref
- whole-file external ref to `Pet.yaml` inside base dir -> class name `Pet`
- anchors + merge keys
- unquoted date/datetime in `default`, `enum`, `const`, `example(s)`
- `.inf` / `.nan`
- syntax error -> SchemaException with file + line
- duplicate keys, multi-document YAML, empty file, top-level scalar
- `.yml` and `.yaml`, upper-case extension
- `{}` vs `[]` (same handling as JSON)

## Docs to update (if implemented)

- `docs/source/gettingStarted.rst` provider table + rawSource paragraph (YAML has no line/column)
- `README.md` provider mention, composer `suggest`

## Decisions (user)

- Generic solution enabling ALL current providers (directory, single file, OpenAPI, ref resolution),
  not a dedicated `YamlOpenAPIv3Provider`. Rejected: separate YAML-only provider class - duplicates
  provider logic per format and leaves directory/single-file/ref resolution JSON-only.
- Line/column reporting must work for YAML too. Rejected: `rawSource: null` for YAML (v1 without
  locations) - user requires parity with JSON error reporting.
- Format handling becomes a core abstraction (direction from user: `JsonSchema` -> more generic
  `SourceSchema`, format exposed via enum or via an interface with JSON/YAML implementations,
  extensible to further formats). Exact shape: open question 1 below.
- Class-name derivation strips any file extension (not just `.json`).
- Format abstraction: `SchemaFormatInterface` (getFileExtensions / decode / locatePointer) with
  `JsonFormat` + `YamlFormat`; the schema object carries the format instance and `SchemaException`
  asks it for positions. Rejected: `SchemaFormat` enum + core `match` - every new format edits the
  enum and every match site, and custom providers cannot bring their own format. Rejected: enum
  wrapping the interface - same closed-set problem, enum adds nothing behaviour-wise.
- Hard rename `Model\SchemaDefinition\JsonSchema` -> `SourceSchema`, no deprecated alias (breaking:
  custom providers + custom `ClassNameGeneratorInterface` impls; major-release change). Rejected:
  deprecated alias - user prefers clean break. Rejected: keep name - YAML in a class named JsonSchema.
  Watch: unrelated `Utils\JsonSchema` helper keeps its name unless decided otherwise.
- YAML positions: decode with symfony/yaml + own `YamlPointerLocator` over raw text (block maps/seqs,
  flow collections, quoted keys, comments, multi-line scalars); unresolvable constructs -> null.
  Rejected: full locator covering anchors/aliases/merge keys/tags/complex keys - effectively a second
  YAML grammar. Rejected: own YAML parser - largest effort and maintenance burden.
  Assumption (low-stakes, stated to user): a pointer whose remaining path runs through an alias or
  merge key resolves to the alias / `<<` site; anything deeper -> null.
- Non-JSON YAML values: decode with `PARSE_DATETIME`, walk the tree, throw SchemaException (with
  location) on any DateTimeInterface, INF or NAN anywhere. Rejected: converting timestamps back to
  strings - lexical form is lost (`2020-01-01` vs `2020-01-01T00:00:00Z`), generated default may differ
  from source. Rejected: keyword-scoped rejection - needs per-draft keyword list, YAML layer would have
  to know schema semantics.
- symfony/yaml goes in composer `suggest`; `YamlFormat` checks `class_exists` and throws a clear
  SchemaException on first YAML file. Rejected: `require` - forces the package on JSON-only users.
- One format per provider instance: ctor arg `SchemaFormatInterface $format = new JsonFormat()`;
  discovery uses `$format->getFileExtensions()`. Rejected: registry with JSON default / all formats -
  user does not want several formats in one run; all-formats default would also pick up stray YAML
  files (CI configs) in existing directories. Rejected: fixed-by-extension - no extensibility.
- External `$ref` to a file of another format -> SchemaException (mismatch rejection). Rejected:
  decoding with provider format unchecked - JSON provider on a .yaml ref gives a confusing "invalid
  JSON" error. Rejected: per-extension ref format list - reintroduces a registry; mixing can be added
  later without a break.
- `SourceSchema` format ctor arg is required (3rd positional, before pointer/rawSource). Rejected:
  optional default JsonFormat - a YAML provider forgetting it silently gets JSON locating on YAML
  text; the hard rename already breaks callers so requiring it costs nothing extra.
- Extensionless `$ref` targets (URLs, local files) skip the mismatch check and decode with the provider
  format; a wrong format surfaces as that format's syntax error. Rejected: reject extensionless refs -
  breaks existing JSON setups referencing extensionless `$id` URLs. Rejected: HTTP Content-Type
  sniffing - servers often send text/plain, adds header handling to the ref resolver.

## Open decisions (to be asked one at a time)

1. Format abstraction shape: `SchemaFormat` enum + core `match` vs. `SchemaFormatInterface`
   (decode / locate pointer / locate syntax error / file extensions) with JSON + YAML impls.
2. Rename `JsonSchema` -> `SourceSchema` (public API, used by custom providers + `ClassNameGeneratorInterface`):
   hard rename vs. rename with deprecated alias vs. keep name.
3. YAML parser + pointer locator: symfony/yaml exposes no node positions -> own YAML pointer locator needed.
4. Unquoted YAML timestamps / INF / NAN: reject vs. convert.
5. Dependency: symfony/yaml `suggest` vs `require`.

## Superseded open decisions

1. API shape: separate `YamlOpenAPIv3Provider` class vs. `OpenAPIv3Provider` detecting the format by
   extension (and same question for `RecursiveDirectoryProvider` / `SingleFileProvider`).
2. Unquoted YAML timestamps: reject with SchemaException ("quote the value") vs. convert back to string.
3. Dependency: symfony/yaml as `suggest` vs. `require`.
4. Scope of line/column reporting for YAML (accept none in v1?).
