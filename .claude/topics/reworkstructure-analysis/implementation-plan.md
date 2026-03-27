# Implementation Plan: Draft-Based Architecture Rework

Based on `implementation-analysis.md` and the Q&A decisions recorded there.

---

## Guiding principles

- Every phase must leave the full test suite green before the next phase begins.
- Each phase is a standalone PR — no phase may depend on uncommitted work from another.
- The existing integration-style test suite (generate → instantiate → assert) is the primary
  regression guard. Unit tests for new classes are added in the same phase that introduces them.
- "Non-breaking inside the phase" means: no public API removal until the phase that explicitly
  targets that removal (the public API of the library is `ModelGenerator`, `GeneratorConfiguration`,
  and the Schema/Property interfaces used by post-processors).

---

## Phase 0 — `RenderJob` simplification

**Goal**: Remove `classPath`/`className` from `RenderJob` constructor (they already live on
`Schema`). Purely internal, zero behaviour change.

**Scope**:
- Read `RenderJob` constructor — already done: it only takes `Schema $schema`. This phase is
  already complete in the current codebase. **Skip.**

---

## Phase 1 — Introduce `DraftInterface`, `Draft_07`, `AutoDetectionDraft` **[DONE]**

**Goal**: Define the Draft abstraction and wire it into `GeneratorConfiguration`. All existing
processor classes remain intact and are not yet called through the Draft. The Draft is structurally
present but the pipeline does not use it yet.

### Implemented structure

New files (all created and committed):
- `src/Draft/Modifier/ModifierInterface.php` — single method:
  `modify(SchemaProcessor, Schema, PropertyInterface, JsonSchema): void`
- `src/Draft/Element/Type.php` — holds a type name, an optional `TypeCheckModifier` added by
  default, and a list of `ModifierInterface[]`. Has `addModifier(ModifierInterface): self` and
  `getModifiers(): ModifierInterface[]`.
- `src/Draft/DraftBuilder.php` — collects `Type` objects keyed by type name;
  `addType(Type): self`, `getType(string): ?Type`, `build(): Draft`.
- `src/Draft/Draft.php` — value object holding `Type[]`; `getTypes(): Type[]`,
  `getCoveredTypes(string|array $type): Type[]` (includes `'any'` type automatically).
- `src/Draft/DraftInterface.php` — `getDefinition(): DraftBuilder`
- `src/Draft/DraftFactoryInterface.php` — `getDraftForSchema(JsonSchema): DraftInterface`
- `src/Draft/Draft_07.php` — implements `DraftInterface`. Returns a `DraftBuilder` with all
  seven JSON Schema types (`object`, `array`, `string`, `integer`, `number`, `boolean`, `null`)
  plus an `'any'` pseudo-type for universal modifiers. Types currently have only
  `TypeCheckModifier` (via `Type` constructor); `object` and `any` pass `false` for
  `$typeCheck`. `'any'` holds `DefaultValueModifier`.
- `src/Draft/AutoDetectionDraft.php` — implements `DraftFactoryInterface`. Caches draft
  instances per class. Currently always returns `Draft_07` (all schemas fall back to it).
- `src/Draft/Modifier/TypeCheckModifier.php` — implements `ModifierInterface`; adds
  `TypeCheckValidator` if not already present.
- `src/Draft/Modifier/DefaultValueModifier.php` — implements `ModifierInterface`; reads
  `$json['default']` and calls `$property->setDefaultValue(...)`.

`GeneratorConfiguration` holds a `DraftFactoryInterface $draftFactory` (defaulting to
`AutoDetectionDraft`) with `getDraftFactory()` / `setDraftFactory()`.

### Docs for Phase 1

- Add `docs/source/generator-configuration.rst` section describing `setDraftFactory()` and the
  draft concept at a high level.

---

## Phase 2 — Eliminate `PropertyMetaDataCollection`

**Goal**: Drop `PropertyMetaDataCollection` entirely. Both pieces of data it carried
(`required` array and `dependencies` map) are already available from `Schema::getJsonSchema()`
or can be passed as a plain `bool $required` at call sites. This eliminates all save/restore
mutations of shared `Schema` state that were introduced as workarounds, including those that
the original plan deferred to Phases 7 and 8.

### Why `PropertyMetaDataCollection` can be dropped completely

`PropertyMetaDataCollection` carried two pieces of data:

1. **`required` array** — always available at
   `$schema->getJsonSchema()->getJson()['required']` on the parent `Schema`. For synthetic
   properties (composition elements, additional/pattern/tuple properties), the required state
   is a fixed call-site decision — not schema data — so it belongs as a direct `bool $required`
   parameter rather than being injected via a mutable collection.

2. **`dependencies` map** — available at
   `$schema->getJsonSchema()->getJson()['dependencies']` on the parent `Schema`. Dependency
   validation is only semantically correct at the point where the parent schema's JSON is
   available alongside the named property being added — i.e. in
   `BaseProcessor::addPropertiesToSchema`. Moving the `addDependencyValidator` call there,
   reading directly from `$json['dependencies'][$propertyName]`, eliminates any need to
   thread dependency data through the pipeline.

### 2.1 — Add `bool $required = false` to `PropertyFactory::create` and processor constructors

