<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class AnyProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class AnyProcessor extends AbstractValueProcessor
{
    /**
     * @param string $propertyName
     * @param array  $propertyData
     *
     * @return PropertyInterface
     */
    public function process(string $propertyName, array $propertyData): PropertyInterface
    {
        $property = parent::process($propertyName, $propertyData);

        if (isset($propertyData['default'])) {
            $property->setDefaultValue($propertyData['default']);
        }

        return $property;
    }
}
