<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Model\Property;

/**
 * Class AbstractScalarValueProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
abstract class AbstractScalarValueProcessor extends AbstractPropertyProcessor
{
    protected const TYPE = '';

    /**
     * @inheritdoc
     */
    public function process(string $propertyName, array $propertyData): Property
    {
        $property = new Property($propertyName, static::TYPE);

        $this->generateValidators($property, $propertyData);

        return $property;
    }
}