- `PropertyFactory::create` gains `bool $required = false` parameter.
- `ProcessorFactoryInterface::getProcessor` gains `bool $required = false` parameter.
- `PropertyProcessorFactory::getProcessor` and `getSingleTypePropertyProcessor` gain
  `bool $required = false` and pass it to the processor constructor.
- `AbstractPropertyProcessor::__construct` gains `protected bool $required = false`.
- `ComposedValueProcessorFactory::getProcessor` gains `bool $required = false` and passes
  it through.

### 2.2 — Replace `isAttributeRequired` lookups

Everywhere `isAttributeRequired($propertyName)` was called:

- **`AbstractValueProcessor::process`** — replace with `$this->required` (constructor value).
- **`ConstProcessor::process`** — same.
- **`ReferenceProcessor::process`** — use `$this->required` when calling
  `$definition->resolveReference($propertyName, $path, $this->required)`.

For the **normal named-property path** (`BaseProcessor::addPropertiesToSchema`), compute
`$required = in_array($propertyName, $json['required'] ?? [], true)` at the call site and
pass it to `PropertyFactory::create`.

### 2.3 — Move `addDependencyValidator` to `BaseProcessor::addPropertiesToSchema`

- Remove the `getAttributeDependencies` call and `addDependencyValidator` invocation from
  `AbstractPropertyProcessor::generateValidators` entirely.
- In `BaseProcessor::addPropertiesToSchema`, after calling `$propertyFactory->create(...)`,
  check `$json['dependencies'][$propertyName] ?? null` and call
  `$this->addDependencyValidator($property, $deps)` directly at that level.
- `addDependencyValidator` itself moves to `BaseProcessor` (from `AbstractPropertyProcessor`).

### 2.4 — Update `SchemaDefinition::resolveReference`

- Change signature: replace `PropertyMetaDataCollection $propertyMetaDataCollection` with
  `bool $required` and `?array $dependencies = null`.
- Remove all save/restore of `$schema->getPropertyMetaData()`.
- Apply `$property->setRequired($required)` on the returned property.
- New cache key: `implode('-', [...$originalPath, $required ? '1' : '0', md5(json_encode($dependencies))])`.

**Why the dependencies must be in the cache key (not the property name):**

The original PMC hash encoded `[dependencies, required]` for the specific property — not the
property name itself. This meant two properties with different names but the same required/dependency
state (e.g. `property1` and `item of array property1` both optional with no dependencies) produced
the same cache key, which is essential for breaking recursive `$ref` cycles: the second call
(with the different property name) hits the sentinel entry for the first call and returns a proxy.

Including `$propertyName` in the key would break recursion: each level of recursion uses a
different name (e.g. `property` → `item of array property` → `item of array item of array
property` …), so no sentinel is ever hit and the resolution loops infinitely.

However, dependencies MUST be in the key: two properties with the same `required` state but
different dependency arrays (e.g. `property1` depends on `property3: string` while `property2`
depends on `property3: integer`) would otherwise share a cached entry and add their dependency
validators to the same underlying property object.

**How dependencies reach `resolveReference`:**

`BaseProcessor` injects `'_dependencies'` into the property's `JsonSchema` before calling
`PropertyFactory::create`. `ReferenceProcessor` reads `$propertySchema->getJson()['_dependencies']`
and passes it to `resolveReference`. All other call sites pass `null` (no dependencies).

### 2.5 — Remove all PMC save/restore sites

All five save/restore blocks introduced in Phase 2 (and noted as Phase 7/8 debt in the
original plan) are removed in this phase:

- **`AdditionalPropertiesValidator`** — remove save/restore; pass `$required = true` to
  `PropertyFactory::create` (synthetic `'additional property'` is always treated as required).
- **`PatternPropertiesValidator`** — same, always `$required = true`.
- **`ArrayTupleValidator`** — same, each tuple item is always `$required = true`.
- **`AbstractComposedValueProcessor::getCompositionProperties`** — remove save/restore; pass
  `$property->isRequired()` as `bool $required` to `PropertyFactory::create`. Because
  `NotProcessor` calls `$property->setRequired(true)` before `parent::generateValidators`,
  this value is correctly `true` when processing `not` composition elements.
- **`IfProcessor`** — same, pass `$property->isRequired()`.

Also: `AbstractPropertyProcessor::addComposedValueValidator` must pass `$property->isRequired()`
as `$required` to `PropertyFactory::create`. Without it, the composed value property for a
required property is constructed with `required=false`, causing `isImplicitNullAllowed` to return
`true` inside `AnyOfProcessor`/`OneOfProcessor`, which then incorrectly adds `null` to the type
hint and allows null input for required properties.

### 2.6 — Delete `PropertyMetaDataCollection` and clean up `Schema`

- Remove `getPropertyMetaData()` and `setPropertyMetaData()` from `Schema`.
- Remove the `$propertyMetaData` field and its initialisation from `Schema::__construct`.
- Delete `src/PropertyProcessor/PropertyMetaDataCollection.php`.

### Tests for Phase 2

- `PropertyProcessorFactoryTest` — remove `PropertyMetaDataCollection` from all
  `getProcessor` calls (already done in the initial Phase 2 commit); verify the test still
  passes with the `bool $required` parameter added.
