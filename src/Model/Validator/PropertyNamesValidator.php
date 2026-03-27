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
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class PropertyNamesValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class PropertyNamesValidator extends PropertyTemplateValidator
{
    /**
     * PropertyNamesValidator constructor.
     *
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
            throw new SchemaException("Invalid const property name in file {$propertiesNames->getFile()}");
        }

        // Property names are always strings; ensure the schema declares the type so that
        // string-specific validators (minLength, maxLength, pattern) are applied.
        $propertiesNamesAsString = array_key_exists('type', $propertiesNames->getJson())
            ? $propertiesNames
            : $propertiesNames->withJson(['type' => 'string'] + $propertiesNames->getJson());

        $nameValidationProperty = (new PropertyFactory(new PropertyProcessorFactory()))
            ->create($schemaProcessor, $schema, 'property name', $propertiesNamesAsString)
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
}
