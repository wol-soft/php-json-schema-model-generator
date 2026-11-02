<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Object\InvalidPropertyNamesException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

class PropertyNamesValidator extends PropertyTemplateValidator implements ObjectUpdateDependentValidatorInterface
{
    /**
     * @throws SchemaException
     */
    public function __construct(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        JsonSchema $propertiesNames,
    ) {
        $this->isResolved = true;

        if (
            array_key_exists('const', $propertiesNames->getJson()) &&
            gettype($propertiesNames->getJson()['const']) !== 'string'
        ) {
            throw new SchemaException(
                "Invalid const property name in file {$propertiesNames->getFile()}",
                $propertiesNames,
            );
        }

        if (
            array_key_exists('type', $propertiesNames->getJson()) &&
            $propertiesNames->getJson()['type'] !== 'string'
        ) {
            throw new SchemaException(
                sprintf(
                    "Invalid type '%s' for propertyNames schema in file %s",
                    $propertiesNames->getJson()['type'],
                    $propertiesNames->getFile(),
                ),
                $propertiesNames,
            );
        }

        // Property names are always strings; ensure the schema declares the type so that
        // string-specific validators (minLength, maxLength, pattern) are applied.
        $propertiesNamesAsString = array_key_exists('type', $propertiesNames->getJson())
            ? $propertiesNames
            : $propertiesNames->withJson(['type' => 'string'] + $propertiesNames->getJson());

        // The name is always present: a required property, like the other properties which validate a value of the
        // object (additional properties, pattern properties, ...). Some validators of an optional property (eg. a
        // not or a const without implicit null) only validate a value which is a provided key of the object, which a
        // name never is.
        $nameValidationProperty = (new PropertyFactory())
            ->create($schemaProcessor, $schema, 'property name', $propertiesNamesAsString, true)
            // the property name validator doesn't need type checks or required checks so simply filter them out
            ->filterValidators(static fn(Validator $validator): bool =>
                !is_a($validator->getValidator(), RequiredPropertyValidator::class) &&
                !is_a($validator->getValidator(), TypeCheckValidator::class));

        parent::__construct(
            new Property($schema->getClassName(), null, $propertiesNames),
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'PropertyNames.phptpl',
            [
                'nameValidationProperty' => $nameValidationProperty,
                'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                'viewHelper'             => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
                'schema'                 => $schema,
            ],
            InvalidPropertyNamesException::class,
            ['&$invalidProperties'],
        );
    }

    /**
     * Initialize all variables which are required to execute a property names validator
     */
    public function getValidatorSetUp(): string
    {
        return '$invalidProperties = [];';
    }

    public function getObjectUpdateDependency(string $key): ObjectUpdateDependency
    {
        return ObjectUpdateDependency::AddedKey;
    }

    public function getUndeclaredKeyDependency(): ObjectUpdateDependency
    {
        return ObjectUpdateDependency::AddedKey;
    }
}
