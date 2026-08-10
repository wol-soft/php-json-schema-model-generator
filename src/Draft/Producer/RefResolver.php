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
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
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
        bool $isArrayItem = false,
    ): PropertyInterface {
        $json = $propertySchema->getJson();

        if (isset($json['type']) && $json['type'] === 'base') {
            return $this->resolveBaseReference(
                $schemaProcessor,
                $schema,
                $propertyName,
                $propertySchema,
                $required,
                $isArrayItem,
            );
        }

        return $this->resolveReference(
            $schemaProcessor,
            $schema,
            $propertyName,
            $propertySchema,
            $required,
            $isArrayItem,
        );
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
        bool $isArrayItem = false,
    ): PropertyInterface {
        $path       = [];
        $reference  = $propertySchema->getJson()['$ref'];
        $dictionary = $schema->getSchemaDictionary();

        try {
            $definition = $dictionary->getDefinition($reference, $schemaProcessor, $path, $propertySchema->getBaseId());

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
                    $isArrayItem,
                );

                // Use the reference site's pointer (where $ref appears in the schema) rather
                // than the definition's pointer. This is always meaningful and consistent with
                // how inline properties work — both show WHERE IN THE SCHEMA the property is
                // defined, not where the resolved type lives.
                $property->overrideJsonPointer(new PhpAttribute(JsonPointer::class, [$propertySchema->getPointer()]));

                return $property;
            }
        } catch (Exception $exception) {
            // A failure raised while eagerly generating the REFERENCED schema's own class already
            // names that schema and the real cause, so replacing it with "Unresolved Reference"
            // would blame the reference site for a fault in its target - the reference resolved
            // perfectly well. Only a genuine resolution failure (missing file, malformed JSON)
            // means the reference itself is broken, which is what the message below says.
            if ($exception instanceof SchemaException && $exception->isReferencedSchemaFailure()) {
                throw $exception;
            }

            throw new SchemaException(
                "Unresolved Reference $reference in file {$propertySchema->getFile()}",
                null,
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
        bool $isArrayItem = false,
    ): PropertyInterface {
        $reference = $propertySchema->getJson()['$ref'];

        // '' and '#' both resolve (RFC 3986 §5.2.2: empty path/fragment) to the enclosing
        // document's own root. On base level that means the schema would be merged with itself -
        // there is no fixed point to compute, so PropertyProxy::getNestedSchema() below would
        // recurse into resolving the same schema forever instead of converging. Reject it
        // explicitly rather than looping until the interpreter's guard aborts the process.
        if ($reference === '' || $reference === '#') {
            throw new SchemaException(
                sprintf(
                    "A referenced schema on base level must not reference itself for property '%s' in file %s",
                    $propertyName,
                    $propertySchema->getFile(),
                ),
            );
        }

        $schema->getSchemaDictionary()->setUpDefinitionDictionary($schemaProcessor, $schema);

        $property = $this->resolveReference(
            $schemaProcessor,
            $schema,
            $propertyName,
            $propertySchema,
            $required,
            $isArrayItem,
        );

        if (!$property->getNestedSchema()) {
            // A referenced schema that is an anyOf/oneOf composition (or a non-object-asserting
            // allOf) deliberately has NO single nested schema, per
            // PropertyInterface::getNestedSchema(): its object values are represented by
            // branch-owned classes reachable through $property's own composed validator instead.
            // Transfer that composition to $schema exactly as a composition sitting directly on
            // this class would be - do NOT special-case this to the allOf shape below; the
            // mechanism is identical regardless of which composition keyword produced the
            // validator. Without this, a base-level $ref to such a schema is refused outright even
            // though the same composition written inline generates fine.
            if ($this->hasComposedPropertyValidator($property)) {
                $schemaProcessor->transferComposedPropertiesToSchema($property, $schema);

                return $property;
            }

            throw new SchemaException(
                sprintf(
                    'A referenced schema on base level must provide an object definition for property %s in file %s',
                    $propertyName,
                    $propertySchema->getFile(),
                )
            );
        }

        // A referenced schema that is itself a composition (e.g. an allOf of further $refs, as
        // built by the object-shape re-routing) enforces requiredness and cross-branch constraints
        // via its OWN base validator, not via validators attached to the individual transferred
        // properties - those are merged/redirected and carry no validation of their own. The same
        // holds for object-level keywords (additionalProperties, minProperties, propertyNames),
        // which live on the referenced schema's base validators rather than on any property.
        // Without transferring them here, a base-level $ref silently drops every constraint that
        // is not attached to an individual property.
        foreach ($property->getNestedSchema()->getBaseValidators() as $baseValidator) {
            $schema->addBaseValidator($baseValidator);
        }

        foreach ($property->getNestedSchema()->getProperties() as $refProperty) {
            // Use allOf semantics when a sibling has already registered this property name so
            // that type-intersection narrowing and default-conflict detection apply, and pass
            // the ref property's own JsonSchema as the constraint-reapplication source (see
            // PropertyMerger::merge()) so narrowing doesn't silently drop the ref's type-specific
            // constraints (minimum, maximum, …). For properties that only the ref defines, plain
            // registration (null compositionProcessor) is correct — they become root-registered
            // with no merge needed.
            $existingProperty     = $schema->getProperty($refProperty->getName());
            $compositionProcessor = $existingProperty !== null
                ? AllOfValidatorFactory::class
                : null;
            $schema->addProperty($refProperty, $compositionProcessor, $refProperty->getJsonSchema(), $schemaProcessor);
        }

        return $property;
    }

    /**
     * Returns true when $property carries a composition validator (allOf/anyOf/oneOf/if-then-else)
     * among its own validators - the shape a base-level $ref to a disjunctive composition takes
     * (see PropertyInterface::getNestedSchema()).
     */
    private function hasComposedPropertyValidator(PropertyInterface $property): bool
    {
        foreach ($property->getValidators() as $validator) {
            if (is_a($validator->getValidator(), AbstractComposedPropertyValidator::class)) {
                return true;
            }
        }

        return false;
    }
}
