<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor\Internal;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\MethodInterface;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Traits\CompositionEvaluationTrait;

/**
 * Detects whether unevaluatedProperties or unevaluatedItems is reachable from a schema
 * and, when it is, activates evaluation tracking on that schema's composition validators.
 *
 * Activation emits the _compositionEvaluations cache field on the generated class and marks
 * each composition validator with per-branch slot writes.
 * When neither keyword is reachable anywhere in the schema graph, this post processor is a
 * complete no-op and generates no extra code.
 */
class UnevaluatedPropertiesPostProcessor extends PostProcessor
{
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $seen = [];

        if (!$this->needsActivation($schema, $seen)) {
            return;
        }

        foreach ($schema->getBaseValidators() as $baseValidator) {
            if ($baseValidator instanceof AbstractComposedPropertyValidator) {
                $baseValidator->enableEvaluationTracking();
            }
        }

        if (empty($schema->getCompositionValidatorKeys())) {
            return;
        }

        $schema->addTrait(CompositionEvaluationTrait::class);

        $schema->addProperty(
            (new Property(
                'compositionEvaluations',
                new PropertyType('array'),
                new JsonSchema(__FILE__, []),
            ))
                ->setInternal(true)
                ->setDefaultValue([]),
        );

        $this->addGetEvaluatedPropertiesToNestedBranchSchemas($schema);
    }

    /**
     * For each composition branch that produces a nested object class, adds a
     * getEvaluatedProperties() method so the unevaluatedProperties validator can query
     * which properties the nested class evaluated.
     */
    private function addGetEvaluatedPropertiesToNestedBranchSchemas(Schema $schema): void
    {
        foreach ($schema->getBaseValidators() as $baseValidator) {
            if (!$baseValidator instanceof AbstractComposedPropertyValidator) {
                continue;
            }

            foreach ($baseValidator->getComposedProperties() as $composedProperty) {
                $nestedSchema = $composedProperty->getNestedSchema();

                if ($nestedSchema === null || $nestedSchema->hasMethod('getEvaluatedProperties')) {
                    continue;
                }

                $nestedSchema->addMethod(
                    'getEvaluatedProperties',
                    $this->buildGetEvaluatedPropertiesMethod($nestedSchema),
                );
            }
        }
    }

    /**
     * Builds a MethodInterface that emits getEvaluatedProperties() for the given nested schema.
     *
     * The method returns all declared property names (from the nested schema's `properties`
     * keyword) that are present in the instance's raw model data at the time of the call.
     */
    private function buildGetEvaluatedPropertiesMethod(Schema $nestedSchema): MethodInterface
    {
        return new class ($nestedSchema) implements MethodInterface {
            public function __construct(private readonly Schema $nestedSchema)
            {
            }

            public function getCode(): string
            {
                $declaredPropertyNames = array_values(array_map(
                    static fn(PropertyInterface $property): string => $property->getName(),
                    array_filter(
                        $this->nestedSchema->getProperties(),
                        static fn(PropertyInterface $property): bool => !$property->isInternal(),
                    ),
                ));

                return sprintf(
                    '
                    public function getEvaluatedProperties(): array
                    {
                        $evaluated = [];
                        foreach (%s as $propName) {
                            if (array_key_exists($propName, $this->_rawModelDataInput)) {
                                $evaluated[$propName] = true;
                            }
                        }
                        return array_keys($evaluated);
                    }',
                    var_export($declaredPropertyNames, true),
                );
            }
        };
    }

    /**
     * Returns true when the schema or any reachable subschema contains unevaluatedProperties
     * or unevaluatedItems, meaning composition validators on this schema must emit the
     * _compositionEvaluations cache.
     *
     * Uses a $seen map keyed by file+pointer to break reference cycles.
     */
    private function needsActivation(Schema $schema, array &$seen): bool
    {
        $schemaKey = $schema->getJsonSchema()->getFile() . '#' . $schema->getJsonSchema()->getPointer();

        if (array_key_exists($schemaKey, $seen)) {
            return $seen[$schemaKey];
        }

        // Mark false initially so cycles terminate without infinite recursion.
        $seen[$schemaKey] = false;

        $json = $schema->getJsonSchema()->getJson();

        if (array_key_exists('unevaluatedProperties', $json) || array_key_exists('unevaluatedItems', $json)) {
            return $seen[$schemaKey] = true;
        }

        // Check each composition branch: both the branch-level JSON and any nested schema it produces.
        foreach ($schema->getBaseValidators() as $baseValidator) {
            if (!$baseValidator instanceof AbstractComposedPropertyValidator) {
                continue;
            }

            foreach ($baseValidator->getComposedProperties() as $composedProperty) {
                $branchJson = $composedProperty->getBranchSchema()->getJson();

                if (
                    array_key_exists('unevaluatedProperties', $branchJson)
                    || array_key_exists('unevaluatedItems', $branchJson)
                ) {
                    return $seen[$schemaKey] = true;
                }

                $nestedSchema = $composedProperty->getNestedSchema();

                if ($nestedSchema !== null && $this->needsActivation($nestedSchema, $seen)) {
                    return $seen[$schemaKey] = true;
                }
            }
        }

        // Check property-level nested schemas (e.g. an object-typed property with its own composition).
        foreach ($schema->getProperties() as $schemaProperty) {
            $nestedSchema = $schemaProperty->getNestedSchema();

            if ($nestedSchema !== null && $this->needsActivation($nestedSchema, $seen)) {
                return $seen[$schemaKey] = true;
            }
        }

        return $seen[$schemaKey] = false;
    }
}
