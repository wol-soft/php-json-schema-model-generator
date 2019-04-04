<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use Exception;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class ConstProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class ReferenceProcessor extends AbstractTypedValueProcessor
{
    /**
     * @inheritdoc
     *
     * @throws SchemaException
     */
    public function process(string $propertyName, array $propertyData): PropertyInterface
    {
        $path = [];
        $reference = $propertyData['$ref'];
        $dictionary = $this->schema->getSchemaDictionary();

        try {
            $definition = $dictionary->getDefinition($reference, $this->schemaProcessor, $path);

            if ($definition) {
                return $definition->resolveReference($propertyName, $path, $this->propertyCollectionProcessor);
            }
        } catch (Exception $exception) {
            throw new SchemaException("Unresolved Reference: $reference", 0, $exception);
        }

        throw new SchemaException("Unresolved Reference: $reference");
    }
}