- The entire existing integration test suite is the regression guard. Key scenarios:
  - **Issue 86 `ref.json`** — same `$ref` resolved as required vs optional; verifies the
    simplified cache key (`$required` bool) correctly separates the two entries.
  - **Issue 86 `schemaDependency.json`** — `$ref` properties with schema dependencies;
    verifies dependency validators are correctly attached by `BaseProcessor` after
    `resolveReference` returns.
  - **`ComposedNotTest::ReferencedObjectSchema`** — `not` composition with a `$ref` to a
    local definition; verifies that `NotProcessor`'s forced `required=true` reaches
    `resolveReference` via the constructor parameter.
  - **`PropertyDependencyTest`, `SchemaDependencyTest`** — all dependency validator paths.
- No new test schemas or test methods are needed; the existing tests provide complete coverage.

### Docs for Phase 2

- Update any doc page that shows `PropertyFactory::create` with its parameter list.

### Bridge-period debt resolved

The original plan deferred the following PMC-mutation workarounds to Phases 7 and 8:

- `AbstractComposedValueProcessor::getCompositionProperties` save/restore — **resolved here**.
- `AdditionalPropertiesValidator`, `PatternPropertiesValidator`, `ArrayTupleValidator`
  save/restore — **resolved here**.
- `SchemaDefinition::resolveReference` PMC parameter — **resolved here**.

Phase 7 and Phase 8 no longer need to address any PMC-related cleanup.

---

## Phase 3 — `PropertyFactory` constructs `Property`; first modifiers land **[DONE]**

**Goal**: Move `Property` object construction from `AbstractValueProcessor::process` into
`PropertyFactory::create`. Wire `PropertyFactory` to ask the Draft for modifiers. Introduce the
first two concrete modifier classes (`TypeCheckModifier`, `DefaultValueModifier`) and fill them
into `Draft07`. Keep all existing processor classes intact — they run _after_ the modifier
pipeline as a temporary bridge, deduplicated by the property state they observe.

**This is the most delicate phase** because `PropertyFactory::create` currently just routes to
a processor. After this phase it both constructs `Property` and calls the processor (which
still constructs a second `Property` internally). The temporary bridge strategy:

- `PropertyFactory::create` constructs the `Property`, sets required/readOnly, runs Draft
  modifiers, then calls the legacy processor's `process()`.
- Legacy processors that call `parent::process()` (i.e. `AbstractValueProcessor`) will
  construct a _second_ `Property` internally. To avoid duplicate validators, the legacy
  processors are updated in Phase 4 to skip construction and receive the existing property.
- For Phase 3 only, the `AbstractTypedValueProcessor`-level TypeCheckValidator is skipped
  if `TypeCheckModifier` already added it — detected by checking whether the property already
  carries a `TypeCheckValidator` for that type.

### 3.1 — Modifier: `TypeCheckModifier`

New file `src/Draft/Modifier/TypeCheckModifier.php`:
```php
class TypeCheckModifier implements ModifierInterface {
    public function __construct(private readonly string $type) {}
    public function modify(...): void {
        // Add TypeCheckValidator only if not already present
        $property->addValidator(
            new TypeCheckValidator($this->type, $property, $schemaProcessor->..isImplicitNullAllowed..($property)),
            2,
        );
    }
}
```

Register in `Draft07`: each type gets `new TypeCheckModifier($type)` as its first modifier.
`object` type does NOT get a `TypeCheckModifier` — objects are identified by instantiation, not
a raw type check. `null` gets `new TypeCheckModifier('null')`.

### 3.2 — Modifier: `DefaultValueModifier`

New file `src/Draft/Modifier/DefaultValueModifier.php` — reads `$json['default']`, validates
it against the type, calls `$property->setDefaultValue(...)`.

Register in `Draft07` after `TypeCheckModifier` for all scalar types.

### 3.3 — `PropertyFactory::create` pipeline

```php
public function create(
    SchemaProcessor $schemaProcessor,
    Schema $schema,
    string $propertyName,
    JsonSchema $propertySchema,
): PropertyInterface {
    $json = $propertySchema->getJson();

    // Resolve draft from schema's $schema keyword
    $draft = $schemaProcessor->getGeneratorConfiguration()->getDraft();
    if ($draft instanceof AutoDetectionDraft) {
        $draft = $draft->getDraftForSchema($propertySchema);
    }

    // Construct Property (was: inside AbstractValueProcessor)
    $property = new Property($propertyName, null, $propertySchema, $json['description'] ?? '');
    $property
        ->setRequired($schema->getPropertyMetaData()->isAttributeRequired($propertyName))
        ->setReadOnly(
            (isset($json['readOnly']) && $json['readOnly'] === true)
            || $schemaProcessor->getGeneratorConfiguration()->isImmutable()
        );

    // Resolve types and run type-specific modifiers
    $types = $this->resolveTypes($json);
    foreach ($types as $type) {
        foreach ($draft->getModifiersForType($type) as $modifier) {
            $modifier->modify($schemaProcessor, $schema, $property, $propertySchema);
        }
    }

    // Universal modifiers
    foreach ($draft->getUniversalModifiers() as $modifier) {
        $modifier->modify($schemaProcessor, $schema, $property, $propertySchema);
    }

    // Legacy bridge: route to existing processor (temporary, removed in later phases)
    $property = $this->legacyProcess($json, $propertyMetaData, $schemaProcessor, $schema, $propertyName, $propertySchema, $property);

    return $property;
}
```

