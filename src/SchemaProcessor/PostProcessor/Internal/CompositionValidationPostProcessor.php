<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor\Internal;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\SchemaProcessor\Hook\SetterBeforeValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessorInterface;
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
class CompositionValidationPostProcessor implements PostProcessorInterface
{
    /**
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
     */
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $validatorPropertyMap = $this->generateValidatorPropertyMap($schema, $generatorConfiguration);

        if (empty($validatorPropertyMap)) {
            return;
        }

        $this->addValidationMethods($schema, $generatorConfiguration, $validatorPropertyMap);

        // if the generator is immutable no validation on value updates are required
        if ($generatorConfiguration->isImmutable()) {
            return;
        }

        $this->addValidationCallsToSetterMethods($schema, $validatorPropertyMap);
    }

    /**
     * Set up a map containing the properties and the corresponding composition validators which must be checked when
     * the property is updated
     *
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
     *
     * @return array
     */
    private function generateValidatorPropertyMap(Schema $schema, GeneratorConfiguration $generatorConfiguration): array
    {
        $validatorPropertyMap = [];

        // get all base validators which are composed value validators and set up a map of affected object properties
        foreach ($schema->getBaseValidators() as $validatorIndex => $validator) {
            if (!is_a($validator, AbstractComposedPropertyValidator::class)) {
                continue;
            }

            foreach ($validator->getComposedProperties() as $composedProperty) {
                foreach ($composedProperty->getNestedSchema()->getProperties() as $property) {
                    if (!isset($validatorPropertyMap[$property->getName()])) {
                        $validatorPropertyMap[$property->getName()] = [];
                    }

                    $validatorPropertyMap[$property->getName()][] = $validatorIndex;
                }
            }
        }

        if (!empty($validatorPropertyMap) && !$generatorConfiguration->isImmutable()) {
            $schema->addMethod(
                'getValidatorPropertyMap',
                new RenderedMethod(
                    $schema,
                    $generatorConfiguration,
                    'GetValidatorPropertyMap.phptpl',
                    ['validatorPropertyMap' => var_export($validatorPropertyMap, true)]
                )
            );
        }

        return $validatorPropertyMap;
    }

    /**
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
     * @param array $validatorPropertyMap
     */
    private function addValidationMethods(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        array $validatorPropertyMap
    ): void {
        foreach (array_unique(array_merge(...array_values($validatorPropertyMap))) as $validatorIndex) {
            $schema->addMethod(
                "validateComposition_$validatorIndex",
                new RenderedMethod(
                    $schema,
                    $generatorConfiguration,
                    'CompositionValidation.phptpl',
                    [
                        'validator' => $schema->getBaseValidators()[$validatorIndex],
                        'index' => $validatorIndex,
                        'viewHelper' => new RenderHelper($generatorConfiguration),
                    ]
                )
            );
        }
    }

    /**
     * Add internal calls to validation methods to the setters which are part of a composition validation. The
     * validation methods will validate the state of all compositions when the value is updated.
     *
     * @param Schema $schema
     * @param array $validatorPropertyMap
     */
    private function addValidationCallsToSetterMethods(Schema $schema, array $validatorPropertyMap): void
    {
        $schema->addSchemaHook(new class ($validatorPropertyMap) implements SetterBeforeValidationHookInterface {
            protected $validatorPropertyMap;

            public function __construct(array $validatorPropertyMap)
            {
                $this->validatorPropertyMap = $validatorPropertyMap;
            }

            public function getCode(PropertyInterface $property): string
            {
                if (!isset($this->validatorPropertyMap[$property->getName()])) {
                    return '';
                }

                return join(
                    "\n",
                    array_map(
                        function ($validatorIndex) {
                            return sprintf('$this->validateComposition_%s($modelData);', $validatorIndex);
                        },
                        array_unique($this->validatorPropertyMap[$property->getName()])
                    )
                );
            }
        });
    }
}