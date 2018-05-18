<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Model\Property;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;

/**
 * Class EnumProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class EnumProcessor extends AbstractPropertyProcessor
{
    /** @var array */
    protected $values;

    public function __construct(array $values, PropertyCollectionProcessor $propertyCollectionProcessor)
    {
        parent::__construct($propertyCollectionProcessor);
        $this->values = $values;
    }

    /**
     * @inheritdoc
     */
    public function process(string $propertyName, array $propertyData): Property
    {
        $property = new Property($propertyName, '');

        $this->generateValidators($property, $propertyData);

        return $property;
    }

    /**
     * @inheritdoc
     */
    protected function generateValidators(Property $property, array $propertyData): void
    {
        parent::generateValidators($property, $propertyData);
        $this->addEnumValidator($property, $this->values);
    }
}