`resolveTypes`: returns `['string']` for `"type":"string"`, `['string','null']` for
`"type":["string","null"]`, `[]` for no type (untyped / `any`).

The `legacyProcess` bridge calls the old `ProcessorFactoryInterface` path. It is removed
phase-by-phase from Phase 4 onward.

### 3.4 — Prevent duplicate TypeCheckValidator in bridge period

`AbstractTypedValueProcessor::generateValidators` currently always adds `TypeCheckValidator`.
Add a guard: check if the property already has a `TypeCheckValidator` for `static::TYPE`
before adding another. This is the minimal change needed to bridge Phase 3 without duplicates.

### Tests for Phase 3

- New unit tests for `TypeCheckModifier` and `DefaultValueModifier` in `tests/Draft/Modifier/`.
- `tests/Objects/MultiTypePropertyTest.php` — must stay green (bridge still handles multi-type
  via `MultiTypeProcessor`).
- Full integration suite must stay green.

### Implementation notes (Phase 3)

Implemented using Option B (legacy processor first, then modifiers on returned property).

Key fixes required during implementation:
- `AbstractComposedValueProcessor::getCompositionProperties`: temporarily overrides the schema
  PMC to reflect the parent property's `isRequired` state for each composition element. This is
  essential for `NotProcessor` (which sets `isRequired=true` on the composition property to enforce
  strict null checks), so that sub-properties (including referenced schemas) see the correct state.
- `AdditionalPropertiesValidator`, `PatternPropertiesValidator`, `ArrayTupleValidator`: similarly
  override the schema PMC to mark validation sub-properties as required, restoring the behaviour
  that Phase 1 provided via explicit PMC construction.

---

## Phase 4 — Migrate scalar type keyword validators to validator factories **[DONE — commit 3bec251]**

**Goal**: For each scalar type (`string`, `integer`, `number`, `boolean`, `null`, `array`),
move all keyword-specific validator generation from the processor `generateValidators` method
into dedicated classes. Register them in `Draft07`. Once all keywords for a type are migrated,
that processor's `generateValidators` override is deleted.

Ordering within a type's modifier list mirrors the current `generateValidators` call order.

### Class hierarchy for keyword-driven validators

Most JSON Schema keywords follow a simple pattern: check whether the keyword is present in the
property's JSON, validate its value, then add a validator to the property. This phase introduces
a reusable class hierarchy for this pattern:

- **`AbstractValidatorFactory`** (`src/Model/Validator/Factory/AbstractValidatorFactory.php`) —
  implements `ModifierInterface` (already defined in Phase 1). Holds `protected $key` injected
  via `setKey(string $key): void`. The `$key` is the JSON Schema keyword name (e.g. `'minLength'`),
  set by `Type::addValidator` at registration time (see below).
- **`SimplePropertyValidatorFactory`** — extends `AbstractValidatorFactory`. Provides `modify()`:
  reads `$property->getJsonSchema()->getJson()[$this->key]`, calls `hasValidValue()` (which also
  throws `SchemaException` for invalid values), then calls abstract `getValidator()` and adds the
  validator to the property. Subclasses only implement `isValueValid($value): bool` and
  `getValidator(PropertyInterface, $value): PropertyValidatorInterface`.
- **`SimpleBaseValidatorFactory`** — same pattern but calls `$schema->addBaseValidator()` instead
  of `$property->addValidator()`, for root-schema validators.

**How the JSON keyword is bound to the factory**: `Type::addValidator(string $validatorKey,
AbstractValidatorFactory $factory)` is a new method added to `Type` in this phase. It calls
`$factory->setKey($validatorKey)` before appending the factory to the modifier list. This means
factories do **not** hard-code their keyword — they receive it from the registration call. The
same factory class can be reused for different keywords if the logic is identical.

`TypeCheckModifier` also adds a validator (a `TypeCheckValidator`), but it is not keyed to a
single JSON keyword — the type comes from the `Type` registration itself. It could equally be
named `TypeCheckValidatorFactory`, but because it implements `ModifierInterface` directly without
the `$key`/`setKey` mechanism, the `*Modifier` suffix is used to signal that it is not part of the
`AbstractValidatorFactory` hierarchy. The same applies to `DefaultValueModifier` (which modifies a
property attribute rather than adding a validator). The naming rule is therefore: `*ValidatorFactory`
for classes that extend `AbstractValidatorFactory` (keyed via `setKey`); `*Modifier` for classes
that implement `ModifierInterface` directly (non-keyed).

### Classes to create

**String** (namespace `src/Model/Validator/Factory/String/`):
- `PatternPropertyValidatorFactory` — extends `SimplePropertyValidatorFactory`; from `StringProcessor::addPatternValidator`
- `MinLengthPropertyValidatorFactory` — extends `SimplePropertyValidatorFactory`; from `StringProcessor::addLengthValidator` (min part)
- `MaxLengthValidatorFactory` — extends `MinLengthPropertyValidatorFactory`; from `StringProcessor::addLengthValidator` (max part)
- `FormatValidatorFactory` — extends `AbstractValidatorFactory`; from `StringProcessor::addFormatValidator`

