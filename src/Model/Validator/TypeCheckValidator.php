<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;

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
     * @param string            $type
     * @param PropertyInterface $property
     */
    public function __construct(string $type, PropertyInterface $property)
    {
            parent::__construct(
                '!is_' . strtolower($type) . '($value)' . ($property->isRequired() ? '' : ' && $value !== null'),
                "invalid type for {$property->getName()}"
            );
    }
}
