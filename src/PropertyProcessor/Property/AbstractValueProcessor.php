<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Model\Property;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;

/**
 * Class AbstractScalarValueProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
abstract class AbstractValueProcessor extends AbstractPropertyProcessor
{
    private $type = '';

    /**
     * AbstractValueProcessor constructor.
     *
     * @param PropertyCollectionProcessor $propertyCollectionProcessor
     * @param string                      $type
     */
    public function __construct(PropertyCollectionProcessor $propertyCollectionProcessor, string $type = '')
    {
        parent::__construct($propertyCollectionProcessor);
        $this->type = $type;
    }

    /**
     * @inheritdoc
     */
    public function process(string $propertyName, array $propertyData): Property
    {
        $property = (new Property($propertyName, $this->type))
            // the property is either required if defined as required
            // or if the property is related to a typed enum (strict type checks)
            ->setRequired(
                $this->propertyCollectionProcessor->isAttributeRequired($propertyName) ||
                isset($propertyData['enum'], $propertyData['type'])
            );

        $this->generateValidators($property, $propertyData);

        return $property;
    }
}