**Integer / Number** (namespace `src/Model/Validator/Factory/Number/`):
- `MinimumValidatorFactory` — extends `SimplePropertyValidatorFactory`; from `AbstractNumericProcessor::addRangeValidator` (minimum)
- `MaximumValidatorFactory`
- `ExclusiveMinimumValidatorFactory`
- `ExclusiveMaximumValidatorFactory`
- `MultipleOfPropertyValidatorFactory` — extends `SimplePropertyValidatorFactory`

**Array** (namespace `src/Model/Validator/Factory/Arrays/`):
- `MinItemsValidatorFactory`, `MaxItemsValidatorFactory`, `UniqueItemsValidatorFactory` — extend `SimplePropertyValidatorFactory`
- `ItemsValidatorFactory` — extends `AbstractValidatorFactory`; handles `items`, `additionalItems`, tuples
- `ContainsValidatorFactory` — extends `SimplePropertyValidatorFactory`

**Object** (namespace `src/Model/Validator/Factory/Object/`):
- `PropertiesValidatorFactory` — extends `AbstractValidatorFactory`; from `BaseProcessor::addPropertiesToSchema`
- `PropertyNamesValidatorFactory` — extends `AbstractValidatorFactory`
- `PatternPropertiesValidatorFactory` — extends `AbstractValidatorFactory`
- `AdditionalPropertiesValidatorFactory` — extends `AbstractValidatorFactory`
- `MinPropertiesValidatorFactory`, `MaxPropertiesValidatorFactory` — extend `SimplePropertyValidatorFactory`

**Universal** (namespace `src/Model/Validator/Factory/Any/`):
- `EnumValidatorFactory` — extends `AbstractValidatorFactory`; from `AbstractPropertyProcessor::addEnumValidator`
- `FilterValidatorFactory` — extends `AbstractValidatorFactory`; from `AbstractValueProcessor` filter call

**Deferred** (not part of Phase 4):
- `RequiredValidatorFactory` — deferred to Phase 6/7; `RequiredPropertyValidator` is added inside
  `AbstractPropertyProcessor::generateValidators` which is tightly coupled to the full processor
  hierarchy, and required-state handling interacts with composition.
- `DependencyValidatorFactory` — deferred to Phase 6; dependency validation lives in
  `BaseProcessor::addPropertiesToSchema` and requires `$this->schemaProcessor` + `$this->schema`
  for schema-dependency processing — it belongs with the object keyword migration.
- `ReferenceValidatorFactory` — deferred to Phase 7 (`$ref` handling is tightly coupled to composition routing)
- `AllOfValidatorFactory` / other composition factories — deferred to Phase 7 (composition is the most complex migration)

**Note on naming**: the `*ValidatorFactory` suffix is used for all classes that follow the
`AbstractValidatorFactory` hierarchy. The `*Modifier` suffix (e.g. `TypeCheckModifier`,
`DefaultValueModifier`) is reserved for objects that implement `ModifierInterface` directly and
perform property modification beyond a simple key→validator mapping.

### Registration in `Draft_07`

`Draft_07` registers factories on `Type` objects via `addValidator(string $key, AbstractValidatorFactory)`.
Example:

```php
(new Type('string'))
    ->addValidator('pattern', new PatternPropertyValidatorFactory())
    ->addValidator('minLength', new MinLengthPropertyValidatorFactory())
    ->addValidator('maxLength', new MaxLengthValidatorFactory())
    ->addValidator('format', new FormatValidatorFactory()),
```

The `Type::addValidator` method calls `$factory->setKey($key)`, injecting the JSON keyword name
into the factory before it is stored. This is the only place the keyword name is bound — factory
classes never hard-code it.

### Migration strategy per type

For each type, the work is:
1. Create the `AbstractValidatorFactory` subclass(es). The `$key` property is set by the
   `Type` registry at registration time, not in the constructor.
2. Register in `Draft_07` via `Type::addValidator`.
3. Add deduplication guard in the legacy processor (same pattern as Phase 3: skip if
   validator already present).
4. Delete the `generateValidators` override in the processor once all keywords are covered.
5. If the processor class becomes empty (only inherits from `AbstractTypedValueProcessor`),
   mark it `@deprecated` — deletion happens in Phase 8.

### Tests for Phase 4

- New unit tests for each validator factory class.
- All existing integration tests (`StringPropertyTest`, `IntegerPropertyTest`,
  `NumberPropertyTest`, `ArrayPropertyTest`, etc.) must stay green after each sub-step.
- Run the full suite after each type is completed, not just at the end of the phase.

### Docs for Phase 4

No user-visible behaviour change. No doc updates needed this phase.

---

## Phase 5 — Eliminate `MultiTypeProcessor`

**Goal**: `"type": ["string","null"]` is handled by `PropertyFactory` directly — it iterates
the type list, processes each type through the legacy per-type processor to collect validators
and decorators, merges them onto a single `Property`, consolidates `TypeCheckValidator`
instances into a `MultiTypeCheckValidator`, and runs universal modifiers once.
`MultiTypeProcessor` is deleted.

