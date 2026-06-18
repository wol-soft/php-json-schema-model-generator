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
use PHPModelGenerator\Model\Validator\UnevaluatedPropertiesValidator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Traits\CompositionEvaluationTrait;

/**
 * Detects whether unevaluatedProperties or unevaluatedItems is reachable from a schema and,
 * when it is, activates evaluation tracking on that schema's composition validators.
 *
 * Activation emits the _compositionEvaluations cache field on the generated class and marks
 * each composition validator with per-branch slot writes. When neither keyword is reachable
 * anywhere in the schema graph, this post processor is a complete no-op.
 *
 * Cross-state revalidation under mutation (setter + populate) is *not* handled here. It lives
 * in Model.phptpl and Populate.phptpl as a direct call to _executePostCompositionValidators
 * against a candidate state — see the templates for details. The unevaluatedProperties
 * validator participates in that pass simply by being registered as a post-composition
 * validator on the schema; this post processor's only responsibility is the activation-side
 * work that makes the cache available to it.
 *
 * Known limitation: when a cross-state check fails in direct-exception mode, composition
 * validation that ran during the setter's per-property _validate* phase may have updated
 * _propertyValidationState / _compositionEvaluations with state that was never committed.
 * The cache may briefly hold "would-be" entries until the next mutation invalidates them.
 * This is a pre-existing concern with composition revalidation, not specific to the
 * unevaluatedProperties feature, and self-corrects the next time an affecting property
 * changes.
 */
class UnevaluatedPropertiesPostProcessor extends PostProcessor
{
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $seen = [];

        if (!$this->needsActivation($schema, $seen)) {
            return;
        }

        // A schema with a non-false `unevaluatedProperties` validator tracks each key the
        // validator successfully evaluates in `_evaluatedPropertyKeys`. That field is read
        // by `_getEvaluatedProperties()` on nested branch classes so an enclosing
        // `unevaluatedProperties` sees those keys as evaluated.
        $this->addEvaluatedPropertyKeysField($schema);

        // The nested branch schemas may already be queued — adding the method here, before any
        // render() runs, ensures every branch class carries _getEvaluatedProperties() regardless
        // of whether the outer or inner schema was processed first. RenderQueue::execute runs
        // process() over every job before render() begins.
        $this->addGetEvaluatedPropertiesToNestedBranchSchemas($schema);

        // The trait carries collectUnevaluatedKeys(), which every generated unevaluatedProperties
        // validator calls regardless of whether the schema also has composition validators. It
        // must therefore be attached on every activation-triggering schema, not only on ones that
        // declare allOf/anyOf/oneOf/if-then-else.
        $schema->addTrait(CompositionEvaluationTrait::class);

        // The cache field is only written when there are composition validators, but the trait
        // reads $this->_compositionEvaluations even on no-composition schemas. Always declare the
        // property so the read never touches an undefined property (deprecated in PHP 8.2+).
        $schema->addProperty(
            (new Property(
                'compositionEvaluations',
                new PropertyType('array'),
                new JsonSchema(__FILE__, []),
            ))
                ->setInternal(true)
                ->setDefaultValue([]),
        );

        foreach ($schema->getBaseValidators() as $baseValidator) {
            if ($baseValidator instanceof AbstractComposedPropertyValidator) {
                $baseValidator->enableEvaluationTracking();
            }
        }
    }

    /**
     * Adds the `_evaluatedPropertyKeys` collection field to a schema that carries the
     * schema-form `UnevaluatedPropertiesValidator`. Nested branch schemas reach this code
     * through their own `process()` call (RenderQueue calls each job's processors), so no
     * recursion across branches is required here.
     */
    private function addEvaluatedPropertyKeysField(Schema $schema): void
    {
        foreach ($schema->getPostCompositionValidators() as $postCompositionValidator) {
            if ($postCompositionValidator instanceof UnevaluatedPropertiesValidator) {
                $schema->addProperty(
                    (new Property(
                        'evaluatedPropertyKeys',
                        new PropertyType('array'),
                        new JsonSchema(__FILE__, []),
                    ))
                        ->setInternal(true)
                        ->setDefaultValue([]),
                );

                return;
            }
        }
    }

    /**
     * For each composition branch that produces a nested object class, adds an internal
     * `_getEvaluatedProperties()` method the enclosing schema's unevaluatedProperties
     * validator queries to learn which keys the nested class evaluated.
     *
     * The method name uses an underscore prefix so it cannot collide with a user-declared
     * schema property called `evaluatedProperties` (whose generated getter would be
     * `getEvaluatedProperties()` without the underscore) and so its internal-only role is
     * visible at the call site.
     */
    private function addGetEvaluatedPropertiesToNestedBranchSchemas(Schema $schema): void
    {
        foreach ($schema->getBaseValidators() as $baseValidator) {
            if (!$baseValidator instanceof AbstractComposedPropertyValidator) {
                continue;
            }

            foreach ($baseValidator->getComposedProperties() as $composedProperty) {
                $nestedSchema = $composedProperty->getNestedSchema();

                if ($nestedSchema === null || $nestedSchema->hasMethod('_getEvaluatedProperties')) {
                    continue;
                }

                $nestedSchema->addMethod(
                    '_getEvaluatedProperties',
                    $this->buildGetEvaluatedPropertiesMethod($nestedSchema),
                );
            }
        }
    }

    /**
     * Builds a MethodInterface that emits `_getEvaluatedProperties()` for the given nested
     * schema. The method returns the union of declared property names present in the
     * instance's raw model data and the keys recorded in `_evaluatedPropertyKeys` (populated
     * by the nested schema's own unevaluatedProperties validator).
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
                    #[Internal]
                    public function _getEvaluatedProperties(): array
                    {
                        $evaluated = [];
                        foreach (%s as $propName) {
                            if (array_key_exists($propName, $this->_rawModelDataInput)) {
                                $evaluated[$propName] = true;
                            }
                        }
                        // Keys evaluated by this schema\'s own unevaluatedProperties: {schema}
                        // validator are tracked so an enclosing schema can see them.
                        if (property_exists($this, "_evaluatedPropertyKeys")) {
                            foreach ($this->_evaluatedPropertyKeys as $propName => $_) {
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
