<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Object\MinPropertiesException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\Factory\Object\MinPropertiesValidatorFactory;

class MinPropertiesValidator extends PropertyValidator implements ObjectUpdateDependentValidatorInterface
{
    public function __construct(PropertyInterface $property, int $minProperties)
    {
        parent::__construct(
            $property,
            sprintf('%s < %d', MinPropertiesValidatorFactory::COUNT_PROPERTIES, $minProperties),
            MinPropertiesException::class,
            [$minProperties, '&$count'],
        );
    }

    /**
     * Adding a key may satisfy minProperties (relevant for the cached result of a composition branch), an update
     * never removes a key.
     */
    public function getObjectUpdateDependency(string $key): ObjectUpdateDependency
    {
        return ObjectUpdateDependency::AddedKey;
    }

    public function getUndeclaredKeyDependency(): ObjectUpdateDependency
    {
        return ObjectUpdateDependency::AddedKey;
    }
}