### Why this approach

After Phase 4, every keyword validator factory reads from the property's `JsonSchema` directly.
Each factory is keyed to a distinct JSON keyword (`minLength`, `minimum`, `minItems`, …), so
running multiple types' modifier lists against the same schema produces no duplicates — each
keyword fires at most once, for whichever type owns it. The `$checks` deduplication array inside
`MultiTypeProcessor` guarded against a scenario that no longer occurs.

The sub-property architecture existed only to isolate per-type keyword validators from each
other. With the modifier system that isolation is implicit. We can therefore process each type
directly and merge the results, which eliminates `onResolve` deferral, `processSubProperties`,
`transferValidators`, and the `$checks` accumulator.

### 5.1 — Inline multi-type handling in `PropertyFactory::create`

When `$resolvedType` is an array, `PropertyFactory::create` takes the new path instead of
delegating to `MultiTypeProcessor`:

1. **Construct the main property** directly (same as `AbstractValueProcessor::process` does):
   `new Property($propertyName, null, $propertySchema, $json['description'] ?? '')` with
   `setRequired`/`setReadOnly` applied.

2. **Iterate types**: for each type in the array, call the legacy processor's `process()` to
   obtain a sub-property (same flow as before, reusing `getSingleTypePropertyProcessor`), then:
   - Collect all `TypeCheckInterface` validators from the sub-property into a `$collectedTypes`
     string array (via `getTypes()`).
   - Transfer all non-`TypeCheckInterface` validators onto the main property (preserving priority).
   - If the sub-property has decorators, attach a `PropertyTransferDecorator` to the main
     property (covers the `object` sub-type case where `ObjectInstantiationDecorator` lives
     on the sub-property).
   - Collect the sub-property's type hint via `getTypeHint()` into `$typeHints`.

3. **Consolidate type check**: after the loop, if `$collectedTypes` is non-empty:
   - Add one `MultiTypeCheckValidator(array_unique($collectedTypes), $property, isImplicitNullAllowed)`.
   - Set the union `PropertyType`: separate `$collectedTypes` into non-null types and a
     `$hasNull` flag; call `$property->setType(new PropertyType($nonNullTypes, $hasNull ?: null), ...)`.
   - Add a `TypeHintDecorator` built from the `$typeHints` collected per sub-property.

4. **Run universal modifiers** once on the main property (handles `default`, `enum`, `filter`).
   `DefaultValueModifier` already handles the multi-type case — it iterates `$json['type']`
   (which is the full array) and accepts the default if any type matches.

`isImplicitNullAllowed` is determined the same way as in `AbstractPropertyProcessor`:
`$schemaProcessor->getGeneratorConfiguration()->isImplicitNullAllowed() && !$property->isRequired()`.

### 5.2 — Make `applyTypeModifiers` and `applyUniversalModifiers` private

These methods were `public` only because `MultiTypeProcessor` called them from outside
`PropertyFactory`. After this phase they have no external callers; make them `private`.

### 5.3 — Delete `MultiTypeProcessor` and array-type branch

Delete:
- `src/PropertyProcessor/Property/MultiTypeProcessor.php`
- The `is_array($type)` branch in `PropertyProcessorFactory::getProcessor`

### Tests for Phase 5 **[DONE]**

- `tests/Objects/MultiTypePropertyTest.php` — all existing cases pass.
- Three `invalidRecursiveMultiTypeDataProvider` expectations updated: the outer `InvalidItemException`
  property name changes from `"item of array property"` to `"property"`. This is a cosmetic difference
  in error message wording caused by the change in which `ArrayItemValidator` instance registers the
  extracted method first. Both messages are semantically valid. The inner error messages are unchanged.
- Full suite green (2254 tests, 1 pre-existing deprecation).

---

## Phase 6 — `ObjectProcessor` as modifier; `object` modifier list complete

**Goal**: The `'object'` type modifier list in `Draft07` now includes an `ObjectModifier` that
handles nested-object instantiation (what `ObjectProcessor::process` does today) in addition to
the keyword modifiers from Phase 4. `ObjectProcessor` is then deprecated and the legacy bridge
for `type=object` is removed.

### 6.1 — `ObjectModifier`

New file `src/Draft/Modifier/ObjectType/ObjectModifier.php` — extracts the nested schema
processing logic from `ObjectProcessor::process`:
- Calls `$schemaProcessor->processSchema(...)` to generate the nested class
- Adds `ObjectInstantiationDecorator`, `InstanceOfValidator`, sets `PropertyType` to class name
- Handles namespace transfer

### 6.2 — Remove legacy bridge for `type=object`

Once `ObjectModifier` is registered in `Draft07`'s `object` modifier list and the Phase 4
object keyword modifiers are also there, `ObjectProcessor` can be deprecated.

### 6.3 — `BaseProcessor` pipeline step

`BaseProcessor` handles the root-level object (`type=base`). Its pipeline steps are:
1. `setUpDefinitionDictionary` — stays in `SchemaProcessor` (internal mechanic)
2. All `object` keyword modifiers (now in `Draft07`) — called by `PropertyFactory`
3. `transferComposedPropertiesToSchema` — stays as explicit post-step in `SchemaProcessor`

