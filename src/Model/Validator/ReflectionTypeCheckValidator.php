<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\TypeCheck;

/**
 * Class ReflectionTypeCheckValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class ReflectionTypeCheckValidator extends PropertyValidator
{
    public static function fromType(
        string $type,
        PropertyInterface $property,
    ): self {
        return new self($type, $property);
    }

    /**
     * ReflectionTypeCheckValidator constructor.
     */
    public function __construct(string $name, PropertyInterface $property)
    {
        $typeCheck = TypeCheck::buildNegatedCompound([$name]);

        parent::__construct($property, $typeCheck, '');
    }
}
