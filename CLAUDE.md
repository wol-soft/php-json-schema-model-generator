# CLAUDE.md

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

### Union type style

When rendering union types in generated PHP code, use one space before and after the pipe:

```php
int | string | null
```

Not:

```php
int|string|null
```