`SchemaProcessor::generateModel` calls `PropertyFactory::create` with `type=base`, which the
factory translates to constructing a `BaseProperty` and running the `object` modifier list.
`type=base` is no longer a type string in the Draft — `PropertyFactory` detects it as the
special root-schema signal:

```php
if ($json['type'] === 'base') {
    $property = new BaseProperty($propertyName, new PropertyType('object'), $propertySchema);
    $types = ['object'];
} else {
    $property = new Property(...);
    $types = $this->resolveTypes($json);
}
```

This is the minimal special-casing agreed in Q2.1 (Option A from the analysis).

### Tests for Phase 6

- All object-property and nested-object integration tests must stay green.
- `ObjectPropertyTest`, `IdenticalNestedSchemaTest`, `ReferencePropertyTest`.

---

## Phase 7 — `CompositionModifier`; eliminate `ComposedValueProcessorFactory`

**Goal**: Replace `AbstractComposedValueProcessor` and `ComposedValueProcessorFactory` with a
single `CompositionModifier` universal modifier. This is the highest-risk phase.

### Bridge-period debt status

All PMC-mutation workarounds were **fully resolved in Phase 2**:

- `AbstractComposedValueProcessor::getCompositionProperties` save/restore → replaced by
  passing `$property->isRequired()` as `bool $required` to `PropertyFactory::create`.
- `AdditionalPropertiesValidator`, `PatternPropertiesValidator`, `ArrayTupleValidator`
  save/restore → replaced by passing `$required = true` to `PropertyFactory::create`.
- `SchemaDefinition::resolveReference` PMC parameter → replaced by `bool $required`.
- `PropertyMetaDataCollection` class deleted entirely.

`NotProcessor::setRequired(true)` remains as the mechanism to force strict null-checks in
`not` composition elements. This is semantically correct: the property IS required from the
`not` branch's perspective (it must be non-null to be checked against the negated schema).
It propagates correctly via `$property->isRequired()` in `getCompositionProperties`. No
change is needed here in Phase 7.

### 7.1 — `CompositionModifier`

New file `src/Draft/Modifier/CompositionModifier.php`. Extracts the logic from
`AbstractPropertyProcessor::addComposedValueValidator`:
- Iterates `['allOf','anyOf','oneOf','not','if']` keywords
- Uses `$property instanceof BaseProperty` to determine root-level (replacing `rootLevelComposition`)
- Creates `CompositionPropertyDecorator` instances, sets up `onResolve` callbacks,
  emits `ComposedPropertyValidator`
- Calls `SchemaProcessor::createMergedProperty` for non-root non-allOf compositions
- Handles `not`-keyword strict-null enforcement **without** mutating `isRequired` on the
  composition property — instead passes an explicit `allowImplicitNull=false` signal through the
  sub-property creation API (see bridge-period debt above).

The `AbstractComposedValueProcessor` subclasses (`AllOfProcessor`, `AnyOfProcessor`,
`OneOfProcessor`, `NotProcessor`, `IfProcessor`) are replaced by the logic inside
`CompositionModifier` (using `match($keyword)` or strategy objects for the
`getComposedValueValidation` difference between allOf/anyOf/oneOf/not/if).

### 7.2 — `ComposedValueProcessorFactory` deletion

Once `CompositionModifier` is in the universal modifier list of `Draft07` and handles both
root-level and property-level composition, `ComposedValueProcessorFactory` and the
composition processor classes are deleted.

### 7.3 — `ConstProcessor` and `ReferenceProcessor`

These are special non-Draft-driven processors (they are not JSON Schema types but routing
signals injected by `PropertyFactory`). They remain as-is, invoked by the legacy bridge for
`type=const` and `type=reference`/`type=baseReference` special cases. These are retained as
permanent special-case routes inside `PropertyFactory` — they represent keyword-level routing
(the `$ref` and `const` keywords), not type-level routing, so they do not belong in the Draft.

### Tests for Phase 7

- All `ComposedValue/` test classes must stay green.
- `ComposedAnyOfTest`, `ComposedAllOfTest`, `ComposedOneOfTest`, `ComposedNotTest`,
  `ComposedIfTest`, `CrossTypedCompositionTest`, `ComposedRequiredPromotionTest`.
- All issue regression tests that involve composition:
  `Issue98Test`, `Issue101Test`, `Issue105Test`, `Issue113Test`, `Issue114Test`,
  `Issue116Test`, `Issue117Test`.
- Verify that the PMC-mutation workarounds from Phase 3 are **gone** — confirmed by the absence
  of `setPropertyMetaData` calls outside of `SchemaDefinition::resolveReference` and
  `Schema`'s own initialisation code.

---

## Phase 8 — Delete empty processor classes and legacy bridge

**Goal**: Remove all now-empty or deprecated processor classes, the legacy bridge in
`PropertyFactory`, `PropertyProcessorFactory`, `ComposedValueProcessorFactory`, and
`AbstractPropertyProcessor`/`AbstractValueProcessor`/`AbstractTypedValueProcessor`.

### Remaining bridge-period debt to clean up

At the time Phase 8 runs, the following bridge artifact must be removed:

