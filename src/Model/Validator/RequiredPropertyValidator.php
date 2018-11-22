<?php

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class RequiredPropertyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class RequiredPropertyValidator extends PropertyValidator
{
    /**
     * RequiredPropertyValidator constructor.
     *
     * @param PropertyInterface $property
     */
    public function __construct(PropertyInterface $property)
    {
        parent::__construct(
            "!isset(\$modelData['{$property->getName()}'])",
            InvalidArgumentException::class,
            "Missing required value for {$property->getName()}"
        );
    }
}
