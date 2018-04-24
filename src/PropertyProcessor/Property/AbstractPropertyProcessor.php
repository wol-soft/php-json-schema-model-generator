<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use InvalidArgumentException;
use PHPModelGenerator\Model\Property;
use PHPModelGenerator\Model\PropertyValidator;
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
     * @param Property $property
     * @param array    $propertyData
     */
    protected function generateValidators(Property $property, array $propertyData): void
    {
        if ($this->propertyCollectionProcessor->isAttributeRequired($property->getName())) {
            $property->addValidator(
                new PropertyValidator(
                    'empty($value)',
                    InvalidArgumentException::class,
                    "Value for {$property->getName()} must not be empty"
                )
            );
        }
    }
}
