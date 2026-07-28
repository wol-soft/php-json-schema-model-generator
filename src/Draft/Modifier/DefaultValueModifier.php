<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft\Modifier;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\TypeConverter;

class DefaultValueModifier implements ModifierInterface
{
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $json = $propertySchema->getJson();

        if (!array_key_exists('default', $json)) {
            return;
        }

        // Scalar branch defaults are unreachable: the property value itself is what the
        // composition branch discriminates on, so a default can only fire when no value is
        // provided — but without a value there is no signal to select the branch in the
        // first place. Warn and drop rather than applying the default unconditionally.
        if ($this->isScalarInsideCompositionBranch($propertySchema)) {
            $schemaProcessor->getGeneratorConfiguration()->getLogger()->warning(
                "Property '{property}' declares a default value inside a composition branch in file"
                    . " '{file}'. Scalar branch defaults are unreachable and will be ignored.",
                ['property' => $property->getName(), 'file' => $propertySchema->getFile()],
            );

            return;
        }

        $default = $json['default'];
        $types   = isset($json['type']) ? (array) $json['type'] : [];

        if (empty($types)) {
            $property->setDefaultValue($default);
            return;
        }

        foreach ($types as $jsonType) {
            $phpType = TypeConverter::jsonSchemaToPHP($jsonType);

            // Allow integer literals as defaults for 'number' (float) properties
            if ($phpType === 'float' && is_int($default)) {
                $default = (float) $default;
            }

            $typeCheckFn = 'is_' . $phpType;

            // "array" additionally requires array_is_list(): a JSON object and a JSON array
            // both decode to a PHP array, so is_array() alone cannot tell a JSON-object-shaped
            // default apart from a genuine JSON-array default.
            $matchesType = $phpType === 'array'
                ? is_array($default) && array_is_list($default)
                : (function_exists($typeCheckFn) && $typeCheckFn($default));

            if ($matchesType) {
                $property->setDefaultValue($default);
                return;
            }
        }

        throw new SchemaException(
            sprintf(
                'Invalid type for default value of property %s in file %s',
                $property->getName(),
                $propertySchema->getFile(),
            ),
            $propertySchema,
        );
    }

    /**
     * Returns true when the given property schema is a scalar branch schema (not an object
     * with declared sub-properties) that sits directly at a composition branch level.
     *
     * Two cases must be distinguished:
     *
     * - A *named property inside an object-typed branch* (e.g. `sandbox` in
     *   `oneOf/1/properties/sandbox`) — its pointer ends with `/properties/<name>`.
     *   This is the object-level branch default handled by Phase 2; it must not be dropped.
     *
     * - A *branch schema that is itself scalar* (e.g. `oneOf/0` → `{type:string, default:"x"}`
     *   where the property value IS the discriminant) — its pointer ends with a branch-index
     *   segment, not `/properties/<name>`. This default is unreachable and must be dropped.
     *
     * A property whose pointer ends with `/properties/<name>` is always in the first category.
     * For the remaining pointers, stripping all `/properties/<name>` segments removes noise
     * from intermediate object-nesting, and the regex tests whether a composition keyword
     * segment is present in the structural path.
     */
    private function isScalarInsideCompositionBranch(JsonSchema $propertySchema): bool
    {
        if (isset($propertySchema->getJson()['properties'])) {
            return false;
        }

        $pointer = $propertySchema->getPointer();

        // A pointer ending in /properties/<name> means this is a named property inside an
        // object-typed branch — handled by per-branch runtime application, not warned about.
        if (preg_match('#/properties/[^/]+$#', $pointer)) {
            return false;
        }

        // Strip intermediate /properties/<name> segments to prevent false positives from
        // root properties coincidentally named after a composition keyword, then append '/'
        // so end-of-string branch segments are catchable by the regex.
        $structuralPointer = preg_replace('#/properties/[^/]+#', '', $pointer) . '/';

        return preg_match('#/(allOf|anyOf|oneOf)/\d+/#', $structuralPointer)
            || preg_match('#/(if|then|else)/#', $structuralPointer);
    }
}
