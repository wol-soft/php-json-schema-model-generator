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

        $allFilteredKeys = $this->addValidationMethods(
            $schema,
            $generatorConfiguration,
            $compositionValidatorKeys,
        );

        if ($allFilteredKeys !== []) {
            $this->addBranchFilteredValueConstructorHook($schema);
        }

        // if the generator is immutable no validation on value updates are required
        if ($generatorConfiguration->isImmutable() || empty($validatorPropertyMap)) {
            return;
        }

        $this->addValidationCallsToSetterMethods($schema, $validatorPropertyMap, $allFilteredKeys);
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
     * @return array The properties for which a composition hands over filtered values of its active branch to the
     *               schema (property name => [attribute, validation method]); empty if there are none
     */
    private function addValidationMethods(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        array $compositionValidatorKeys,
    ): array {
        $allFilteredKeys = [];
        $tracksFilterSources = false;

        foreach ($compositionValidatorKeys as $validatorIndex) {
            /** @var AbstractComposedPropertyValidator $compositionValidator */
            $compositionValidator = $schema->getBaseValidators()[$validatorIndex];

            $compositionValidator->setScope($schema);

            $filteredKeys = $compositionValidator->getFilteredKeys();
            $allFilteredKeys += $filteredKeys;
            $publishesFilterSources = $compositionValidator->getFilterSourceMap() !== [];
            $tracksFilterSources = $tracksFilterSources || $publishesFilterSources;

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
                        'branchFilteredKeyComponents' => RenderHelper::varExportArray(
                            $compositionValidator->getFilteredKeyComponents(),
                        ),
                        'publishesFilterSources' => $publishesFilterSources,
                    ],
                )
            );
        }

        if ($allFilteredKeys !== []) {
            // The filtered values which the active branches of the compositions handed over, by property name, and the
            // values which are staged by the update in progress. A failed update leaves the staged values unused.
            foreach (
                [
                    'branchFilteredValues' => 'Filtered values handed over by the active branches of compositions',
                    'stagedBranchFilteredValues' => 'Filtered values staged by the update in progress',
                ] + (
                    $tracksFilterSources
                        // See FilterValidator::requiresSourceTracking()
                        ? ['branchFilteredSources' => 'The filters which produced the handed over filtered values']
                        : []
                ) as $name => $description
            ) {
                $schema->addProperty(
                    (new Property($name, new PropertyType('array'), new JsonSchema(__FILE__, []), $description))
                        ->setInternal(true)
                        ->setDefaultValue([]),
                );
            }

            // Prepared before anything is assigned (a rejected dependent property rejects the update), applied once the
            // update succeeded.
            $schema->addMethod(
                '_prepareBranchFilteredValues',
                new RenderedMethod(
                    $schema,
                    $generatorConfiguration,
                    'PrepareBranchFilteredValues.phptpl',
                    [
                        'branchFilteredKeys' => RenderHelper::varExportArray($allFilteredKeys),
                        'tracksFilterSources' => $tracksFilterSources,
                    ],
                ),
            );
            $schema->addMethod(
                '_applyBranchFilteredValues',
                new RenderedMethod(
                    $schema,
                    $generatorConfiguration,
                    'ApplyBranchFilteredValues.phptpl',
                    ['tracksFilterSources' => $tracksFilterSources],
                ),
            );
        }

        return $allFilteredKeys;
    }

    /**
     * The constructor validates all properties against the staged values, adopt them once the object is valid.
     */
    private function addBranchFilteredValueConstructorHook(Schema $schema): void
    {
        $schema->addSchemaHook(new class implements ConstructorAfterValidationHookInterface {
            public function getCode(): string
            {
                return '$this->_applyBranchFilteredValues('
                    . '$this->_prepareBranchFilteredValues(null, $this->_rawModelDataInput));';
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
     *
     * @param array $allFilteredKeys property name => [attribute, validation method]
     */
    private function addValidationCallsToSetterMethods(
        Schema $schema,
        array $validatorPropertyMap,
        array $allFilteredKeys,
    ): void {
        $schema->addSchemaHook(
            new class ($validatorPropertyMap, $allFilteredKeys !== []) implements SetterBeforeValidationHookInterface {
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
                        // Before the setter assigns anything (see the setter after validation hook).
                        $code[] = sprintf(
                            '$preparedBranchFilteredValues = $this->_prepareBranchFilteredValues(%s, %s);',
                            RenderHelper::varExportArray([$property->getAttribute(true)]),
                            'array_merge($this->_rawModelDataInput, $modelData)',
                        );
                    }

                    return join("\n", $code);
                }
            },
        );

        if ($allFilteredKeys === []) {
            return;
        }

        // populate() prepares and applies the filtered values itself, once for all properties.
        $schema->addSchemaHook(new class ($validatorPropertyMap) implements SetterAfterValidationHookInterface {
            public function __construct(protected array $validatorPropertyMap)
            {}

            public function getCode(PropertyInterface $property, bool $batchUpdate = false): string
            {
                return $batchUpdate || empty($this->validatorPropertyMap[$property->getName()])
                    ? ''
                    : '$this->_applyBranchFilteredValues($preparedBranchFilteredValues);';
            }
        });
    }
}
