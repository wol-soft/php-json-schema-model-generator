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
    protected const JSON_FIELD_MINIMUM = 'minimum';
    protected const JSON_FIELD_MAXIMUM = 'maximum';

    /**
     * @inheritdoc
     */
    protected function generateValidators(Property $property, array $propertyData): void
    {
        parent::generateValidators($property, $propertyData);

        $limitMessage = "Value for %s must not be %s than %s";

        if (isset($propertyData[self::JSON_FIELD_MINIMUM])) {
            $property->addValidator(
                new PropertyValidator(
                    "\$value < {$propertyData[self::JSON_FIELD_MINIMUM]}",
                    InvalidArgumentException::class,
                    sprintf($limitMessage, $property->getName(), 'smaller', $propertyData[self::JSON_FIELD_MINIMUM])
                )
            );
        }

        if (isset($propertyData[self::JSON_FIELD_MAXIMUM])) {
            $property->addValidator(
                new PropertyValidator(
                    "\$value > {$propertyData[self::JSON_FIELD_MAXIMUM]}",
                    InvalidArgumentException::class,
                    sprintf($limitMessage, $property->getName(), 'greater', $propertyData[self::JSON_FIELD_MAXIMUM])
                )
            );
        }
    }
}
