<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\ObjectInstantiationDecorator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class SchemaDependencyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class SchemaDependencyValidator extends PropertyTemplateValidator
{
    /**
     * SchemaDependencyValidator constructor.
     *
     * @param SchemaProcessor $schemaProcessor
     * @param PropertyInterface $property
     * @param Schema $schema
     */
    public function __construct(SchemaProcessor $schemaProcessor, PropertyInterface $property, Schema $schema)
    {
        parent::__construct(
            "Invalid schema which is dependant on {$property->getName()}:\\n  " .
                '" . implode("\n  ", explode("\n", $dependencyException->getMessage())) . "',
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'SchemaDependency.phptpl',
            [
                'viewHelper' => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
                'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                'propertyName' => $property->getName(),
                'transferProperties' => $schema->getProperties(),
                // set up a helper property for handling of the nested object
                'nestedProperty' => (new Property("{$property->getName()}Dependency", ''))
                    ->addDecorator(new ObjectInstantiationDecorator(
                        $schema->getClassName(),
                        $schemaProcessor->getGeneratorConfiguration()
                    ))
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
        return '$dependencyException = null;';
    }
}
