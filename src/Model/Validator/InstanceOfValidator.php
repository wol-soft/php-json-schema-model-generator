<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class InstanceOfValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class InstanceOfValidator extends PropertyValidator
{
    /**
     * InstanceOfValidator constructor.
     *
     * @param PropertyInterface $property
     */
    public function __construct(PropertyInterface $property)
    {
        parent::__construct(
            sprintf('is_object($value) && !($value instanceof %s)', $property->getType()),
            sprintf(
                'Invalid class for %s. Requires %s, got " . get_class($value) . "',
                $property->getName(),
                $property->getType()
            )
        );
    }
}
