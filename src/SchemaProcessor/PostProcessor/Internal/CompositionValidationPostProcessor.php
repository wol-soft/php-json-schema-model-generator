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
                // Every schema-level composition branch has a nested schema at this point:
                // SchemaProcessor::transferComposedPropertiesToSchema() throws SchemaException
                // for any branch that lacks one, and inheritPropertyType() forces branches to
                // adopt the parent's object type so PropertyFactory routes them through
                // createObjectProperty(). The branch's declared properties are thus visible
                // via getNestedSchema()->getProperties(), covering setter-side revalidation
                // for inline oneOf/anyOf/if-then-else discriminators without a separate
                // harvest path.
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
    }
}
