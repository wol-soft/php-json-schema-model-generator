<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Object;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\DeniedPropertyValidator;
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
     * @throws SchemaException
     */
    private function addDependencyValidator(
        PropertyInterface $property,
        JsonSchema $dependencyJsonSchema,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
    ): void {
        if ($dependencyJsonSchema->isBooleanSchema()) {
            $this->addBooleanDependencyValidator($property, $dependencyJsonSchema, $schema);

            return;
        }

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

            return;
        }

        // The dependency schema is validated against the object which holds the property, so an untyped dependency is
        // an object schema, and a type list which includes "object" is narrowed to "object": the other members of the
        // list can never apply. Rejecting such a type list was considered and dropped, as it refuses a valid schema
        // which behaves exactly like the narrowed one.
        $json = $dependencyJsonSchema->getJson();
        $declaredType = $json['type'] ?? null;
        if ($declaredType === null || (is_array($declaredType) && in_array('object', $declaredType, true))) {
            $dependencyJsonSchema = $dependencyJsonSchema->withJson(['type' => 'object'] + $json);
        }

        $dependencySchema = $schemaProcessor->processSchema(
            $dependencyJsonSchema,
            $schema->getClassPath(),
            "{$schema->getClassName()}_{$property->getName()}_Dependency",
            $schema->getSchemaDictionary(),
        );

        // processSchema() skips a schema which neither declares "type": "object" nor carries a composition or a
        // reference, so only a dependency whose declared type excludes "object" ends up here. Such a schema is valid
        // JSON Schema: no object satisfies it, so it forbids the property. The generator doesn't map it onto the
        // denial of a `false` dependency, as a type which can never apply is far more likely a mistake than a way to
        // forbid the property, so it is rejected as unsupported, pointing at `false` as the explicit spelling.
        if ($dependencySchema === null) {
            throw new SchemaException(
                sprintf(
                    "Schema dependency of property '%s' in file %s declares %s, which the object holding the property"
                        . ' can never satisfy: a schema dependency which only forbids the property is not supported,'
                        . ' declare the dependency as false instead',
                    $property->getName(),
                    $dependencyJsonSchema->getFile(),
                    $this->formatDeclaredTypes($dependencyJsonSchema->getJson()['type']),
                ),
                $dependencyJsonSchema,
            );
        }

        $property->addValidator(
            (new SchemaDependencyValidator($schemaProcessor, $property, $dependencySchema))
                ->withJsonPointer($dependencyPointer),
        );
        $schema->addNamespaceTransferDecorator(new SchemaNamespaceTransferDecorator($dependencySchema));

        $this->transferDependentPropertiesToBaseSchema($dependencySchema, $schema);
    }

    /**
     * A dependency which is `true` imposes nothing. A dependency which is `false` can't be satisfied by any object that
     * contains the property, so the property must not be provided at all: the presence of the key in the model data is
     * denied exactly like the `false` entry of `properties` does, which doesn't depend on the value (also not on a null
     * which implicit null treats as absent). A required property would never be valid, which is rejected like the
     * required property of a `false` entry in `properties`.
     *
     * @throws SchemaException
     */
    private function addBooleanDependencyValidator(
        PropertyInterface $property,
        JsonSchema $dependencyJsonSchema,
        Schema $schema,
    ): void {
        if ($dependencyJsonSchema->getBooleanValue()) {
            return;
        }

        if ($property->isRequired()) {
            throw new SchemaException(
                sprintf(
                    "Property '%s' has the dependency false and must not be provided, but is also listed as required"
                        . " in file %s",
                    $property->getName(),
                    $dependencyJsonSchema->getFile(),
                ),
                $dependencyJsonSchema,
            );
        }

        $schema->addBaseValidator(
            (new DeniedPropertyValidator(
                new Property($property->getName(), null, $dependencyJsonSchema->withJson([])),
            ))->withJsonPointer($dependencyJsonSchema->getPointer()),
        );
    }

    private function formatDeclaredTypes(mixed $declaredType): string
    {
        $types = array_map(
            static fn(mixed $type): string => is_string($type) ? $type : (string) json_encode($type),
            (array) $declaredType,
        );

        return sprintf("the %s '%s'", count($types) === 1 ? 'type' : 'types', implode("', '", $types));
    }

    /**
     * The transferred properties neither validate nor transform: the dependency schema only validates the data (also
     * with its filters, which keep running inside the dependency schema). A property which is declared by the base
     * schema keeps the declaration of the base schema, independent of the order of the properties.
     */
    private function transferDependentPropertiesToBaseSchema(Schema $dependencySchema, Schema $schema): void
    {
        foreach ($dependencySchema->getProperties() as $dependencyProperty) {
            // Registered as a transferred property (not as a property of the base schema): a property which the base
            // schema declares takes precedence, see PropertyMerger::merge().
            $schema->addProperty(
                (clone $dependencyProperty)
                    ->setRequired(false)
                    ->setType(null)
                    ->filterValidators(static fn(): bool => false),
                SchemaDependencyValidator::class,
            );
        }
    }
}
