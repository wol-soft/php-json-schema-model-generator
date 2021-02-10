<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Dependency\InvalidSchemaDependencyException;
use PHPModelGenerator\Exception\SchemaException;
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
     * @param SchemaProcessor   $schemaProcessor
     * @param PropertyInterface $property
     * @param Schema            $schema
     *
     * @throws SchemaException
     */
    public function __construct(SchemaProcessor $schemaProcessor, PropertyInterface $property, Schema $schema)
    {
        parent::__construct(
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'SchemaDependency.phptpl',
            [
                'viewHelper' => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
                'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                'transferProperties' => $schema->getProperties(),
                // set up a helper property for handling of the nested object
                'nestedProperty' => (new Property("{$property->getName()}Dependency", null, $schema->getJsonSchema()))
                    ->addDecorator(new ObjectInstantiationDecorator(
                        $schema->getClassName(),
                        $schemaProcessor->getGeneratorConfiguration()
                    ))
            ],
            InvalidSchemaDependencyException::class,
            ['&$dependencyException']
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
