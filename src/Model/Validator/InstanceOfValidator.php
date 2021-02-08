<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Object\InvalidInstanceOfException;
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
            $property,
            sprintf(
                'is_object($value) && !($value instanceof \Exception) && !($value instanceof %s)',
                $property->getType()->getName()
            ),
            InvalidInstanceOfException::class,
            [$property->getType()->getName()]
        );
    }
}
