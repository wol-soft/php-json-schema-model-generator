<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

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
            "Missing required value for {$property->getName()}"
        );
    }
}
