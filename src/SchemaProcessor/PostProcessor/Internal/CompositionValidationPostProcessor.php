<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor\Internal;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\SchemaProcessor\Hook\ConstructorAfterValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\RenderedMethod;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Adds methods to models which require composition validations on object level to validate the compositions, and the
 * state of the compositions of mutable models. The setters execute the validation methods of the compositions an
 * update of the property may change and stage, prepare and apply the filtered values of the active branches (see
 * RenderHelper::renderSetterValidation()).
 *
 * Validating the compositions via a setter hook was rejected: hook code makes a setter snapshot the model state on
 * every call, also if the update of a present key can't change the result of a composition.
 */
class CompositionValidationPostProcessor extends PostProcessor
{
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $compositionValidatorKeys = $schema->getCompositionValidatorKeys();

        if (empty($compositionValidatorKeys)) {
            return;
        }

        $allFilteredKeys = $this->addValidationMethods(
            $schema,
            $generatorConfiguration,
            $compositionValidatorKeys,
        );

        if ($allFilteredKeys !== []) {
            $this->addBranchFilteredValueConstructorHook($schema);
        }

        // if the generator is immutable no validation on value updates are required
        if ($generatorConfiguration->isImmutable()) {
            return;
        }

        $this->addValidationStateProperty($schema, $compositionValidatorKeys);
    }

    /**
     * The composition of a mutable model records the result of each branch, whether or not a branch declares a
     * property. Declaring the state only if a setter validates the composition was rejected: the composition records
     * its result in the constructor as well, an undeclared state is created as (deprecated) dynamic property.
     *
     * @param int[] $compositionValidatorKeys
     */
    private function addValidationStateProperty(Schema $schema, array $compositionValidatorKeys): void
    {
        $schema->addProperty(
            (new Property(
                'propertyValidationState',
                new PropertyType('array'),
                new JsonSchema(__FILE__, []),
                'Track the internal validation state of composed validations',
            ))
                ->setInternal(true)
                ->setDefaultValue(array_fill_keys($compositionValidatorKeys, [])),
        );
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
}
