<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\ComposedValue;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\ConditionalPropertyValidator;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\PropertyProcessor\Property\AbstractValueProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class IfProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\ComposedValue
 */
class IfProcessor extends AbstractValueProcessor implements ComposedPropertiesInterface
{
    /**
     * @inheritdoc
     */
    protected function generateValidators(PropertyInterface $property, array $propertyData): void
    {
        if (!isset($propertyData['propertyData']['then']) && !isset($propertyData['propertyData']['else'])) {
            throw new SchemaException('Incomplete conditional composition');
        }

        $propertyFactory = new PropertyFactory(new PropertyProcessorFactory());

        $properties = [];

        foreach (['if', 'then', 'else'] as $compositionElement) {
            if (!isset($propertyData['propertyData'][$compositionElement])) {
                $properties[$compositionElement] = null;
                continue;
            }

            $compositionProperty = new CompositionPropertyDecorator(
                $propertyFactory
                    ->create(
                        new PropertyCollectionProcessor([$property->getName() => $property->isRequired()]),
                        $this->schemaProcessor,
                        $this->schema,
                        $property->getName(),
                        $propertyData['propertyData'][$compositionElement]
                    )
            );

            $compositionProperty->filterValidators(function (Validator $validator) {
                return !is_a($validator->getValidator(), RequiredPropertyValidator::class) &&
                    !is_a($validator->getValidator(), ComposedPropertyValidator::class);
            });

            $properties[$compositionElement] = $compositionProperty;
        }

        $property->addValidator(
            new ConditionalPropertyValidator(
                $property,
                $properties,
                [
                    'ifProperty' => $properties['if'],
                    'thenProperty' => $properties['then'],
                    'elseProperty' => $properties['else'],
                    'viewHelper' => new RenderHelper(),
                    'onlyForDefinedValues' => $propertyData['onlyForDefinedValues'],
                ]
            ),
            100
        );

        parent::generateValidators($property, $propertyData);
    }
}
