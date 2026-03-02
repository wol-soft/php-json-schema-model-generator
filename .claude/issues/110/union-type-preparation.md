# Preparatory work: native PHP union type support

## Problem statement

The current type system has a fundamental split between what it can express in DocBlocks versus what
it can express in native PHP type hints.

**DocBlock path** (`getTypeHint()` → `RenderHelper::getTypeHintAnnotation()`):
Already supports union types through the decorator chain. `getTypeHintAnnotation` joins all
decorator-contributed types with `|` and deduplicates. This already produces correct `@var`,
`@param`, and `@return` annotations for unions like `int|string|null`.

**Native type hint path** (`getType()` → `RenderHelper::getType()`):
Only supports a **single `PropertyType`**. `RenderHelper::getType()` produces `?TypeName` (one type,
optionally nullable). The template uses this for actual PHP syntax: getter return types, setter
parameter types, and the property declaration type hint.

When `Property::getType()` returns `null` (which happens when input type ≠ output type — the existing
filter/transformer case), `RenderHelper::getType()` returns an empty string and the template omits
the type declaration entirely, falling back to an untyped property/method. There is even a developer
TODO comment at `Property.php:69`:

```php
// TODO: PHP 8 use union types to accept multiple input types
```

This was the anticipated fix location, left unimplemented.

---

## Where the gap matters for issue #110

The issue #110 fix (refined Option C) needs to express the type of a property like `age` that is
defined as `integer` in one branch and `string` in another. The honest PHP type for such a property
is `int|string` (plus `|null` if the property is optional). The current architecture can almost do
this — the decorator machinery already builds `int|string` for the DocBlock — but the native type
hint path cannot emit it.

Without this preparatory work, the only option for cross-typed same-name properties is to fall back
to no type hint at all (the current filter/transformer fallback), which is worse than `mixed` because
it produces untyped properties that offer neither safety nor IDE support.

---

## Current architecture in detail

### `PropertyType` — single-type value object

```php
class PropertyType
{
    public function __construct(private string $name, private ?bool $nullable = null) {}
}
```

`$name` holds exactly one type string: `'string'`, `'int'`, `'float'`, `'bool'`, `'array'`,
or a class name. There is no concept of multiple alternative types within a single `PropertyType`.

### `Property::$type` and `Property::$outputType` — dual-type storage

```php
protected $type;        // input/base type
protected $outputType;  // type after filter/transformation (e.g. DateTime after date filter)
```

`getType(false)` returns `null` when `$type` and `$outputType` both exist with different names —
this signals "the property can hold either type" and forces the DocBlock path via decorators. The
native type hint path receives `null` → empty string → no type declaration.

### `getTypeHint()` — decorator-based union for DocBlocks

```php
// In getTypeHint(), when input ≠ output type:
$input = [$this->type, $this->outputType];
// Both are mapped through the decorator chain and joined with '|'
```

The `TypeHintDecorator` already does:
```php
return implode('|', array_unique(array_filter(array_merge(explode('|', $input), $this->types))));
```

This correctly handles unions for DocBlocks. But `getTypeHint()` returns a `string`; nothing feeds
that string into native PHP type declarations in the template.

### Template rendering — the missing link

**Scope note:** The current template (`Model.phptpl` line 32) never emits a native type hint on
property declarations — only a DocBlock `@var`. This is a pre-existing gap; PHP 8.0 supports union
types on property declarations just as it does on parameters and return types. Adding native
property declaration type hints is covered in Phase 9 of the implementation plan. The preparatory
infrastructure described here (Steps 1–4) is equally required for Phase 9.

The declaration type must use the **output type** (`getType(true)`), matching the getter, because
the property stores the value after any filter transformation. For a `string` → `DateTime` filter
property, the declaration is `protected ?DateTime $date;` even though the setter accepts
`string|DateTime`.

