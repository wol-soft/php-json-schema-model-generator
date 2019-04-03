<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\ObjectInstantiationDecorator;

/**
 * Class ObjectProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class ObjectProcessor extends AbstractTypedValueProcessor
{
    protected const TYPE = 'object';

    /**
     * @inheritdoc
     *
     * @throws SchemaException
     */
    public function process(string $propertyName, array $propertyData): PropertyInterface
    {
        $property = parent::process($propertyName, $propertyData);

        $className = ucfirst(
            $propertyData['id'] ?? sprintf('%s_%s', $this->schemaProcessor->getCurrentClassName(), uniqid())
        );

        $schema = $this->schemaProcessor->processSchema(
            $propertyData,
            $this->schemaProcessor->getCurrentClassPath(),
            $className,
            $this->schema->getSchemaDictionary()
        );

        $property
            ->addDecorator(new ObjectInstantiationDecorator($className))
            ->setType($className)
            ->setNestedSchema($schema);

        return $property;
    }
}
