<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\ModelUpdate;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\ObjectUpdateDependency;
use PHPModelGenerator\SchemaProcessor\Hook\SchemaHookResolver;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Renders the validation of the setters of a model like the constructor validates the data. Only the validation of
 * the object (object-level validators, compositions, dependencies of other properties) and before validation hook
 * code change the model state before the update is accepted - the validators of the property itself never write the
 * model state -, so only a setter executing such code is wrapped into the model state rollback.
 *
 * The setter of a key which is already stored only executes the validation which depends on the value of the key:
 * changing the value of a present key can't change the result of e.g. maxProperties. Snapshotting and validating the
 * object on every call was rejected: it made the setters of a model with such a constraint (e.g. every composition
 * branch with denied additional properties) expensive although most updates change present values.
 */
class SetterValidationRenderer
{
    private readonly ModelStateRollback $modelStateRollback;
    private readonly DependencyValidation $dependencyValidation;

    public function __construct(
        private readonly Schema $schema,
        private readonly GeneratorConfiguration $generatorConfiguration,
        private readonly SchemaHookResolver $schemaHookResolver,
    ) {
        $this->modelStateRollback = new ModelStateRollback($generatorConfiguration);
        $this->dependencyValidation = new DependencyValidation($generatorConfiguration);
    }

    public function render(PropertyInterface $property): string
    {
        $hookCode = $this->schemaHookResolver->resolveSetterBeforeValidationHook($property);
        $addedKeyCode = $this->renderUpdateValidation($property, $hookCode, true);
        $presentKeyCode = $this->renderUpdateValidation($property, $hookCode, false);

        // a path which doesn't validate a composition doesn't stage filtered values of an active branch
        $initialisation = $this->stagesBranchFilteredValues($property)
            ? "\$preparedBranchFilteredValues = null;\n\n"
            : '';

        if ($addedKeyCode === $presentKeyCode) {
            return $initialisation . $addedKeyCode;
        }

        $key = var_export($property->getName(), true);
        $addedKeyCode = RenderHelper::indent($addedKeyCode, 1);
        $presentKeyCode = RenderHelper::indent($presentKeyCode, 1);

        return $initialisation . <<<PHP
            if (!array_key_exists($key, \$this->_rawModelDataInput)) {
                $addedKeyCode
            } else {
                $presentKeyCode
            }
            PHP;
    }

    /**
     * Adopts the filtered values the active branches of the compositions staged during the setter once the update
     * succeeded (see render()).
     */
    public function renderBranchFilteredValueApplication(PropertyInterface $property): string
    {
        return $this->stagesBranchFilteredValues($property)
            ? '$this->_applyBranchFilteredValues($preparedBranchFilteredValues);'
            : '';
    }

    /**
     * Whether the setter of the property validates more than the property itself
     */
    public function hasObjectValidation(PropertyInterface $property): bool
    {
        // adding the key validates everything changing the value of a present key validates
        return array_filter($this->getObjectValidation($property, true)) !== [];
    }

    /**
     * Whether the setter validates a composition which hands over filtered values of its active branch
     */
    private function stagesBranchFilteredValues(PropertyInterface $property): bool
    {
        // adding the key validates every composition changing the value of a present key validates
        return $this->schema->hasMethod('_prepareBranchFilteredValues')
            && $this->getObjectValidation($property, true)[1] !== '';
    }

    private function renderUpdateValidation(PropertyInterface $property, string $hookCode, bool $keyAdded): string
    {
        [$objectCode, $compositionCode, $dependencyCode] = $this->getObjectValidation($property, $keyAdded);

        // the order of the constructor: object validators, compositions, properties
        $code = join("\n\n", array_filter([
            $objectCode,
            $compositionCode,
            $hookCode,
            $dependencyCode,
            sprintf('$value = $this->_validate%s($value, $modelData);', ucfirst($property->getAttribute())),
            $this->generatorConfiguration->collectErrors()
                ? "if (\$this->_errorRegistry->getErrors()) {\n    throw \$this->_errorRegistry;\n}"
                : '',
        ]));

        return $objectCode . $compositionCode . $hookCode . $dependencyCode === ''
            ? $code
            : $this->modelStateRollback->wrap($this->schema, $code);
    }

    /**
     * The validation of the object an update of the property requires, like the constructor validates the data:
     * - the object-level validators (e.g. maxProperties). Only the updated key is passed: the validators which count
     *   the properties include the stored data themselves, the others only validate the provided keys.
     * - the compositions of the schema, which evaluate their branches again as far as the update may change them
     * - the dependencies of the other properties (a schema dependency validates the whole object), the dependencies
     *   of the property itself are validated with the property
     *
     * Executing the object validators in every setter was rejected: the setter of a declared property can't violate
     * e.g. additionalProperties, every setter of such a common model would validate and snapshot the model state for
     * nothing.
     *
     * @return string[] The code for the object-level validators, the compositions and the dependencies
     */
    private function getObjectValidation(PropertyInterface $property, bool $keyAdded): array
    {
        $key = $property->getName();

        $objectCode = ObjectUpdateDependency::ofValidators($this->schema->getObjectValidators(), $key)
            ->requiresValidation($keyAdded)
            // a copy: the validators must not change the value of the setter
            ? "\$objectModelData = \$modelData;\n\$this->_executeObjectValidators(\$objectModelData);"
            : '';

        $compositionCalls = [];
        foreach ($this->schema->getCompositionValidatorKeys() as $validatorIndex) {
            /** @var AbstractComposedPropertyValidator $compositionValidator */
            $compositionValidator = $this->schema->getBaseValidators()[$validatorIndex];

            if ($compositionValidator->getObjectUpdateDependency($key)->requiresValidation($keyAdded)) {
                $compositionCalls[] = sprintf('$this->_validateComposition_%s($modelData);', $validatorIndex);
            }
        }

        // The compositions stage the filtered values of their active branches: the setter starts with an empty
        // staging and prepares the values before anything is assigned (a rejected dependent property rejects the
        // update), they are applied once the update succeeded (see renderBranchFilteredValueApplication()).
        if ($compositionCalls !== [] && $this->schema->hasMethod('_prepareBranchFilteredValues')) {
            array_unshift($compositionCalls, '$this->_stagedBranchFilteredValues = [];');
            $compositionCalls[] = sprintf(
                '$preparedBranchFilteredValues = $this->_prepareBranchFilteredValues(%s, %s);',
                RenderHelper::varExportArray([$property->getAttribute(true)]),
                'array_merge($this->_rawModelDataInput, $modelData)',
            );
        }

        $dependency = ObjectUpdateDependency::None;
        foreach ($this->schema->getDependencyValidators() as $propertyName => $validators) {
            if ($propertyName !== $key) {
                $dependency = $dependency->strongest(ObjectUpdateDependency::ofValidators($validators, $key));
            }
        }

        $dependencyCode = '';
        if ($dependency->requiresValidation($keyAdded)) {
            $this->dependencyValidation->addMethod($this->schema);

            $dependencyCode = sprintf(
                '$this->_executeDependencyValidators(array_merge($this->_rawModelDataInput, $modelData), [%s]);',
                var_export($key, true),
            );
        }

        return [$objectCode, join("\n", $compositionCalls), $dependencyCode];
    }
}
