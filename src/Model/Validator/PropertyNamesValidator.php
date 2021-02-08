<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Object\InvalidPropertyNamesException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\PropertyProcessor\Property\StringProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyMetaDataCollection;
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
     * @param SchemaProcessor $schemaProcessor
     * @param Schema $schema
     * @param JsonSchema $propertiesNames
     *
     * @throws SchemaException
     */
    public function __construct(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        JsonSchema $propertiesNames
    ) {
        $nameValidationProperty = (new StringProcessor(new PropertyMetaDataCollection(), $schemaProcessor, $schema))
            ->process('property name', $propertiesNames)
            // the property name validator doesn't need type checks or required checks so simply filter them out
            ->filterValidators(function (Validator $validator): bool {
                return !is_a($validator->getValidator(), RequiredPropertyValidator::class) &&
                    !is_a($validator->getValidator(), TypeCheckValidator::class);
            });

        parent::__construct(
            new Property($schema->getClassName(), null, $propertiesNames),
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'PropertyNames.phptpl',
            [
                'nameValidationProperty' => $nameValidationProperty,
                'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                'viewHelper'             => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
            ],
            InvalidPropertyNamesException::class,
            ['&$invalidProperties']
        );
    }

    /**
     * Initialize all variables which are required to execute a property names validator
     *
     * @return string
     */
    public function getValidatorSetUp(): string
    {
        return '$invalidProperties = [];';
    }
}
