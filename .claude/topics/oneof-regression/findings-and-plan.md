# oneOf Regression: Findings and Implementation Plan

## Summary

In the v3ready refactoring, the old `ComposedValue/` processor hierarchy (e.g. `OneOfProcessor`, `AnyOfProcessor`,
`ComposedValueProcessorFactory`) was replaced by a Draft-based validator factory system
(`OneOfValidatorFactory`, `AbstractCompositionValidatorFactory`). This changed how oneOf branches without an
explicit `type: "object"` are handled, causing certain schemas to generate fewer class files than expected and
introducing two specific bugs (Issues #133 and #137).

## The Core Issue

When a JSON Schema `oneOf` has branches that use `properties`/`required`/`additionalProperties` keywords
(which imply the value must be an object) but lack an explicit `type: "object"` declaration, the new code
does **not** infer the object type. The branches stay `type: "any"`, go through `createTypedProperty()` instead
of `createObjectProperty()`, and no nested schema/class file is generated for them.

## Example Schema: Works correctly

When the oneOf parent has `type: "object"`, the branches inherit it:

```json
{
  "title": "WorksCorrectly",
  "type": "object",
  "properties": {
    "item": {
      "type": "object",
      "oneOf": [
        {
          "properties": {
            "name": { "type": "string" }
          },
          "additionalProperties": false
        },
        {
          "properties": {
            "id": { "type": "integer" }
          },
          "additionalProperties": false
        }
      ]
    }
  }
}
```

**Generates**: 3 classes (main + 2 oneOf branch classes)

## Example Schema: Broken

When the oneOf parent lacks `type: "object"`:

```json
{
  "title": "Broken",
  "type": "object",
  "properties": {
    "item": {
      "oneOf": [
        {
          "properties": {
            "name": { "type": "string" }
          },
          "additionalProperties": false
        },
        {
          "properties": {
            "id": { "type": "integer" }
          },
          "additionalProperties": false
        }
      ]
    }
  }
}
```

**Generates**: 1 class (only main, no branch classes)

## Issue #133 (PR #134): Validator Leakage

Schema: `oneOfWithMetricIdConflict.json`

```json
{
  "title": "Issue133Test",
  "type": "object",
  "properties": {
    "oneOfWithMetricIdConflict": {
      "oneOf": [
        {
          "properties": {
            "metric_id": {
              "type": "string",
              "enum": ["impressions", "spend", "clicks", "ctr"]
            },
            "scope": {
              "type": "string",
              "enum": ["vendor"]
            }
          },
          "required": ["metric_id", "scope"],
          "additionalProperties": false
        },
        {
          "properties": {
            "metric_id": {
              "type": "string",
              "minLength": 1,
              "maxLength": 64,
              "pattern": "^[a-z][a-z0-9_]*$"
            },
            "scope": {
              "type": "string",
              "enum": ["standard"]
            }
          },
          "required": ["metric_id", "scope"],
          "additionalProperties": false
        }
      ]
    }
  }
}
```

**Bug**: Because the oneOf parent (`oneOfWithMetricIdConflict`) has no `type`, only 1 class is generated. The
property `metric_id` from both branches — if branches had separate classes — would have its own validators
per branch. Without separate classes, when `Schema::addProperty()` merges identical property names, validators
from both branches end up on the single parent property. Input `{"scope": "standard", "metric_id": "custom_metric"}`
correctly matches branch 2 at the composed-validator level, but the parent setter's enum validator (from
branch 1) rejects `"custom_metric"`.

**Root Cause**: The `inheritPropertyType()` method in `AbstractCompositionValidatorFactory` returns early when
the parent has no `type`, so branches don't get `type: "object"` inferred.

## Issue #137 (PR #139): Missing Enum Type Hints

Schema: `oneOfWithArrayDef.json`

```json
{
  "title": "Issue137Test",
  "type": "object",
  "properties": {
    "status_filter": {
      "oneOf": [
        { "$ref": "#/$defs/MediaBuyStatus" },
        {
          "type": "array",
          "items": { "$ref": "#/$defs/MediaBuyStatus" },
          "minItems": 1
        }
      ]
    }
  },
  "$defs": {
    "MediaBuyStatus": {
      "type": "string",
      "enum": ["pending_creatives", "pending_start", "active", "paused", "ended", "archived"]
    }
  }
}
```

**Bug**: The generated type hint is `string|array|null` instead of `string|MediaBuyStatus|string[]|MediaBuyStatus[]|null`.
The enum class name (`MediaBuyStatus`) is not propagated to the parent property's type hint because
`transferPropertyType()` only collects base type names (`string`, `array`) from the branches.

