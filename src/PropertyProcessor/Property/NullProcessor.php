<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class NullProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class NullProcessor extends AbstractTypedValueProcessor
{
    protected const TYPE = 'null';

    /**
     * Explicitly unset the type of the property
     *
     * @param string $propertyName
     * @param array $propertyData
     * @return PropertyInterface
     */
    public function process(string $propertyName, array $propertyData): PropertyInterface
    {
        return (parent::process($propertyName, $propertyData))->setType('');
    }
}
