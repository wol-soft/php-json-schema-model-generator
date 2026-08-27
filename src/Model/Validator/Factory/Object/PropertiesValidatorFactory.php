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
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\JsonSchema as JsonSchemaUtil;

class PropertiesValidatorFactory extends AbstractValidatorFactory
{
    use PropertyDependencyTrait;

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
                        $propertySchema,
                    );
                }

                if (isset($json['dependencies'][$propertyName])) {
                    throw new SchemaException(
                        sprintf(
                            "Property '%s' is denied (schema false) but also has dependencies defined in file %s",
                            $propertyName,
                            $propertySchema->getFile(),
                        ),
                        $propertySchema,
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
}
