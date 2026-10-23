<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor\Internal;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\RenderedMethod;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Adds methods to models which require composition validations on object level to validate the compositions, and the
 * state of the compositions of mutable models. The setters execute the validation methods of the compositions an
 * update of the property may change (see RenderHelper::renderSetterValidation()).
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

        $this->addValidationMethods($schema, $generatorConfiguration, $compositionValidatorKeys);

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
}
