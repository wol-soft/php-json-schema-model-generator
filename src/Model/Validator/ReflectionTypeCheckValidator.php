<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;
use ReflectionType;

/**
 * Class ReflectionTypeCheckValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class ReflectionTypeCheckValidator extends PropertyValidator
{
    public static function fromReflectionType(
        ReflectionType $reflectionType,
        PropertyInterface $property,
    ): self {
        return new self(
            $reflectionType->isBuiltin(),
            $reflectionType->getName(),
            $property,
        );
    }

    public static function fromType(
        string $type,
        PropertyInterface $property,
    ): self {
        return new self(
            in_array($type, ['int', 'float', 'string', 'bool', 'array', 'object', 'null']),
            $type,
            $property,
        );
    }

    /**
     * ReflectionTypeCheckValidator constructor.
     */
    public function __construct(bool $isBuiltin, string $name, PropertyInterface $property)
    {
        if ($isBuiltin) {
            $typeCheck = "!is_{$name}(\$value)";
        } else {
            $parts = explode('\\', $name);
            $className = end($parts);

            $typeCheck = "!(\$value instanceof $className)";
        }

        parent::__construct($property, $typeCheck, '');
    }
}
