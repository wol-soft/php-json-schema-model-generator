<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Model\Property;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;

/**
 * Class AbstractScalarValueProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
abstract class AbstractTypedValueProcessor extends AbstractValueProcessor
{
    protected const TYPE = '';

    /**
     * AbstractTypedValueProcessor constructor.
     *
     * @param PropertyCollectionProcessor $propertyCollectionProcessor
     */
    public function __construct(PropertyCollectionProcessor $propertyCollectionProcessor)
    {
        parent::__construct($propertyCollectionProcessor, static::TYPE);
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
            ),
            2
        );
    }
}
