<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model;

use Exception;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;

/**
 * Class Schema
 *
 * @package PHPModelGenerator\Model
 */
class Schema
{
    /** @var Property[] The properties which are part of the class */
    protected $properties = [];
    /** @var PropertyValidator[] A Collection of validators which must be applied
     *                           before adding properties to the model
     */
    protected $baseValidators = [];

    /**
     * @return Property[]
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * @param Property $property
     */
    public function addProperty(Property $property)
    {
        $this->properties[] = $property;
    }

    /**
     * @return PropertyValidator[]
     */
    public function getBaseValidators(): array
    {
        return $this->baseValidators;
    }

    /**
     * @param PropertyValidatorInterface $baseValidator
     */
    public function addBaseValidator(PropertyValidatorInterface $baseValidator)
    {
        $this->baseValidators[] = $baseValidator;
    }

    /**
     * Extract all required uses for the properties of the schema
     *
     * @param bool $skipGlobalNamespace
     *
     * @return array
     */
    public function getUseList(bool $skipGlobalNamespace): array
    {
        $use = [];

        foreach ($this->getProperties() as $property) {
            if (empty($property->getValidators())) {
                continue;
            }

            $use = array_merge($use, [Exception::class], $property->getClasses());
        }

        if ($skipGlobalNamespace) {
            $use = array_filter($use, function ($namespace) {
                return strstr($namespace, '\\');
            });
        }

        return $use;
    }
}
