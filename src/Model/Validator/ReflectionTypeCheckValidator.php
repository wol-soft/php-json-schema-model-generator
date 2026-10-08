<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\TypeCheck;

class ReflectionTypeCheckValidator extends PropertyValidator
{
    public static function fromType(
        string $type,
        PropertyInterface $property,
        bool $treatObjectAsUninstantiatedShape = false,
    ): self {
        return new self($type, $property, $treatObjectAsUninstantiatedShape);
    }

    public function __construct(
        string $name,
        PropertyInterface $property,
        bool $treatObjectAsUninstantiatedShape = false,
    ) {
        $typeCheck = TypeCheck::buildNegatedJsonSchemaTypeCheck($name, $treatObjectAsUninstantiatedShape);

        parent::__construct($property, $typeCheck, '');
    }
}
