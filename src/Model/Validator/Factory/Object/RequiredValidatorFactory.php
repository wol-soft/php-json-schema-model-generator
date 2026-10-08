<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Object;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\JsonSchema as JsonSchemaUtil;

/**
 * Attaches a RequiredPropertyValidator to every property named in the object schema's 'required'
 * list. Fabricates an empty-schema stub property for a required name that isn't declared in
 * 'properties' — PropertiesValidatorFactory only creates properties for names it finds in
 * 'properties'.
 *
 * Must run after PropertiesValidatorFactory (registered later in the object Type's modifier
 * list): a name denied via 'properties'/<name> === false that is also required is rejected by
 * PropertiesValidatorFactory before this factory runs, so a name missing from $schema here always
 * means "not declared in 'properties' at all", never "denied".
 */
class RequiredValidatorFactory extends AbstractValidatorFactory
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

        foreach ($json[$this->key] ?? [] as $propertyName) {
            $requiredProperty = $schema->getProperty((string) $propertyName)
                ?? $this->createUndeclaredProperty($schemaProcessor, $schema, $propertySchema, (string) $propertyName);

            $requiredProperty->addValidator(
                (new RequiredPropertyValidator($requiredProperty))
                    ->withJsonPointer($propertySchema->getPointer() . '/' . $this->key),
                1,
            );
        }
    }

    /**
     * @throws SchemaException
     */
    private function createUndeclaredProperty(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        JsonSchema $propertySchema,
        string $propertyName,
    ): PropertyInterface {
        // navigate() cannot traverse into a path segment that doesn't exist in the raw JSON; use
        // withPointer() to advance the pointer without descending into JSON content.
        $nestedPropertySchema = $propertySchema
            ->withPointer(
                $propertySchema->getPointer() . '/properties/' . JsonSchemaUtil::encodePointer($propertyName),
            )
            ->withJson([]);

        $nestedProperty = (new PropertyFactory())->create(
            $schemaProcessor,
            $schema,
            $propertyName,
            $nestedPropertySchema,
            true,
        );

        $dependencies = $propertySchema->getJson()['dependencies'][$propertyName] ?? null;

        if ($dependencies !== null) {
            $this->addDependencyValidator(
                $nestedProperty,
                $schema->getJsonSchema()->navigate('dependencies/' . JsonSchemaUtil::encodePointer($propertyName)),
                $schemaProcessor,
                $schema,
            );
        }

        $schema->addProperty($nestedProperty);

        return $nestedProperty;
    }
}
