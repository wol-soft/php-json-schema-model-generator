<?php

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\PropertyTemplateValidator;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class AbstractComposedValueProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
abstract class AbstractComposedValueProcessor extends AbstractNestedValueProcessor
{
    protected const COMPOSED_VALUE_VALIDATION = '';

    /**
     * @inheritdoc
     */
    protected function generateValidators(PropertyInterface $property, array $propertyData): void
    {
        $propertyFactory = new PropertyFactory();

        $properties = [];
        foreach ($propertyData as $compositionElement) {
            $properties[] = $propertyFactory
                ->create(
                    new PropertyCollectionProcessor(),
                    $this->schemaProcessor,
                    $this->schema,
                    $property->getName(),
                    $compositionElement
                )
                ->setRequired($property->isRequired());
        }

        $property->addValidator(
            new PropertyTemplateValidator(
                InvalidArgumentException::class,
                'Invalid composed item',
                DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ComposedItem.phptpl',
                [
                    'properties' => $properties,
                    'viewHelper' => new RenderHelper(),
                    'availableAmount' => count($properties),
                    'composedValueValidation' => static::COMPOSED_VALUE_VALIDATION,
                ]
            )
        );
    }
}
