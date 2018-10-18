<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\SchemaProperty;

use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\SchemaProcessor\SchemaPropertyProcessorInterface;

/**
 * Class PropertiesProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor\SchemaProperty
 */
class PropertiesProcessor implements SchemaPropertyProcessorInterface
{
    /**
     * @inheritdoc
     */
    public function process(SchemaProcessor $schemaProcessor, Schema $schema, array $structure): void
    {
        $propertyProcessorFactory = new PropertyProcessorFactory();

        $propertyCollectionProcessor = (new PropertyCollectionProcessor())
            ->setRequiredAttributes($structure['required'] ?? []);

        foreach ($structure['properties'] as $propertyName => $property) {
            // redirect properties with a constant value to the ConstProcessor
            if (isset($property['const'])) {
                $property['type'] = 'const';
            }

            $schema->addProperty(
                $propertyProcessorFactory
                    ->getPropertyProcessor($property['type'] ?? 'any', $propertyCollectionProcessor, $schemaProcessor)
                    ->process($propertyName, $property)
            );
        }
    }
}
