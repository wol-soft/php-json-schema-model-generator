<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class BaseReferenceProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class BaseReferenceProcessor extends ReferenceProcessor
{
    /**
     * @inheritdoc
     *
     * @throws SchemaException
     */
    public function process(string $propertyName, array $propertyData): PropertyInterface
    {
        // make sure definitions are available. By default the definition dictionary is set up by the BaseProcessor
        $this->schema
            ->getSchemaDictionary()
            ->setUpDefinitionDictionary($propertyData, $this->schemaProcessor, $this->schema);

        $property = parent::process($propertyName, $propertyData);

        if (!$property->getNestedSchema()) {
            throw new SchemaException(
                "A referenced schema on base level must provide an object definition [$propertyName]"
            );
        }

        foreach ($property->getNestedSchema()->getProperties() as $propertiesOfReferencedObject) {
            $this->schema->addProperty($propertiesOfReferencedObject);
        }

        return $property;
    }
}
