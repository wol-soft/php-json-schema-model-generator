<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor\Internal;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\SchemaProcessor\Hook\SetterBeforeValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\RenderedMethod;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Adds methods to models that require composition validations at object level, and extends setter
 * methods to re-run composition validation when an updated property is part of a composition.
 */
class CompositionValidationPostProcessor extends PostProcessor
{
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $compositionValidatorKeys = $schema->getCompositionValidatorKeys();

        if (empty($compositionValidatorKeys)) {
            return;
        }

        $validatorPropertyMap = $this->generateValidatorPropertyMap($schema);

        $this->addValidationMethods($schema, $generatorConfiguration, $compositionValidatorKeys);

        // if the generator is immutable no validation on value updates are required
        if ($generatorConfiguration->isImmutable()) {
            return;
        }

        // The composition template caches each branch's outcome in _propertyValidationState for
        // every mutable base composition — even one whose branches declare no properties (e.g.
        // allOf: [{minProperties: 3}]) and thus produce an empty map. Declare the field whenever
        // the model is mutable and carries a composition so those writes never create a dynamic
        // property, which PHP 8.4 deprecates.
        $this->addPropertyValidationStateField($schema, $validatorPropertyMap);

        if (empty($validatorPropertyMap)) {
            return;
        }

        $this->addValidationCallsToSetterMethods($schema, $validatorPropertyMap);
    }

    /**
     * Set up a map containing the properties and the corresponding composition validators which must be checked when
     * the property is updated
     */
    private function generateValidatorPropertyMap(Schema $schema): array
    {
        $validatorPropertyMap = [];

        // get all base validators which are composed value validators and set up a map of affected object properties
        foreach ($schema->getBaseValidators() as $validatorIndex => $validator) {
            if (!is_a($validator, AbstractComposedPropertyValidator::class)) {
                continue;
            }

            $dependsOnUndeclaredKeys = false;

            foreach ($validator->getComposedProperties() as $composedProperty) {
                // A branch keyword decided by the shape or count of the instance's keys
                // (additionalProperties, patternProperties, min/maxProperties, ...) can flip its
                // outcome when a key the branch does not declare changes. No declared-name list
                // describes which mutations affect it, so every setter must re-run the whole
                // composition — see the mapping to all schema properties below.
                if ($composedProperty->branchEvaluationDependsOnUndeclaredKeys()) {
                    $dependsOnUndeclaredKeys = true;
                }

                // A schema-level composition branch usually has a nested schema at this point
                // (inheritPropertyType() forces branches to adopt the parent's object type, so
                // PropertyFactory routes them through createObjectProperty()), but not always: a
                // self-referencing or mutually-recursive $ref branch can resolve to a placeholder
                // with no nested schema of its own. Skip mapping declared properties for such a
                // branch — branchEvaluationDependsOnUndeclaredKeys() above already ensures a
                // key-sensitive branch still gets full setter coverage regardless.
                if ($composedProperty->getNestedSchema() === null) {
                    continue;
                }

                foreach ($composedProperty->getNestedSchema()->getProperties() as $property) {
                    $this->mapPropertyToValidator($validatorPropertyMap, $property->getName(), $validatorIndex);
                }
            }

            if (!$dependsOnUndeclaredKeys) {
                continue;
            }

            // A key-sensitive branch reacts to keys no branch declares, so mapping the validator
            // only to branch-declared names would leave a plain setter for a sibling property
            // (declared on the outer schema but on no branch) without a revalidation call — that
            // setter could then commit a value the branch rejects. Map the validator to every
            // declared property so all of them revalidate the composition.
            foreach ($schema->getProperties() as $property) {
                if (!$property->isInternal()) {
                    $this->mapPropertyToValidator($validatorPropertyMap, $property->getName(), $validatorIndex);
                }
            }
        }

        return $validatorPropertyMap;
    }

    /**
     * Append $validatorIndex to the property's entry in the map, creating the entry when absent.
     * Duplicate indexes are tolerated: every consumer dedups (the setter hook via array_unique,
     * the field default via array_unique).
     */
    private function mapPropertyToValidator(
        array &$validatorPropertyMap,
        string $propertyName,
        int $validatorIndex,
    ): void {
        if (!isset($validatorPropertyMap[$propertyName])) {
            $validatorPropertyMap[$propertyName] = [];
        }

        $validatorPropertyMap[$propertyName][] = $validatorIndex;
    }

    /**
     * Declare the _propertyValidationState cache field. The default seeds one empty slot per
     * composition validator index that appears in the map; the composition template auto-vivifies
     * any further index it writes, so an empty default (empty map) is valid too.
     */
    private function addPropertyValidationStateField(Schema $schema, array $validatorPropertyMap): void
    {
        $seededValidatorIndexes = $validatorPropertyMap === []
            ? []
            : array_unique(array_merge(...array_values($validatorPropertyMap)));

        $schema->addProperty(
            (new Property(
                'propertyValidationState',
                new PropertyType('array'),
                new JsonSchema(__FILE__, []),
                'Track the internal validation state of composed validations',
            ))
                ->setInternal(true)
                ->setDefaultValue(array_fill_keys($seededValidatorIndexes, [])),
        );
    }

    /**
     * @param int[] $compositionValidatorKeys
     */
    private function addValidationMethods(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        array $compositionValidatorKeys,
    ): void {
        foreach ($compositionValidatorKeys as $validatorIndex) {
            /** @var AbstractComposedPropertyValidator $compositionValidator */
            $compositionValidator = $schema->getBaseValidators()[$validatorIndex];

            $compositionValidator->setScope($schema);

            $schema->addMethod(
                "_validateComposition_$validatorIndex",
                new RenderedMethod(
                    $schema,
                    $generatorConfiguration,
                    'CompositionValidation.phptpl',
                    [
                        'validator' => $compositionValidator,
                        'schema' => $schema,
                        'index' => $validatorIndex,
                        'viewHelper' => new RenderHelper($generatorConfiguration),
                    ],
                )
            );
        }
    }

    /**
     * Add internal calls to validation methods to the setters which are part of a composition validation. The
     * validation methods will validate the state of all compositions when the value is updated.
     */
    private function addValidationCallsToSetterMethods(Schema $schema, array $validatorPropertyMap): void
    {
        $schema->addSchemaHook(new class ($validatorPropertyMap) implements SetterBeforeValidationHookInterface {
            public function __construct(protected array $validatorPropertyMap)
            {}

            public function getCode(PropertyInterface $property, bool $batchUpdate = false): string
            {
                return join(
                    "\n",
                    array_map(
                        static fn(int $validatorIndex): string =>
                            sprintf('$this->_validateComposition_%s($modelData);', $validatorIndex),
                        array_unique($this->validatorPropertyMap[$property->getName()] ?? []),
                    )
                );
            }
        });
    }
}