```phptpl
{# Property declaration — currently no native type hint; Phase 9 adds one #}
/** @var {{ viewHelper.getTypeHintAnnotation(property, true) }} */
protected ${{ property.getAttribute(true) }};   {# target: {% if property.getType(true) %} {{ viewHelper.getType(property, true) }}{% endif %} $... #}

{# Getter return type #}
{% if property.getType(true) %}: {{ viewHelper.getType(property, true) }}{% endif %}
{# ↑ Only emitted if getType() is non-null. Uses getType(), not getTypeHint(). #}

{# Setter parameter type #}
{{ viewHelper.getType(property) }} ${{ property.getAttribute(true) }}
{# ↑ Same — uses getType(), which can only express a single type. #}
```

`getType()` (on `RenderHelper`) generates `?TypeName` — the `?` prefix handles nullable but cannot
express `int|string`. The template has no path to emit a multi-type native hint from `getTypeHint()`.

---

## What the preparatory work requires

### Step 1 — Extend `PropertyType` to hold multiple alternative types

Replace the single `$name` string with an array of type names, or introduce a new class
`UnionPropertyType` that holds multiple `PropertyType` instances.

**Option A — extend `PropertyType` itself:**
```php
class PropertyType
{
    /** @var string[] */
    private array $names;

    public function __construct(string|array $name, private ?bool $nullable = null)
    {
        $this->names = (array) $name;
    }

    public function getName(): string
    {
        // Backwards-compatible single-type access — returns first type
        return $this->names[0];
    }

    /** @return string[] */
    public function getNames(): array
    {
        return $this->names;
    }

    public function isUnion(): bool
    {
        return count($this->names) > 1;
    }
}
```

All existing callers of `getName()` continue to work. New callers can use `getNames()` or
`isUnion()`.

**Option B — introduce `UnionPropertyType extends PropertyType`:**

A subclass that overrides the storage. Explicit, but adds an inheritance layer.

Option A is simpler and less disruptive; it is preferred.

---

### Step 2 — Update `Property::getType()` to return a meaningful type for unions

Currently returns `null` when input ≠ output type, to suppress the native type hint. With union
support, it should instead return a `PropertyType` that covers both alternatives:

```php
public function getType(bool $outputType = false): ?PropertyType
{
    if (!$outputType && $this->type && $this->outputType
        && $this->outputType->getName() !== $this->type->getName()
    ) {
        // Previously returned null (suppressed type hint)
        // With union support: return a union of both types
        return new PropertyType(
            array_unique(array_merge($this->type->getNames(), $this->outputType->getNames())),
            $this->type->isNullable() ?? $this->outputType->isNullable(),
        );
    }

    return $outputType && $this->outputType !== null ? $this->outputType : $this->type;
}
```

---

### Step 3 — Update `RenderHelper::getType()` to emit union syntax

Currently: `"$nullable{$type->getName()}"` → `"?string"`.

With union support:
```php
public function getType(PropertyInterface $property, bool $outputType = false, bool $forceNullable = false): string
{
    $type = $property->getType($outputType);
    if (!$type) {
        return '';
    }

    $nullable = ($type->isNullable() ?? $this->isPropertyNullable($property, $outputType)) || $forceNullable;
    $names = $type->getNames();

    if ($nullable) {
        // PHP 8.0+: int|string|null  (not ?int|string which is a syntax error)
        $names[] = 'null';
    }

    return implode('|', array_unique($names));
}
```

This produces `int|string|null` instead of `?string`. The `?` prefix syntax is only valid for a
single type; union types require the explicit `|null` form.

**Important:** The `?Type` shorthand must be preserved for single-type nullable properties to
avoid generating `string|null` everywhere instead of `?string`. Only emit the long form for actual
unions (when `count($names) > 1` before adding null).

---

### Step 4 — Update `RenderHelper::getTypeHintAnnotation()` to use `getNames()`

Currently reads `$type->isNullable()` to decide whether to append `|null`. With union support,
the same logic applies but now the type hint string is already built from multiple names via
`getTypeHint()`. This method mostly works already via the decorator path; only the nullability
decision needs to account for union types correctly (which it does — `!strstr($typeHint, 'mixed')`
guard is already there).

---

### Step 5 — Validator type-check generation must handle union types

Many validators generate checks like:
```php
if (!is_string($value) && $value !== null) { throw ... }
```

