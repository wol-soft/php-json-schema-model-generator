<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

/**
 * Class BaseReferenceProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class BasereferenceProcessor extends ReferenceProcessor
{
    /**
     * @inheritdoc
     *
     * @throws SchemaException
     */
    public function process(string $propertyName, JsonSchema $propertySchema): PropertyInterface
    {
        // make sure definitions are available. By default the definition dictionary is set up by the BaseProcessor
        $this->schema
            ->getSchemaDictionary()
            ->setUpDefinitionDictionary($this->schemaProcessor, $this->schema);

        $property = parent::process($propertyName, $propertySchema);

        if (!$property->getNestedSchema()) {
            throw new SchemaException(
                sprintf(
                    'A referenced schema on base level must provide an object definition for property %s in file %s',
                    $propertyName,
                    $propertySchema->getFile()
                )
            );
        }

        foreach ($property->getNestedSchema()->getProperties() as $propertiesOfReferencedObject) {
            $this->schema->addProperty($propertiesOfReferencedObject);
        }

        return $property;
    }
}
