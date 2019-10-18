<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\ObjectInstantiationDecorator;

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
            $propertyData['id'] ?? str_replace(
                ' ',
                '_',
                sprintf('%s_%s_%s', $this->schemaProcessor->getCurrentClassName(), $propertyName, uniqid())
            )
        );

        $schema = $this->schemaProcessor->processSchema(
            $propertyData,
            $this->schemaProcessor->getCurrentClassPath(),
            $className,
            $this->schema->getSchemaDictionary()
        );

        $property
            ->addDecorator(
                new ObjectInstantiationDecorator(
                    $schema->getClassName(),
                    $this->schemaProcessor->getGeneratorConfiguration()
                )
            )
            ->setType($schema->getClassName())
            ->setNestedSchema($schema);

        return $property;
    }
}