For a union type `int|string`, this must become:
```php
if (!is_int($value) && !is_string($value) && $value !== null) { throw ... }
```

This affects:
- `src/Templates/Validator/TypeCheckValidator.phptpl` (or wherever type check code is generated)
- Any processor that generates inline type check strings

The fix needs a helper that maps `PropertyType::getNames()` → the multi-clause `is_X` expression.

---

### Step 6 — `Schema::addProperty()` deduplication for cross-typed same-name properties

This is the specific fix needed for issue #110's cross-branch same-name property scenario.
Once `PropertyType` supports multiple names, `addProperty()` can widen the existing property's
type on a collision:

```php
public function addProperty(PropertyInterface $property): self
{
    if (!isset($this->properties[$property->getName()])) {
        $this->properties[$property->getName()] = $property;
        // ... onResolve callback
    } else {
        $existing = $this->properties[$property->getName()];
        $existingType = $existing->getType();
        $incomingType = $property->getType();

        if ($existingType && $incomingType) {
            $mergedNames = array_unique(
                array_merge($existingType->getNames(), $incomingType->getNames())
            );

            if ($mergedNames !== $existingType->getNames()) {
                // Types differ — widen to union
                $existing->setType(
                    new PropertyType($mergedNames, true),  // nullable: the union covers multiple branches
                    new PropertyType($mergedNames, true),
                );
            }
            // else: same type, silent skip is correct
        }
    }
    return $this;
}
```

Type-check validators on the promoted root property are already stripped by `cloneTransferredProperty()`
(only `PropertyTemplateValidator` survives). So no validator removal is needed here. The fix is
purely about widening the declared type. Validation happens in the branch sub-classes.

---

## Scope of impact

| Component | Change required | Risk |
|---|---|---|
| `PropertyType` | Add `$names: string[]`, keep `getName()` for compat | Low — additive only |
| `Property::getType()` | Return union instead of null | Medium — affects all callers that check for null |
| `RenderHelper::getType()` | Emit `int|string|null` form | Medium — changes generated output |
| `RenderHelper::getTypeHintAnnotation()` | Minor adjustment | Low |
| Type-check validator templates | Multi-clause `is_X` for union types | Medium |
| `Schema::addProperty()` | Widen type on duplicate name | Low — isolated change |
| All existing `new PropertyType('string')` callsites | No change needed (string constructor arg still works) | None |

The most impactful changes are Steps 2–3 (the `getType()` / `RenderHelper::getType()` pipeline).
These affect every generated getter, setter, and property declaration. A comprehensive test pass
is required to verify no regressions.

---

## Order of implementation

1. **Step 1** — extend `PropertyType` to accept `string|array` constructor arg and expose `getNames()`
2. **Step 3** — update `RenderHelper::getType()` to use `getNames()` and emit union syntax
3. **Step 2** — update `Property::getType()` to return a union `PropertyType` instead of null
4. **Step 4** — minor update to `getTypeHintAnnotation()`
5. **Step 5** — update type-check validator generation
6. **Step 6** — update `Schema::addProperty()` for the issue #110 cross-branch case

Steps 1–4 are pure infrastructure with no behaviour change (existing properties all have single
types, so `getNames()` returns a one-element array and the output is identical). Step 5 changes
validator generation for multi-type properties (currently none exist, so no tests break). Step 6
is the first consumer that actually creates multi-type `PropertyType` instances.

---

## Relationship to issue #110 fix

This preparatory work enables the correct fix for the `age: int|string` cross-branch case:

- Without this work: `age` must be `mixed` (no type safety) or untyped (no hint at all)
- With this work: `age` gets `getAge(): int|string|null` — type-safe, accurate, and IDE-friendly

The branch sub-classes continue to enforce their individual constraints (`minimum >= 0` for int,
`^[0-9]+$` for string). The root class accurately reflects "this property can be either type".

For the null-setting issue (#110's core symptom), this work is complementary but not strictly
required — the null-setting removal can proceed independently. However, without union type support,
removing null-setting for cross-typed properties would leave an untyped root property, which is
the very scenario this work fixes.
