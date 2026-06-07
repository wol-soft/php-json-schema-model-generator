<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\MethodInterface;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator;

class ComposedPropertyValidator extends AbstractComposedPropertyValidator
{
    private string $modifiedValuesMethod;
    private array $discriminatorInfo;

    public function __construct(
        GeneratorConfiguration $generatorConfiguration,
        PropertyInterface $property,
        array $composedProperties,
        string $compositionProcessor,
        string $exceptionClass,
        array $validatorVariables,
    ) {
        $this->modifiedValuesMethod = '_getModifiedValues_' . substr(md5(spl_object_hash($this)), 0, 5);
        $this->isResolved = true;
        $this->discriminatorInfo = $validatorVariables['discriminatorInfo'] ?? [];

        parent::__construct(
            $generatorConfiguration,
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ComposedItem.phptpl',
            array_merge($validatorVariables, ['modifiedValuesMethod' => $this->modifiedValuesMethod]),
            $exceptionClass,
            ['&$succeededCompositionElements', '&$compositionErrorCollection', '&$branchDescriptions', '&$discriminatorInfo'],
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
                public function __construct(
                    /** @var CompositionPropertyDecorator[] $compositionProperties */
                    private readonly array $compositionProperties,
                    private readonly string $modifiedValuesMethod
                ) {}

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

                    return sprintf(
                        '
                        private function %s(array $originalModelData, object $nestedCompositionObject): array {
                            $modifiedValues = [];
                            $defaultValueMap = %s;
    
                            foreach (%s as $key => $accessor) {
                                if ((isset($originalModelData[$key]) || in_array($key, $defaultValueMap))
                                    && method_exists($nestedCompositionObject, $accessor)
                                    && ($modifiedValue = $nestedCompositionObject->$accessor())
                                        !== ($originalModelData[$key] ?? !$modifiedValue)
                                ) {
                                    $modifiedValues[$key] = $modifiedValue;
                                }
                            }
    
                            return $modifiedValues;
                        }',
                        $this->modifiedValuesMethod,
                        var_export($defaultValueMap, true),
                        var_export($propertyAccessors, true),
                    );
                }
            },
        );

        return parent::getCheck();
    }

    /**
     * Initialize all variables which are required to execute a composed property validator
     */
    public function getValidatorSetUp(): string
    {
        $descriptions = [];
        foreach ($this->composedProperties as $prop) {
            $descriptions[] = $this->describeBranch($prop);
        }

        $discriminatorCode = $this->discriminatorInfo !== []
            ? '$discriminatorInfo = ' . var_export($this->discriminatorInfo, true) . ';'
            : '$discriminatorInfo = [];';

        return '
            $succeededCompositionElements = 0;
            $compositionErrorCollection = [];
            $branchDescriptions = ' . var_export($descriptions, true) . ';
            ' . $discriminatorCode . '
        ';
    }

    private function describeBranch(CompositionPropertyDecorator $prop): string
    {
        if ($prop->isAlwaysTrueBranch()) {
            return 'always-true (no constraint)';
        }

        $parts = [];

        $type = $prop->getType();
        if ($type !== null) {
            $typeNames = $type->getNames();
            if ($typeNames !== []) {
                $parts[] = 'type: ' . implode(' | ', $typeNames);
            }
        }

        $nestedSchema = $prop->getNestedSchema();
        if ($nestedSchema !== null) {
            $propNames = array_map(
                static fn (PropertyInterface $p): string => $p->getName(),
                $nestedSchema->getProperties(),
            );
            if ($propNames !== []) {
                $parts[] = 'properties: [' . implode(', ', $propNames) . ']';
            }
        }

        return implode(', ', $parts);
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
                $composedProperty->filterValidators(
                    static fn(Validator $validator): bool =>
                        !is_a($validator->getValidator(), AbstractComposedPropertyValidator::class)
                );
            });
        }

        return $validator;
    }
}
