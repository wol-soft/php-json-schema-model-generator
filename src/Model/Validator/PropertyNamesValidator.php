<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPMicroTemplate\Exception\FileSystemException;
use PHPMicroTemplate\Exception\SyntaxErrorException;
use PHPMicroTemplate\Exception\UndefinedSymbolException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Schema;
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
     * @param Schema          $schema
     * @param array           $propertiesNames
     *
     * @throws FileSystemException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     * @throws SchemaException
     */
    public function __construct(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        array $propertiesNames
    ) {
        $nameValidationProperty = (new StringProcessor(new PropertyMetaDataCollection(), $schemaProcessor, $schema))
            ->process('property name', $propertiesNames)
            // the property name validator doesn't need type checks or required checks so simply filter them out
            ->filterValidators(function (Validator $validator): bool {
                return !is_a($validator->getValidator(), RequiredPropertyValidator::class) &&
                    !is_a($validator->getValidator(), TypeCheckValidator::class);
            });

        parent::__construct(
            $this->getRenderer()->renderTemplate(
                DIRECTORY_SEPARATOR . 'Exception' . DIRECTORY_SEPARATOR . 'InvalidPropertiesException.phptpl',
                ['error' => 'Provided JSON contains properties with invalid names.', 'property' => 'property']
            ),
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'PropertyNames.phptpl',
            [
                'nameValidationProperty' => $nameValidationProperty,
                'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                'viewHelper'             => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
            ]
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
