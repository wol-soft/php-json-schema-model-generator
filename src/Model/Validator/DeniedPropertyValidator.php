<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Generic\DeniedPropertyException;
use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Rejects the presence of a property declared with the schema false
 */
class DeniedPropertyValidator extends PropertyValidator implements ObjectUpdateDependentValidatorInterface
{
    public function __construct(PropertyInterface $property)
    {
        parent::__construct(
            $property,
            "array_key_exists('" . addslashes($property->getName()) . "', \$modelData)",
            DeniedPropertyException::class,
        );
    }

    public function getObjectUpdateDependency(string $key): ObjectUpdateDependency
    {
        return $key === $this->property->getName() ? ObjectUpdateDependency::AddedKey : ObjectUpdateDependency::None;
    }
}
