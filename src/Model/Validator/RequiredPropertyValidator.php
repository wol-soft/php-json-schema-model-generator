<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Object\RequiredValueException;
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
        parent::__construct($property, '', RequiredValueException::class);
    }

    public function getCheck(): string
    {
        return "!array_key_exists('{$this->property->getName()}', \$modelData)";
    }
}
