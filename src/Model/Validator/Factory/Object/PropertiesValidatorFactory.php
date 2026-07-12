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
use PHPModelGenerator\Utils\JsonSchema as JsonSchemaUtil;

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

                if (isset($json['dependencies'][$propertyName])) {
                    throw new SchemaException(
                        sprintf(
                            "Property '%s' is denied (schema false) but also has dependencies defined in file %s",
                            $propertyName,
                            $propertySchema->getFile(),
                        ),
                    );
                }

                $schema->addBaseValidator(
                    (new PropertyValidator(
                        new Property($propertyName, null, $propertySchema->withJson([])),
                        "array_key_exists('" . addslashes($propertyName) . "', \$modelData)",
                        DeniedPropertyException::class,
                    ))->withJsonPointer(
                        $propertySchema->getPointer()
                            . '/properties/'
                            . JsonSchemaUtil::encodePointer((string) $propertyName),
                    ),
                );
                continue;
            }

            $required = in_array($propertyName, $json['required'] ?? [], true);
            $dependencies = $json['dependencies'][$propertyName] ?? null;

            if ($propertyStructure === true) {
                // navigate() cannot traverse into a `true` schema value; use withPointer() to
                // advance the pointer without descending into JSON content, then replace the json.
                $nestedPropertySchema = $propertySchema
                    ->withPointer(
                        $propertySchema->getPointer()
                            . '/' . $this->key . '/'
                            . JsonSchemaUtil::encodePointer($propertyName)
                    )
                    ->withJson([]);
            } else {
                $nestedPropertySchema = $propertySchema
                    ->navigate("$this->key/" . JsonSchemaUtil::encodePointer($propertyName))
                    ->withJson(
                        $dependencies !== null
                            ? $propertyStructure + ['_dependencies' => $dependencies]
                            : $propertyStructure,
                    );
            }

            $nestedProperty = $propertyFactory->create(
                $schemaProcessor,
                $schema,
                (string) $propertyName,
                $nestedPropertySchema,
                $required,
            );

            if ($dependencies !== null) {
                $this->addDependencyValidator(
                    $nestedProperty,
                    $schema->getJsonSchema()->navigate(
                        'dependencies/' . JsonSchemaUtil::encodePointer((string) $propertyName),
                    ),
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

        $dependencyPointer = $dependencyJsonSchema->getPointer();

        if ($propertyDependency) {
            $property->addValidator(
                (new PropertyDependencyValidator($property, $dependencyJsonSchema->getJson()))
                    ->withJsonPointer($dependencyPointer),
            );

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

        $property->addValidator(
            (new SchemaDependencyValidator($schemaProcessor, $property, $dependencySchema))
                ->withJsonPointer($dependencyPointer),
        );
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
