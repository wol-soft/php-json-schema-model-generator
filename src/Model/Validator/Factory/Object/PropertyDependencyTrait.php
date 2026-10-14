<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Object;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\PropertyDependencyValidator;
use PHPModelGenerator\Model\Validator\SchemaDependencyValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\SchemaNamespaceTransferDecorator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Attaches a property or schema dependency (the 'dependencies' keyword) to a property.
 * Shared by PropertiesValidatorFactory and RequiredValidatorFactory, as a property's
 * 'dependencies' entry applies regardless of whether the property was declared in 'properties'
 * or only exists because it is listed in 'required'.
 */
trait PropertyDependencyTrait
{
    /**
     * @return Schema|null The dependency schema of a schema dependency whose properties have to be transferred to the
     *                     base schema by transferDependentPropertiesToBaseSchema() once the properties of the base
     *                     schema are registered, null for a property dependency
     *
     * @throws SchemaException
     */
    private function addDependencyValidator(
        PropertyInterface $property,
        JsonSchema $dependencyJsonSchema,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
    ): ?Schema {
        $propertyDependency = true;

        foreach ($dependencyJsonSchema->getJson() as $index => $dependency) {
            if (!is_int($index) || !is_string($dependency)) {
                $propertyDependency = false;
                break;
            }
        }

        $dependencyPointer = $dependencyJsonSchema->getPointer();

        if ($propertyDependency) {
            $property->addValidator(
                (new PropertyDependencyValidator($property, $dependencyJsonSchema->getJson()))
                    ->withJsonPointer($dependencyPointer),
            );

            return null;
        }

        $json = $dependencyJsonSchema->getJson();
        if (!isset($json['type'])) {
            $dependencyJsonSchema = $dependencyJsonSchema->withJson($json + ['type' => 'object']);
        }

        $dependencySchema = $schemaProcessor->processSchema(
            $dependencyJsonSchema,
            $schema->getClassPath(),
            "{$schema->getClassName()}_{$property->getName()}_Dependency",
            $schema->getSchemaDictionary(),
        );

        $property->addValidator(
            (new SchemaDependencyValidator($schemaProcessor, $property, $dependencySchema))
                ->withJsonPointer($dependencyPointer),
        );
        $schema->addNamespaceTransferDecorator(new SchemaNamespaceTransferDecorator($dependencySchema));

        return $dependencySchema;
    }

    /**
     * The transferred properties neither validate nor transform: the dependency schema only validates the data (also
     * with its filters, which keep running inside the dependency schema). Transferred after the properties of the
     * base schema are registered: the declaration which is registered first wins, a property which is declared by the
     * base schema must not be replaced by the unvalidated copy of a dependency schema.
     */
    private function transferDependentPropertiesToBaseSchema(Schema $dependencySchema, Schema $schema): void
    {
        foreach ($dependencySchema->getProperties() as $dependencyProperty) {
            $schema->addProperty(
                (clone $dependencyProperty)
                    ->setRequired(false)
                    ->setType(null)
                    ->filterValidators(static fn(): bool => false),
            );
        }
    }
}
