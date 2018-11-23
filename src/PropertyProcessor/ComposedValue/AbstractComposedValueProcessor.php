<?php

namespace PHPModelGenerator\PropertyProcessor\ComposedValue;

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
            $compositionProperty = $propertyFactory
                ->create(
                    new PropertyCollectionProcessor([$property->getName() => $property->isRequired()]),
                    $this->schemaProcessor,
                    $this->schema,
                    $property->getName(),
                    $compositionElement
                );

            $compositionProperty->filterValidators(function (Validator $validator) {
                return !is_a($validator->getValidator(), RequiredPropertyValidator::class);
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
                    'property' => $property,
                    'viewHelper' => new RenderHelper(),
                    'availableAmount' => $availableAmount,
                    'composedValueValidation' => $this->getComposedValueValidation($availableAmount),
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
    abstract function getComposedValueValidation(int $composedElements): string;
}
