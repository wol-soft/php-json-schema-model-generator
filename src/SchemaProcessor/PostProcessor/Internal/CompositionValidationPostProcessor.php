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

        [$validatorPropertyMap, $allPropertiesValidatorIndices] = $this->generateValidatorPropertyMap($schema);

        $this->addValidationMethods($schema, $generatorConfiguration, $compositionValidatorKeys);

        // if the generator is immutable no validation on value updates are required
        if (
            $generatorConfiguration->isImmutable()
            || (empty($validatorPropertyMap) && empty($allPropertiesValidatorIndices))
        ) {
            return;
        }

        $this->addValidationCallsToSetterMethods($schema, $validatorPropertyMap, $allPropertiesValidatorIndices);
    }

    /**
     * Set up a map containing the properties and the corresponding composition validators which must be checked when
     * the property is updated
     *
     * @return array{array<string, int[]>, int[]} The validators per property declared in a branch, and the validators
     *         which must be checked when any property is updated
     */
    private function generateValidatorPropertyMap(Schema $schema): array
    {
        $validatorPropertyMap = [];
        $allPropertiesValidatorIndices = [];

        // get all base validators which are composed value validators and set up a map of affected object properties
        foreach ($schema->getBaseValidators() as $validatorIndex => $validator) {
            if (!is_a($validator, AbstractComposedPropertyValidator::class)) {
                continue;
            }

            // A branch which depends on keys it doesn't declare (e.g. maxProperties) may change its result with an
            // update of any property, so each setter must validate the composition again. Mapping only the
            // properties declared by the branches was rejected: an update of another property would be accepted
            // although the constructor rejects the resulting data. The setters are resolved when they are rendered
            // (see addValidationCallsToSetterMethods), not here, to cover properties added by later post processors.
            if ($validator->dependsOnAllObjectProperties()) {
                $allPropertiesValidatorIndices[] = $validatorIndex;

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

        if (!empty($validatorPropertyMap) || !empty($allPropertiesValidatorIndices)) {
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
                                array_merge($allPropertiesValidatorIndices, ...array_values($validatorPropertyMap)),
                            ),
                            [],
                        )
                    ),
            );
        }

        return [$validatorPropertyMap, $allPropertiesValidatorIndices];
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
    private function addValidationCallsToSetterMethods(
        Schema $schema,
        array $validatorPropertyMap,
        array $allPropertiesValidatorIndices,
    ): void {
        $schema->addSchemaHook(new class (
            $schema,
            $validatorPropertyMap,
            $allPropertiesValidatorIndices,
        ) implements SetterBeforeValidationHookInterface {
            public function __construct(
                protected Schema $schema,
                protected array $validatorPropertyMap,
                protected array $allPropertiesValidatorIndices,
            ) {}

            public function getCode(PropertyInterface $property, bool $batchUpdate = false): string
            {
                // A batch update (populate) executes all base validators, the compositions included, for the whole
                // update. Validating the compositions again per updated property is redundant and, with collected
                // errors, reports each failing composition a second time.
                if ($batchUpdate) {
                    return '';
                }

                $validatorIndices = $this->validatorPropertyMap[$property->getName()] ?? [];

                // Only properties of the schema itself: the hook is also resolved for the validation property of
                // additional properties, whose update already executes all base validators.
                if (!$property->isInternal() && $this->schema->getProperty($property->getName()) === $property) {
                    $validatorIndices = array_merge($validatorIndices, $this->allPropertiesValidatorIndices);
                }

                $validatorIndices = array_unique($validatorIndices);
                sort($validatorIndices);

                return join(
                    "\n",
                    array_map(
                        static fn(int $validatorIndex): string =>
                            sprintf('$this->_validateComposition_%s($modelData);', $validatorIndex),
                        $validatorIndices,
                    )
                );
            }
        });
    }
}
