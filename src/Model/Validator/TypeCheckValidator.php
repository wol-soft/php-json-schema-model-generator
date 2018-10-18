<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Model\Property;

/**
 * Class TypeCheckValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class TypeCheckValidator extends PropertyValidator
{
    /**
     * TypeCheckValidator constructor.
     *
     * @param string   $type
     * @param Property $property
     */
    public function __construct(string $type, Property $property)
    {
            parent::__construct(
                '!is_' . strtolower($type) . '($value)' . ($property->isRequired() ? '' : ' && $value !== null'),
                InvalidArgumentException::class,
                "invalid type for {$property->getName()}"
            );
    }
}
