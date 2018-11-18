<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorInterface;

/**
 * Class AbstractPropertyProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
abstract class AbstractPropertyProcessor implements PropertyProcessorInterface
{
    /** @var PropertyCollectionProcessor */
    protected $propertyCollectionProcessor;

    /**
     * AbstractPropertyProcessor constructor.
     *
     * @param PropertyCollectionProcessor $propertyCollectionProcessor
     */
    public function __construct(PropertyCollectionProcessor $propertyCollectionProcessor)
    {
        $this->propertyCollectionProcessor = $propertyCollectionProcessor;
    }

    /**
     * Generates the validators for the property
     *
     * @param PropertyInterface $property
     * @param array             $propertyData
     */
    protected function generateValidators(PropertyInterface $property, array $propertyData): void
    {
        if ($property->isRequired()) {
            $property->addValidator(
                new PropertyValidator(
                    "!isset(\$modelData['{$property->getName()}'])",
                    InvalidArgumentException::class,
                    "Missing required value for {$property->getName()}"
                ),
                1
            );
        }

        if (isset($propertyData['enum'])) {
            $this->addEnumValidator($property, $propertyData['enum']);
        }
    }

    /**
     * Add a validator to a property which validates the value against a list of allowed values
     *
     * @param PropertyInterface $property
     * @param array             $allowedValues
     */
    protected function addEnumValidator(PropertyInterface $property, array $allowedValues)
    {
        $property->addValidator(
            new PropertyValidator(
                '!in_array($value, ' .
                    preg_replace('(\d+\s=>)', '', var_export(array_values($allowedValues), true)) .
                    ', true)',
                InvalidArgumentException::class,
                "Invalid value for {$property->getName()} declined by enum constraint"
            ),
            3
        );
    }
}