## Root Cause Analysis

The method `inheritPropertyType()` in `AbstractCompositionValidatorFactory`:

```php
protected function inheritPropertyType(JsonSchema $propertySchema): JsonSchema
{
    $json = $propertySchema->getJson();

    // Early return when parent has no type — branches stay untyped
    if (!isset($json['type'])) {
        return $propertySchema;
    }

    switch ($this->key) {
        case 'not':
        case 'if':
        default:
            foreach ($json[$this->key] as &$composedElement) {
                if (!isset($composedElement['type'])) {
                    $composedElement['type'] = $json['type'];
                }
            }
    }

    return $propertySchema->withJson($json);
}
```

When the parent lacks `type`, all branches stay untyped. `PropertyFactory::create()` routes them to
`createTypedProperty()` (no nested schema), which means:
1. No separate PHP class file is generated per branch
2. `transferComposedPropertiesToSchema()` cannot transfer properties (it requires `getNestedSchema()`)
3. The composed validator works but branch-specific validators aren't properly scoped

## Implementation Plan

### Phase 1: Fix branch type inference

**File**: `src/Model/Validator/Factory/Composition/AbstractCompositionValidatorFactory.php`

Modify `inheritPropertyType()` to detect object-like keywords (`properties`, `required`, `additionalProperties`)
in composition branches and inject `type: object` even when the parent lacks an explicit type:

```php
protected function inheritPropertyType(JsonSchema $propertySchema): JsonSchema
{
    $json = $propertySchema->getJson();

    $hasObjectKeywords = function (array $element): bool {
        if (isset($element['type'])) {
            return false;
        }
        return isset($element['properties'])
            || isset($element['required'])
            || isset($element['additionalProperties']);
    };

    switch ($this->key) {
        case 'not':
            if (isset($json[$this->key]) && !isset($json[$this->key]['type'])) {
                if (isset($json['type'])) {
                    $json[$this->key]['type'] = $json['type'];
                } elseif ($hasObjectKeywords($json[$this->key])) {
                    $json[$this->key]['type'] = 'object';
                }
            }
            break;
        case 'if':
            return $this->inheritIfPropertyType($propertySchema->withJson($json));
        default:
            foreach ($json[$this->key] as &$composedElement) {
                if (isset($composedElement['type'])) {
                    continue;
                }
                if (isset($json['type'])) {
                    $composedElement['type'] = $json['type'];
                } elseif ($hasObjectKeywords($composedElement)) {
                    $composedElement['type'] = 'object';
                }
            }
    }

    return $propertySchema->withJson($json);
}
```

### Phase 2: Create tests for Issues #133 and #137

Create test files:
- `tests/Schema/Issues/133/oneOfWithMetricIdConflict.json`
- `tests/Issues/Issue/Issue133Test.php`
- `tests/Schema/Issues/137/oneOfWithArrayDef.json`
- `tests/Issues/Issue/Issue137Test.php`

Each test verifies that the correct number of class files are generated and that validation works correctly
(branch-specific validators don't leak, enum type hints include class names).

### Phase 3: Fix validator leakage (Issue #133)

**Files**: `src/SchemaProcessor/SchemaProcessor.php` (or `src/Utils/PropertyMerger.php`)

When `transferComposedPropertiesToSchema()` transfers properties from oneOf branches to the parent schema,
and the same property name appears in multiple branches:
1. Only widen the type to a union
2. Do NOT merge branch-specific validators onto the parent property
3. Branch-specific validation already runs inside the `ComposedItem.phptpl` composed validator loop

### Phase 4: Fix enum type hints (Issue #137)

**File**: `src/Model/Validator/Factory/Composition/AbstractCompositionValidatorFactory.php`

In `transferPropertyType()`, propagate type-hint decorator names (including enum class names) from the
composition properties alongside the base type names when setting the parent property's type.

## Verification

After each phase, run:
```bash
./vendor/bin/phpunit --no-coverage --display-warnings 2>&1 | tail -5
```

All 2405 existing tests must pass. New test cases must cover:
- oneOf with explicit `type: object` branches (should generate 1+N classes — already works)
- oneOf with implicit object branches (should generate 1+N classes — Phase 1 fix)
- oneOf with same property name across branches (should not leak validators — Phase 3 fix)
- oneOf with `$ref` to enum definitions (should include enum class in type hints — Phase 4 fix)
