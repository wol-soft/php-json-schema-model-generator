<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\ComposedValue\InvalidComposedValueException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\MethodInterface;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator;

/**
 * Class ComposedPropertyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class ComposedPropertyValidator extends AbstractComposedPropertyValidator
{
    private $modifiedValuesMethod;

    public function __construct(
        GeneratorConfiguration $generatorConfiguration,
        PropertyInterface $property,
        array $composedProperties,
        string $compositionProcessor,
        array $validatorVariables
    ) {
        $this->modifiedValuesMethod = '_getModifiedValues_' . substr(md5(spl_object_hash($this)), 0, 5);
        $this->isResolved = true;

        parent::__construct(
            $generatorConfiguration,
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ComposedItem.phptpl',
            array_merge($validatorVariables, ['modifiedValuesMethod' => $this->modifiedValuesMethod]),
            $this->getExceptionByProcessor($compositionProcessor),
            ['&$succeededCompositionElements', '&$compositionErrorCollection']
        );

        $this->compositionProcessor = $compositionProcessor;
        $this->composedProperties = $composedProperties;
    }

    /**
     * TODO: add method only if nested objects contain filter (else also skip method call)
     */
    public function getCheck(): string
    {
        /**
         * Add a method to the schema to gather values from a nested object which are modified.
         * This is required to adopt filter changes to the values which are passed into a merged property
         */
        $this->scope->addMethod(
            $this->modifiedValuesMethod,
            new class ($this->composedProperties, $this->modifiedValuesMethod) implements MethodInterface {
                /** @var CompositionPropertyDecorator[] $compositionProperties */
                private $compositionProperties;
                /** @var string */
                private $modifiedValuesMethod;

                public function __construct(array $compositionProperties, string $modifiedValuesMethod)
                {
                    $this->compositionProperties = $compositionProperties;
                    $this->modifiedValuesMethod = $modifiedValuesMethod;
                }

                public function getCode(): string
                {
                    $defaultValueMap = [];
                    $propertyAccessors = [];
                    foreach ($this->compositionProperties as $compositionProperty) {
                        if (!$compositionProperty->getNestedSchema()) {
                            continue;
                        }

                        foreach ($compositionProperty->getNestedSchema()->getProperties() as $property) {
                            $propertyAccessors[$property->getName()] = 'get' . ucfirst($property->getAttribute());

                            if ($property->getDefaultValue() !== null) {
                                $defaultValueMap[] = $property->getName();
                            }
                        }
                    }

                    return sprintf('
                        private function %s(array $originalModelData, object $nestedCompositionObject): array {
                            $modifiedValues = [];
                            $defaultValueMap = %s;
    
                            foreach (%s as $key => $accessor) {
                                if ((isset($originalModelData[$key]) || in_array($key, $defaultValueMap))
                                    && method_exists($nestedCompositionObject, $accessor)
                                    && ($modifiedValue = $nestedCompositionObject->$accessor()) !== ($originalModelData[$key] ?? !$modifiedValue)
                                ) {
                                    $modifiedValues[$key] = $modifiedValue;
                                }
                            }
    
                            return $modifiedValues;
                        }',
                        $this->modifiedValuesMethod,
                        var_export($defaultValueMap, true),
                        var_export($propertyAccessors, true)
                    );
                }
            }
        );

        return parent::getCheck();
    }

    /**
     * Initialize all variables which are required to execute a composed property validator
     *
     * @return string
     */
    public function getValidatorSetUp(): string
    {
        return '
            $succeededCompositionElements = 0;
            $compositionErrorCollection = [];
        ';
    }

    /**
     * Creates a copy of the validator and strips all nested composition validations from the composed properties.
     * See usage in BaseProcessor for more details why the nested validators can be filtered out.
     *
     * @return $this
     */
    public function withoutNestedCompositionValidation(): self
    {
        $validator = clone $this;

        /** @var CompositionPropertyDecorator $composedProperty */
        foreach ($validator->composedProperties as $composedProperty) {
            $composedProperty->onResolve(static function () use ($composedProperty): void {
                $composedProperty->filterValidators(static function (Validator $validator): bool {
                    return !is_a($validator->getValidator(), AbstractComposedPropertyValidator::class);
                });
            });
        }

        return $validator;
    }

    /**
     * Parse the composition type (allOf, anyOf, ...) from the given processor and get the corresponding exception class
     *
     * @param string $compositionProcessor
     *
     * @return string
     */
    private function getExceptionByProcessor(string $compositionProcessor): string
    {
        return str_replace(
                DIRECTORY_SEPARATOR,
                '\\',
                dirname(str_replace('\\', DIRECTORY_SEPARATOR, InvalidComposedValueException::class))
            ) . '\\' . str_replace(
                'Processor',
                '',
                substr($compositionProcessor, strrpos($compositionProcessor, '\\') + 1)
            ) . 'Exception';
    }
}
