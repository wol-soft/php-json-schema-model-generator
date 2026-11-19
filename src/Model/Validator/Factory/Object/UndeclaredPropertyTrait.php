<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Object;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\JsonSchema as JsonSchemaUtil;

/**
 * Creates the property of a key which the object schema names (in `required` or `dependencies`) without declaring it
 * in `properties`, so the generated class provides an untyped getter and setter for the key. The property carries no
 * declaration of its own: the keywords which describe undeclared keys (`additionalProperties`, `patternProperties`)
 * keep validating the key on the object level.
 */
trait UndeclaredPropertyTrait
{
    /**
     * @throws SchemaException
     */
    private function addUndeclaredProperty(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        JsonSchema $objectSchema,
        string $propertyName,
        bool $required,
    ): PropertyInterface {
        // navigate() cannot traverse into a path segment that doesn't exist in the raw JSON; use withPointer() to
        // advance the pointer without descending into JSON content.
        $propertySchema = $objectSchema
            ->withPointer($objectSchema->getPointer() . '/properties/' . JsonSchemaUtil::encodePointer($propertyName))
            ->withJson([]);

        $property = (new PropertyFactory())->create(
            $schemaProcessor,
            $schema,
            $propertyName,
            $propertySchema,
            $required,
        );

        $schema->addProperty($property);

        return $property;
    }
}
