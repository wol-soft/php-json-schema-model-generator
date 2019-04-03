<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\ComposedValue;

use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\PropertyProcessor\Property\AbstractTypedValueProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class AbstractComposedValueProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\ComposedValue
 */
abstract class AbstractComposedValueProcessor extends AbstractTypedValueProcessor
{
    /**
     * @inheritdoc
     */
    protected function generateValidators(PropertyInterface $property, array $propertyData): void
    {
        $propertyFactory = new PropertyFactory(new PropertyProcessorFactory());

        $properties = [];

        foreach ($propertyData['composition'] as $compositionElement) {
            $compositionProperty = new CompositionPropertyDecorator(
                $propertyFactory
                    ->create(
                        new PropertyCollectionProcessor([$property->getName() => $property->isRequired()]),
                        $this->schemaProcessor,
                        $this->schema,
                        $property->getName(),
                        $compositionElement
                    )
            );

            $compositionProperty->filterValidators(function (Validator $validator) {
                return !is_a($validator->getValidator(), RequiredPropertyValidator::class) &&
                    !is_a($validator->getValidator(), ComposedPropertyValidator::class);
            });

            $properties[] = $compositionProperty;
        }

        $availableAmount = count($properties);

        $property->addValidator(
            new ComposedPropertyValidator(
                $property,
                $properties,
                static::class,
                [
                    'properties' => $properties,
                    'viewHelper' => new RenderHelper(),
                    'availableAmount' => $availableAmount,
                    'composedValueValidation' => $this->getComposedValueValidation($availableAmount),
                    'onlyForDefinedValues' => $propertyData['onlyForDefinedValues'] &&
                        $this instanceof AbstractComposedPropertiesProcessor,
                ]
            ),
            100
        );
    }

    /**
     * @param int $composedElements The amount of elements which are composed together
     *
     * @return string
     */
    abstract protected function getComposedValueValidation(int $composedElements): string;
}
