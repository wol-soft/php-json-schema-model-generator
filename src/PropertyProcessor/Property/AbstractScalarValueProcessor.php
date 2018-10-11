<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Model\Property;
use PHPModelGenerator\Model\Validator\PropertyValidator;

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

    /**
     * @inheritdoc
     */
    protected function generateValidators(Property $property, array $propertyData): void
    {
        parent::generateValidators($property, $propertyData);

        $property->addValidator(
            new PropertyValidator(
                '!is_' . strtolower(static::TYPE) . '($value)' . ($property->isRequired() ? '' : ' && $value !== null'),
                InvalidArgumentException::class,
                "invalid type for {$property->getName()}"
            )
        );
    }
}
