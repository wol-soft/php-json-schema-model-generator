<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Object;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\DeniedPropertyValidator;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
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

                $schema->addBaseValidator(
                    (new DeniedPropertyValidator(
                        new Property($propertyName, null, $propertySchema->withJson([])),
                    ))->withJsonPointer(
                        $propertySchema->getPointer()
                            . '/properties/'
                            . JsonSchemaUtil::encodePointer((string) $propertyName),
                    ),
                );
                continue;
            }

            $required = in_array($propertyName, $json['required'] ?? [], true);

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
                    ->withJson($propertyStructure);
            }

            $schema->addProperty(
                $propertyFactory->create(
                    $schemaProcessor,
                    $schema,
                    (string) $propertyName,
                    $nestedPropertySchema,
                    $required,
                ),
            );
        }
    }
}
