<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use InvalidArgumentException;
use PHPModelGenerator\Model\Property;
use PHPModelGenerator\Model\PropertyValidator;

/**
 * Class AbstractNumericProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
abstract class AbstractNumericProcessor extends AbstractScalarValueProcessor
{
    /**
     * @inheritdoc
     */
    protected function generateValidators(Property $property, array $propertyData): void
    {
        parent::generateValidators($property, $propertyData);

        if (isset($propertyData['minimum'])) {
            $property->addValidator(
                new PropertyValidator(
                    "\$value < {$propertyData['minimum']}",
                    InvalidArgumentException::class,
                    "Value for {$property->getName()} must not be smaller than {$propertyData['minimum']}"
                )
            );
        }

        if (isset($propertyData['maximum'])) {
            $property->addValidator(
                new PropertyValidator(
                    "\$value > {$propertyData['maximum']}",
                    InvalidArgumentException::class,
                    "Value for {$property->getName()} must not be greater than {$propertyData['maximum']}"
                )
            );
        }
    }
}
