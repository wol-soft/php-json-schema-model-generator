<?php

declare(strict_types=1);

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
                $definitionSchema = $definition->getSchema();

                if (
                    $this->schema->getClassPath() !== $definitionSchema->getClassPath() ||
                    $this->schema->getClassName() !== $definitionSchema->getClassName() ||
                    (
                        $this->schema->getClassName() === 'ExternalSchema' &&
                        $definitionSchema->getClassName() === 'ExternalSchema'
                    )
                ) {
                    $this->schema->addNamespaceTransferDecorator(
                        new SchemaNamespaceTransferDecorator($definitionSchema),
                    );

                    // When the definition resolves to a canonical (non-ExternalSchema) class that
                    // lives in a different namespace from the current schema, register its FQCN
                    // directly as a used class. The ExternalSchema intermediary that previously
                    // performed this registration (transitively via its own usedClasses list) is
                    // no longer created when the file was already processed; this explicit call
                    // ensures the referencing schema's import list remains complete.
                    if ($definitionSchema->getClassName() !== 'ExternalSchema') {
                        $this->schema->addUsedClass(join('\\', array_filter([
                            $this->schemaProcessor->getGeneratorConfiguration()->getNamespacePrefix(),
                            $definitionSchema->getClassPath(),
                            $definitionSchema->getClassName(),
                        ])));
                    }
                }

                return $definition->resolveReference(
                    $propertyName,
                    $path,
                    $this->required,
                    $propertySchema->getJson()['_dependencies'] ?? null,
                );
            }
        } catch (Exception $exception) {
            throw new SchemaException(
                "Unresolved Reference $reference in file {$propertySchema->getFile()}",
                0,
                $exception,
            );
        }

        throw new SchemaException("Unresolved Reference $reference in file {$propertySchema->getFile()}");
    }
}
