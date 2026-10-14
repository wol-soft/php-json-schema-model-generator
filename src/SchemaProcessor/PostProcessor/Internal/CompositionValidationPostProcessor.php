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
use PHPModelGenerator\SchemaProcessor\Hook\SetterRollbackHookInterface;
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

        $this->addValidationMethods($schema, $generatorConfiguration, $compositionValidatorKeys);

        // if the generator is immutable no validation on value updates are required
        if ($generatorConfiguration->isImmutable() || empty($validatorPropertyMap)) {
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

        // The composition validation records the result of each component in the validation state and applies the
        // defaults of the matching branches before the update is accepted. A rejected update must leave both as
        // they were, otherwise the rejected data influences later updates (components are only evaluated again if
        // their properties are updated) and the model contains defaults of a branch which doesn't match.
        $branchDefaultAttributes = [];
        foreach ($schema->getCompositionValidatorKeys() as $validatorIndex) {
            $branchDefaultAttributes[$validatorIndex] = $schema->getBaseValidators()[$validatorIndex]
                ->getBranchDefaultAttributes();
        }

        $schema->addSchemaHook(
            new class ($validatorPropertyMap, $branchDefaultAttributes) implements SetterRollbackHookInterface {
                public function __construct(
                    protected array $validatorPropertyMap,
                    protected array $branchDefaultAttributes,
                ) {}

                public function getBackupCode(?PropertyInterface $property): string
                {
                    $attributes = $this->getRestoredAttributes($property);

                    return $attributes === []
                        ? ''
                        : sprintf('$compositionStateBackup = [%s];', $this->renderAttributes($attributes));
                }

                public function getRestoreCode(?PropertyInterface $property): string
                {
                    $attributes = $this->getRestoredAttributes($property);

                    return $attributes === []
                        ? ''
                        : sprintf('[%s] = $compositionStateBackup;', $this->renderAttributes($attributes));
                }

                /**
                 * @return string[] The attributes which are changed by the compositions the property (or, for a
                 *                  batch update, any property) is part of
                 */
                private function getRestoredAttributes(?PropertyInterface $property): array
                {
                    $validatorIndices = $property === null
                        ? array_merge(...array_values($this->validatorPropertyMap))
                        : ($this->validatorPropertyMap[$property->getName()] ?? []);

                    if ($validatorIndices === []) {
                        return [];
                    }

                    $defaultAttributes = [];
                    foreach (array_unique($validatorIndices) as $validatorIndex) {
                        $defaultAttributes = array_merge(
                            $defaultAttributes,
                            $this->branchDefaultAttributes[$validatorIndex] ?? [],
                        );
                    }

                    return array_merge(['_propertyValidationState'], array_unique($defaultAttributes));
                }

                private function renderAttributes(array $attributes): string
                {
                    return join(
                        ', ',
                        array_map(static fn(string $attribute): string => '$this->' . $attribute, $attributes),
                    );
                }
            },
        );
    }
}
