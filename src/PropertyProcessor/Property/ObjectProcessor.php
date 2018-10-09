<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Model\Property;
use PHPModelGenerator\PropertyProcessor\Decorator\ObjectInstantiationDecorator;

/**
 * Class ObjectProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class ObjectProcessor extends AbstractNestedValueProcessor
{
    protected const TYPE = 'object';

    /**
     * @inheritdoc
     */
    public function process(string $propertyName, array $propertyData): Property
    {
        $property = parent::process($propertyName, $propertyData);

        $className = $propertyData['id'] ?? sprintf('%s_%s', $this->schemaProcessor->getCurrentClassName(), uniqid());

        $this->schemaProcessor->processSchema($propertyData, $this->schemaProcessor->getCurrentClassPath(), $className);

        $property
            ->addDecorator(new ObjectInstantiationDecorator($className))
            ->setType($className);

        return $property;
    }
}
