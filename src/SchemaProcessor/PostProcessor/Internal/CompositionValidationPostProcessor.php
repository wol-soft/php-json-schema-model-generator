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
use PHPModelGenerator\SchemaProcessor\Hook\ConstructorAfterValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\Hook\SetterAfterValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\Hook\SetterBeforeValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\RenderedMethod;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class CompositionValidationPostProcessor
 *
 * The CompositionValidationPostProcessor adds methods to models which require composition validations on object level
 * to validate the compositions.
 *
 * Additionally extends setter methods to also validate compositions if the updated property is part of a composition
 *
 * @package PHPModelGenerator\SchemaProcessor\PostProcessor\Internal
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

        $handsOverFilteredValues = $this->addValidationMethods(
            $schema,
            $generatorConfiguration,
            $compositionValidatorKeys,
        );

        if ($handsOverFilteredValues) {
            $this->addBranchFilteredValueConstructorHook($schema);
        }

        // if the generator is immutable no validation on value updates are required
        if ($generatorConfiguration->isImmutable() || empty($validatorPropertyMap)) {
            return;
        }

        $this->addValidationCallsToSetterMethods($schema, $validatorPropertyMap, $handsOverFilteredValues);
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

            foreach ($validator->getComposedProperties() as $composedProperty) {
                if ($composedProperty->getNestedSchema() === null) {
                    continue;
                }

                foreach ($composedProperty->getNestedSchema()->getProperties() as $property) {
                    if (!isset($validatorPropertyMap[$property->getName()])) {
                        $validatorPropertyMap[$property->getName()] = [];
                    }

                    $validatorPropertyMap[$property->getName()][] = $validatorIndex;
                }
            }
        }

        if (!empty($validatorPropertyMap)) {
            $schema->addProperty(
                (new Property(
                    'propertyValidationState',
                    new PropertyType('array'),
                    new JsonSchema(__FILE__, []),
                    'Track the internal validation state of composed validations',
                ))
                    ->setInternal(true)
                    ->setDefaultValue(
                        array_fill_keys(
                            array_unique(
                                array_merge(...array_values($validatorPropertyMap)),
                            ),
                            [],
                        )
                    ),
            );
        }

        return $validatorPropertyMap;
    }

    /**
     * @param int[] $compositionValidatorKeys
     *
     * @return bool Whether a composition hands over filtered values of its active branch to the schema
     */
    private function addValidationMethods(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        array $compositionValidatorKeys,
    ): bool {
        $handsOverFilteredValues = false;
        $allFilteredKeys = [];

        foreach ($compositionValidatorKeys as $validatorIndex) {
            /** @var AbstractComposedPropertyValidator $compositionValidator */
            $compositionValidator = $schema->getBaseValidators()[$validatorIndex];

            $compositionValidator->setScope($schema);

            $filteredKeys = $compositionValidator->getFilteredKeys();
            $handsOverFilteredValues = $handsOverFilteredValues || $filteredKeys !== [];
            $allFilteredKeys += $filteredKeys;

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
                        'branchFilteredKeys' => $filteredKeys === [] ? '' : RenderHelper::varExportArray($filteredKeys),
                        'branchFilteredKeyLookup' => RenderHelper::varExportArray(
                            array_fill_keys(array_keys($filteredKeys), true),
                        ),
                    ],
                )
            );
        }

        if ($handsOverFilteredValues) {
            // The filtered values which the active branches of the compositions handed over, by property name, and the
            // values which are staged by the update in progress. A failed update leaves the staged values unused.
            foreach (
                [
                    'branchFilteredValues' => 'Filtered values handed over by the active branches of compositions',
                    'stagedBranchFilteredValues' => 'Filtered values staged by the update in progress',
                ] as $name => $description
            ) {
                $schema->addProperty(
                    (new Property($name, new PropertyType('array'), new JsonSchema(__FILE__, []), $description))
                        ->setInternal(true)
                        ->setDefaultValue([]),
                );
            }

            $schema->addMethod(
                '_commitBranchFilteredValues',
                new RenderedMethod(
                    $schema,
                    $generatorConfiguration,
                    'CommitBranchFilteredValues.phptpl',
                    ['branchFilteredKeys' => RenderHelper::varExportArray($allFilteredKeys)],
                ),
            );
        }

        return $handsOverFilteredValues;
    }

    /**
     * The constructor validates all properties against the staged values, adopt them once the object is valid.
     */
    private function addBranchFilteredValueConstructorHook(Schema $schema): void
    {
        $schema->addSchemaHook(new class implements ConstructorAfterValidationHookInterface {
            public function getCode(): string
            {
                return '$this->_commitBranchFilteredValues(array_keys($this->_rawModelDataInput));';
            }
        });
    }

    /**
     * Add internal calls to validation methods to the setters which are part of a composition validation. The
     * validation methods will validate the state of all compositions when the value is updated.
     *
     * The compositions stage the filtered values of their active branches. A setter starts with an empty staging, the
     * values are adopted once the update succeeded. The batch update (populate) validates all compositions up front
     * and adopts the staged values after all properties were updated.
     */
    private function addValidationCallsToSetterMethods(
        Schema $schema,
        array $validatorPropertyMap,
        bool $handsOverFilteredValues,
    ): void {
        $schema->addSchemaHook(
            new class ($validatorPropertyMap, $handsOverFilteredValues) implements SetterBeforeValidationHookInterface {
                public function __construct(protected array $validatorPropertyMap, protected bool $stages)
                {}

                public function getCode(PropertyInterface $property, bool $batchUpdate = false): string
                {
                    $validatorIndices = array_unique($this->validatorPropertyMap[$property->getName()] ?? []);

                    $code = array_map(
                        static fn(int $validatorIndex): string =>
                            sprintf('$this->_validateComposition_%s($modelData);', $validatorIndex),
                        $validatorIndices,
                    );

                    if ($this->stages && !$batchUpdate && $validatorIndices !== []) {
                        array_unshift($code, '$this->_stagedBranchFilteredValues = [];');
                    }

                    return join("\n", $code);
                }
            },
        );

        if (!$handsOverFilteredValues) {
            return;
        }

        $schema->addSchemaHook(new class ($validatorPropertyMap) implements SetterAfterValidationHookInterface {
            public function __construct(protected array $validatorPropertyMap)
            {}

            public function getCode(PropertyInterface $property, bool $batchUpdate = false): string
            {
                if (empty($this->validatorPropertyMap[$property->getName()])) {
                    return '';
                }

                return $batchUpdate
                    ? '$this->_commitBranchFilteredValues(array_keys($modelData));'
                    : sprintf('$this->_commitBranchFilteredValues([%s]);', var_export($property->getName(), true));
            }
        });
    }
}
