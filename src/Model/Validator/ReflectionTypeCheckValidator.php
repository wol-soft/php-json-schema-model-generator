<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use ReflectionType;

/**
 * Class ReflectionTypeCheckValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class ReflectionTypeCheckValidator extends PropertyValidator
{
    /**
     * ReflectionTypeCheckValidator constructor.
     *
     * @param ReflectionType $reflectionType
     * @param PropertyInterface $property
     * @param Schema $schema
     */
    public function __construct(ReflectionType $reflectionType, PropertyInterface $property, Schema $schema)
    {
        if ($reflectionType->isBuiltin()) {
            $skipTransformedValuesCheck = "!is_{$reflectionType->getName()}(\$value)";
        } else {
            $skipTransformedValuesCheck = "!(\$value instanceof {$reflectionType->getName()})";
            // make sure the returned class is imported so the instanceof check can be performed
            $schema->addUsedClass($reflectionType->getName());
        }

        parent::__construct(
            $skipTransformedValuesCheck,
            sprintf(
                'Invalid type for %s. Requires %s, got " . gettype($value) . "',
                $property->getName(),
                $reflectionType->getName()
            )
        );
    }
}
