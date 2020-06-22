<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Generic\MissingRequiredValueException;
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
            MissingRequiredValueException::class,
            [$property->getName()]
        );
    }
}
