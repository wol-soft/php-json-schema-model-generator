<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Object;

use PHPModelGenerator\Exception\Generic\DeniedPropertyException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Model\Validator\PropertyDependencyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\SchemaDependencyValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\SchemaNamespaceTransferDecorator;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

class PropertiesValidatorFactory extends AbstractValidatorFactory
{
    /**
     * @throws SchemaException
     */
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $json = $propertySchema->getJson();

        $propertyFactory = new PropertyFactory();

        $json[$this->key] ??= [];
        // Setup empty properties for required properties which aren't defined in the properties section
        $json[$this->key] += array_fill_keys(
            array_diff($json['required'] ?? [], array_keys($json[$this->key])),
            [],
        );

        $propertySchema = $propertySchema->withJson($json);

        foreach ($json[$this->key] as $propertyName => $propertyStructure) {
            if ($propertyStructure === false) {
                if (in_array($propertyName, $json['required'] ?? [], true)) {
                    throw new SchemaException(
                        sprintf(
                            "Property '%s' is denied (schema false) but also listed as required in file %s",
                            $propertyName,
                            $propertySchema->getFile(),
                        ),
                    );
                }

                $schema->addBaseValidator(
                    new PropertyValidator(
                        new Property($propertyName, null, $propertySchema->withJson([])),
                        "array_key_exists('" . addslashes($propertyName) . "', \$modelData)",
                        DeniedPropertyException::class,
                    )
                );
                continue;
            }

            $required = in_array($propertyName, $json['required'] ?? [], true);
            $dependencies = $json['dependencies'][$propertyName] ?? null;
            $nestedProperty = $propertyFactory->create(
                $schemaProcessor,
                $schema,
                (string) $propertyName,
                $propertySchema->navigate("$this->key/" . JsonSchema::encodePointer($propertyName))->withJson(
                    $dependencies !== null
                        ? $propertyStructure + ['_dependencies' => $dependencies]
                        : $propertyStructure,
                ),
                $required,
            );

            if ($dependencies !== null) {
                $this->addDependencyValidator(
                    $nestedProperty,
                    $schema->getJsonSchema()->navigate("dependencies/" . JsonSchema::encodePointer($propertyName)),
                    $schemaProcessor,
                    $schema,
                );
            }

            $schema->addProperty($nestedProperty);
        }
    }

    /**
     * @throws SchemaException
     */
    private function addDependencyValidator(
        PropertyInterface $property,
        JsonSchema $dependencyJsonSchema,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
    ): void {
        $propertyDependency = true;

        foreach ($dependencyJsonSchema->getJson() as $index => $dependency) {
            if (!is_int($index) || !is_string($dependency)) {
                $propertyDependency = false;
                break;
            }
        }

        if ($propertyDependency) {
            $property->addValidator(new PropertyDependencyValidator($property, $dependencyJsonSchema->getJson()));

            return;
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

        $property->addValidator(new SchemaDependencyValidator($schemaProcessor, $property, $dependencySchema));
        $schema->addNamespaceTransferDecorator(new SchemaNamespaceTransferDecorator($dependencySchema));

        $this->transferDependentPropertiesToBaseSchema($dependencySchema, $schema);
    }

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
