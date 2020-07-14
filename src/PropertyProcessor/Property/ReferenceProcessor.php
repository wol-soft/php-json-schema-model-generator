<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use Exception;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\PropertyProcessor\Decorator\SchemaNamespaceTransferDecorator;

/**
 * Class ReferenceProcessor
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
    public function process(string $propertyName, JsonSchema $propertySchema): PropertyInterface
    {
        $path = [];
        $reference = $propertySchema->getJson()['$ref'];
        $dictionary = $this->schema->getSchemaDictionary();

        try {
            $definition = $dictionary->getDefinition($reference, $this->schemaProcessor, $path);

            if ($definition) {
                if ($this->schema->getClassPath() !== $definition->getSchema()->getClassPath() ||
                    $this->schema->getClassName() !== $definition->getSchema()->getClassName()
                ) {
                    $this->schema->addNamespaceTransferDecorator(
                        new SchemaNamespaceTransferDecorator($definition->getSchema())
                    );
                }

                return $definition->resolveReference($propertyName, $path, $this->propertyMetaDataCollection);
            }
        } catch (Exception $exception) {
            throw new SchemaException(
                "Unresolved Reference $reference in file {$propertySchema->getFile()}",
                0,
                $exception
            );
        }

        throw new SchemaException("Unresolved Reference $reference in file {$propertySchema->getFile()}");
    }
}
