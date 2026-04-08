# Issue #103 — Serialization uses PHP property names instead of schema names

## Problem

`SerializableTrait::_getValues` (production library,
`src/Traits/SerializableTrait.php:76-109`) walks `get_class_vars($this::class)`
and uses the **PHP variable name** as the output key of `toArray()`,
`toJSON()`, and `jsonSerialize()`. A JSON schema property `product_id`
becomes the PHP attribute `productId`, and `toArray()` returns
`['productId' => …]`. The resulting array cannot be fed back into the same
generated class's constructor, because the constructor expects the original
schema name (`product_id`).

This defeats the purpose of the serialization methods — round-tripping a
model through `new $class($model->toArray())` is broken for any schema
whose property names are not already valid camelCase PHP identifiers.

Reported as [wol-soft/php-json-schema-model-generator#103][issue-103].

[issue-103]: https://github.com/wol-soft/php-json-schema-model-generator/issues/103

## Root cause

The trait has no way to recover the original schema name at serialization
time — it only sees PHP variable names via `get_class_vars`. Historically
there was no reliable mapping back to the schema name on the generated
class itself.

As of commit `2f9538a` ("make JSON_POINTER and SCHEMA_NAME always-enabled
attributes …") the missing mapping now exists. Every schema-derived
property on every generated class carries an always-on
`#[SchemaName('original name')]` PHP attribute, added unconditionally by
`PropertyFactory::buildProperty`
(`src/PropertyProcessor/PropertyFactory.php:240-244`) and guarded by
`PhpAttribute::ALWAYS_ENABLED_ATTRIBUTES`. The trait can read it via
reflection.

## Related latent bug: `_skipNotProvidedPropertiesMap`

While investigating the serialization path I found a second bug in the same
area that must be fixed at the same time.

`SerializationPostProcessor::addSkipNotProvidedPropertiesMap`
(`src/SchemaProcessor/PostProcessor/Internal/SerializationPostProcessor.php:233-240`)
populates the map with **PHP variable names**:

```php
$skipNotProvidedValues = array_map(
    static fn(PropertyInterface $property): string => $property->getAttribute(true),
    ...
);
```

But `SerializableTrait::_getValues` uses that map like this:

```php
array_diff($this->_skipNotProvidedPropertiesMap, array_keys($this->_rawModelDataInput))
```

and `_rawModelDataInput` is keyed by `$property->getName()` — the original
schema name (see `src/Templates/Model.phptpl:159`). The two sides of the
`array_diff` use different naming conventions, so the comparison silently
fails for any property whose schema name differs from its PHP attribute
name. Optional properties that weren't supplied at construction are *not*
dropped from serialization as intended — they leak into the output as
`null` (or their default). This has been broken since the
`skipNotProvidedPropertiesMap` feature was added; nobody has noticed
because the existing test suite uses schema names that are already valid
camelCase PHP (`property`, `name`, `address`, etc.).

The fix is the same correction: store schema names in
`_skipNotProvidedPropertiesMap` so both sides of the `array_diff` agree.
This falls out naturally once the trait's main loop is keyed by schema
name, but it needs its own dedicated test case covering a non-camelCase
optional property.

## Decisions

### Drop backward-compatibility mode entirely

Confirmed by the user. No `setSerializeUsingPhpNames(bool)` flag. The old
behaviour is a bug per #103, not a feature, and the project's stated
posture in `CLAUDE.md` is to avoid backwards-compatibility hacks.

- Schemas whose property names are already valid camelCase PHP identifiers
  produce **identical output** before and after the fix, so most users see
  no diff at all.
- Users who genuinely need the old camelCase keys can implement
  `resolveSerializationHook` on the generated class and rekey the array
  there — no config flag required.
- The change rides on the `attributes` branch which is already a
  meaningful release; bundling the correctness fix into that release is
  the cleanest moment for a one-shot break.

### `$except` takes schema names

Confirmed by the user. `$except` values must be schema names. This is a
backward-compatibility break for any user passing PHP variable names, but
it is consistent with the new output keys: whatever the user sees coming
out of `toArray()` is what they pass in to `$except`. Accepting both names
would introduce quiet ambiguity if a schema legitimately has both
`productId` and `product_id` properties.

### `_propertySchemaNames` static cache

Confirmed by the user. Reflection per call is cheap but not free, and the
property layout of a generated class is static. The trait caches the
php-name → schema-name map per concrete class in a static property:

```php
private static array $_propertySchemaNames = [];
```

keyed by `static::class`. The `_customSerializer` cache already does the
same trick for `serialize{Foo}` method lookup.

### `evaluateAttribute` rewrite (additional cleanup, separate from #103)

While reviewing the trait, three latent issues in `evaluateAttribute` were
found that should be fixed in the same change:

1. **Per-call `method_exists` overhead.** Each nested object pays 3-4
   `method_exists` calls. The dispatch decision is class-intrinsic, so it
   should be cached once per concrete class — same pattern as
   `_customSerializer` and `_propertySchemaNames`. New static
   `$_objectSerializationCapability[$attribute::class]` cache.

2. **Blind `toArray()` dispatch.** The current code calls
   `$attribute->toArray($except)` on **any** object that has a `toArray`
   method, even if that method has a different signature than this
   library's. The fix is to check `$attribute instanceof
   SerializationInterface` (the library's own protocol) first; if true,
   call with the full `($except, $depth, …)` argument list, otherwise
   fall back to a parameter-less `toArray()`.

   `instanceof SerializationInterface` is also faster than
   `in_array(SerializableTrait::class, class_uses($attribute))` (one
   opcode vs an array walk), and inheritance-safe (`class_uses` only sees
   direct trait uses on the immediate class, not inherited ones).
   `SerializationPostProcessor` already adds `SerializationInterface` to
   the `implements` list of every generated class with the trait.

3. **`$depth` not propagated through recursion.** The current code calls
   `$attribute->jsonSerialize($except)` and `$attribute->toArray($except)`
   without passing `$depth`, so each nested object resets its budget back
   to the default 512. This defeats the documented intent of `$depth`.
   The fix passes `$depth` through. For users who today rely on the
   accidental "depth resets per nesting level" behaviour, this is a
   silent change — but it's a correctness fix, not a feature, and the
   docs already describe `$depth` as the maximum nesting level.

4. **`__toString` stays as the depth-exhausted fallback only.** The
   prototype version of the rewrite promoted `__toString` to a general
   fallback for any object lacking `toArray` / `jsonSerialize`. That
   would silently downgrade objects-with-public-properties to a single
   string, which is too invasive. Decision: keep `__toString` as the
   depth-zero fallback only, exactly as today.

## Impact analysis

### Breaking changes for users

- `toArray()` / `toJSON()` / `jsonSerialize()` output keys change from the
  PHP camelCase form to the original schema name, for every property where
  the two differ.
- `$except` argument must now contain schema names.
- Any downstream code that reads serialized output keyed by PHP names must
  be updated. The fix is usually one-line: either update the key string,
  or post-process with a user-defined `resolveSerializationHook`.

### Non-breaking for users whose schemas already use camelCase

For schemas where every property name is already a valid camelCase PHP
identifier (no spaces, dashes, underscores, leading digits, or reserved
words), old and new behaviour produce identical output. This covers the
majority of the existing test suite.

### Custom serializer conventions unchanged

`serialize{Foo}` methods are generated and looked up by **PHP attribute
name**, not schema name. This stays as-is: user-defined overrides follow
PHP naming conventions, and `SerializationPostProcessor`'s method
generator uses `"serialize{$property->getAttribute()}"` (camelCase). The
trait still passes the PHP name to `_getCustomSerializerMethod`.

### Depth budget across nested models — silent correctness change

Today, deeply nested models effectively serialize at unlimited depth
because the recursive call drops `$depth`. After the fix, nested models
share the budget supplied by the outermost call. Any caller that today
serializes a model nested deeper than 512 levels (or whatever they pass
in) without hitting the limit will start hitting it. We treat this as a
bug fix rather than a breaking change because the docs already describe
`$depth` as the maximum nesting level, but it gets a line in the upgrade
notes.

### `patternProperties` and `additionalProperties`

Already keyed by their original JSON keys (they do not go through PHP
variable normalisation). No change required.

## Non-goals

- No rename of existing public methods (`toArray`, `toJSON`,
  `jsonSerialize` keep their names and signatures).
- No changes to `SerializationInterface`.
- No changes to custom serializer method naming (`serialize{Foo}` stays
  camelCase-PHP-named).
- No changes to `patternProperties` / `additionalProperties` serialization
  code paths.
- No new `GeneratorConfiguration` option — the whole point is to remove
  the need for one.
