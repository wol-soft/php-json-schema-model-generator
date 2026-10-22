<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Object\MaxPropertiesException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\Factory\Object\MinPropertiesValidatorFactory;

class MaxPropertiesValidator extends PropertyValidator implements ObjectUpdateDependentValidatorInterface
{
    public function __construct(PropertyInterface $property, int $maxProperties)
    {
        parent::__construct(
            $property,
            sprintf('%s > %d', MinPropertiesValidatorFactory::COUNT_PROPERTIES, $maxProperties),
            MaxPropertiesException::class,
            [$maxProperties, '&$count'],
        );
    }

    public function getObjectUpdateDependency(string $key): ObjectUpdateDependency
    {
        return ObjectUpdateDependency::AddedKey;
    }
}
