<?php

declare(strict_types = 1);

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
            "!array_key_exists('{$property->getName()}', \$modelData)",
            InvalidArgumentException::class,
            "Missing required value for {$property->getName()}"
        );
    }
}