- The dedup guard in `AbstractTypedValueProcessor::generateValidators` (checking for existing
  `TypeCheckInterface` validators) is only needed during the bridge period; it is deleted when
  `AbstractTypedValueProcessor` itself is deleted.

All PMC-mutation workarounds were already resolved in Phase 2.

### Classes to delete

- `src/PropertyProcessor/Property/AbstractPropertyProcessor.php`
- `src/PropertyProcessor/Property/AbstractValueProcessor.php`
- `src/PropertyProcessor/Property/AbstractTypedValueProcessor.php`
- `src/PropertyProcessor/Property/AbstractNumericProcessor.php`
- `src/PropertyProcessor/Property/StringProcessor.php`
- `src/PropertyProcessor/Property/IntegerProcessor.php`
- `src/PropertyProcessor/Property/NumberProcessor.php`
- `src/PropertyProcessor/Property/BooleanProcessor.php`
- `src/PropertyProcessor/Property/NullProcessor.php`
- `src/PropertyProcessor/Property/ArrayProcessor.php`
- `src/PropertyProcessor/Property/ObjectProcessor.php`
- `src/PropertyProcessor/Property/AnyProcessor.php`
- `src/PropertyProcessor/Property/MultiTypeProcessor.php` (deleted in Phase 5)
- `src/PropertyProcessor/ComposedValue/AbstractComposedValueProcessor.php`
- `src/PropertyProcessor/ComposedValue/AllOfProcessor.php`
- `src/PropertyProcessor/ComposedValue/AnyOfProcessor.php`
- `src/PropertyProcessor/ComposedValue/OneOfProcessor.php`
- `src/PropertyProcessor/ComposedValue/NotProcessor.php`
- `src/PropertyProcessor/ComposedValue/IfProcessor.php`
- `src/PropertyProcessor/ComposedValueProcessorFactory.php`
- `src/PropertyProcessor/PropertyProcessorFactory.php`
- `src/PropertyProcessor/ProcessorFactoryInterface.php`
- `src/PropertyProcessor/PropertyProcessorInterface.php`

### Tests to delete/replace

- `tests/PropertyProcessor/PropertyProcessorFactoryTest.php` — tests the deleted factory.
  Replace with `tests/Draft/DraftRegistryTest.php` testing that `Draft07` returns the correct
  modifier list for each type.

### Remaining processors (kept permanently)

- `src/PropertyProcessor/Property/ConstProcessor.php` — becomes a standalone callable, no
  longer part of the processor hierarchy; or folds into `PropertyFactory` directly
- `src/PropertyProcessor/Property/ReferenceProcessor.php` — same
- `src/PropertyProcessor/Property/BasereferenceProcessor.php` — same

### Docs for Phase 8

- Remove all references to `PropertyProcessorFactory` from docs.
- Update architecture overview in docs and `CLAUDE.md`.
- Document the final `DraftInterface` / modifier system for users.

---

## Cross-cutting: `AutoDetectionDraft` completion

This runs in parallel with Phases 3–8 as each draft keyword is modelled.

### Detection logic

Inspect `$schema` keyword:
- `http://json-schema.org/draft-07/schema#` → `Draft07`
- `http://json-schema.org/draft-04/schema#` → `Draft04` (once implemented)
- `https://json-schema.org/draft/2020-12/schema` → `Draft202012` (once implemented)
- absent / unrecognised → `Draft07` (safe default, matches current behaviour)

The detection runs per-`JsonSchema` (per file/component), not per generator run, so schemas
with different `$schema` declarations in the same generation run can use different drafts.

### Where detection is called

`PropertyFactory::create` already receives `$propertySchema: JsonSchema`. It calls
`$config->getDraft()` and, if the result is `AutoDetectionDraft`, calls
`getDraftForSchema($propertySchema)` to get the concrete draft. This is the single
detection point — no other caller needs to know about drafts.

---

## Test suite impact summary

| Phase | Tests added | Tests changed | Tests deleted |
|---|---|---|---|
| 1 | `tests/Draft/DraftTest.php` | `GeneratorConfigurationTest` (new `setDraft` test) | — |
| 2 | — | `PropertyProcessorFactoryTest` (remove `PropertyMetaDataCollection` arg) | — |
| 3 | `tests/Draft/Modifier/TypeCheckModifierTest.php`, `DefaultValueModifierTest.php` | Full suite regression | — |
| 4 | One unit test per new modifier | All type-specific integration tests (regression) | — |
| 5 | New multi-type edge cases in `MultiTypePropertyTest` | `MultiTypePropertyTest` | — |
| 6 | — | `ObjectPropertyTest`, `IdenticalNestedSchemaTest` | — |
| 7 | — | All `ComposedValue/*Test`, all composition-related issue tests | — |
| 8 | `tests/Draft/DraftRegistryTest.php` | — | `PropertyProcessorFactoryTest` |

---

## Completion criteria

Each phase is complete when:
1. All new/changed code passes `./vendor/bin/phpcs --standard=phpcs.xml`
2. `./vendor/bin/phpunit` is fully green
3. The plan file is updated with "DONE" on that phase
4. The phase is committed as a standalone PR

The entire rework is complete when Phase 8 is merged and this tracking directory is deleted
before the merge to `master`.
