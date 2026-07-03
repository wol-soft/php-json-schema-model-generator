<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft\Producer;

use Exception;
use PHPModelGenerator\Attributes\JsonPointer;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Attributes\PhpAttribute;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\Factory\Composition\AllOfValidatorFactory;
use PHPModelGenerator\PropertyProcessor\Decorator\SchemaNamespaceTransferDecorator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Resolves a $ref by looking it up in the definition dictionary and returning the referenced
 * property. The resolver only resolves: it holds no sibling policy (that is expressed per draft by
 * wrapping it in an {@see ExclusiveProducer} or not) and does not merge with other producers.
 */
class RefResolver implements PropertyProducerInterface
{
    /**
     * @throws SchemaException
     */
    public function produce(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $propertyName,
        JsonSchema $propertySchema,
        bool $required,
    ): PropertyInterface {
        $json = $propertySchema->getJson();

        if (isset($json['type']) && $json['type'] === 'base') {
            return $this->resolveBaseReference($schemaProcessor, $schema, $propertyName, $propertySchema, $required);
        }

        return $this->resolveReference($schemaProcessor, $schema, $propertyName, $propertySchema, $required);
    }

    /**
     * Resolve a $ref reference by looking it up in the definition dictionary.
     *
     * @throws SchemaException
     */
    private function resolveReference(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $propertyName,
        JsonSchema $propertySchema,
        bool $required,
    ): PropertyInterface {
        $path       = [];
        $reference  = $propertySchema->getJson()['$ref'];
        $dictionary = $schema->getSchemaDictionary();

        try {
            $definition = $dictionary->getDefinition($reference, $schemaProcessor, $path);

            if ($definition) {
                $definitionSchema = $definition->getSchema();

                if (
                    $schema->getClassPath() !== $definitionSchema->getClassPath() ||
                    $schema->getClassName() !== $definitionSchema->getClassName() ||
                    (
                        $schema->getClassName() === 'ExternalSchema' &&
                        $definitionSchema->getClassName() === 'ExternalSchema'
                    )
                ) {
                    $schema->addNamespaceTransferDecorator(
                        new SchemaNamespaceTransferDecorator($definitionSchema),
                    );

                    if ($definitionSchema->getClassName() !== 'ExternalSchema') {
                        $schema->addUsedClass(join('\\', array_filter([
                            $schemaProcessor->getGeneratorConfiguration()->getNamespacePrefix(),
                            $definitionSchema->getClassPath(),
                            $definitionSchema->getClassName(),
                        ])));
                    }
                }

                $property = $definition->resolveReference(
                    $propertyName,
                    implode('/', $path),
                    $required,
                    $propertySchema->getJson()['_dependencies'] ?? null,
                );

                // Use the reference site's pointer (where $ref appears in the schema) rather
                // than the definition's pointer. This is always meaningful and consistent with
                // how inline properties work — both show WHERE IN THE SCHEMA the property is
                // defined, not where the resolved type lives.
                $property->overrideJsonPointer(new PhpAttribute(JsonPointer::class, [$propertySchema->getPointer()]));

                return $property;
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

    /**
     * Resolve a $ref on a base-level schema: set up definitions, delegate to resolveReference,
     * then copy the referenced schema's properties to the parent schema.
     *
     * @throws SchemaException
     */
    private function resolveBaseReference(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $propertyName,
        JsonSchema $propertySchema,
        bool $required,
    ): PropertyInterface {
        $schema->getSchemaDictionary()->setUpDefinitionDictionary($schemaProcessor, $schema);

        $property = $this->resolveReference($schemaProcessor, $schema, $propertyName, $propertySchema, $required);

        if (!$property->getNestedSchema()) {
            throw new SchemaException(
                sprintf(
                    'A referenced schema on base level must provide an object definition for property %s in file %s',
                    $propertyName,
                    $propertySchema->getFile(),
                )
            );
        }

        foreach ($property->getNestedSchema()->getProperties() as $refProperty) {
            // Use allOf semantics when a sibling has already registered this property name so
            // that type-intersection narrowing and default-conflict detection apply. For
            // properties that only the ref defines, plain registration (null compositionProcessor)
            // is correct — they become root-registered with no merge needed.
            $compositionProcessor = $schema->getProperty($refProperty->getName()) !== null
                ? AllOfValidatorFactory::class
                : null;
            $schema->addProperty($refProperty, $compositionProcessor);
        }

        return $property;
    }
}
