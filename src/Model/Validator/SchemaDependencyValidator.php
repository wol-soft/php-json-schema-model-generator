<?php

declare(strict_types=1);

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
     * @throws SchemaException
     */
    public function __construct(SchemaProcessor $schemaProcessor, PropertyInterface $property, Schema $schema)
    {
        $this->isResolved = true;

        $generatorConfiguration = $schemaProcessor->getGeneratorConfiguration();

        $nestedProperty = (new Property("{$property->getName()}Dependency", null, $schema->getJsonSchema()))
            ->addDecorator(new ObjectInstantiationDecorator($schema->getClassName(), $generatorConfiguration));

        parent::__construct(
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'SchemaDependency.phptpl',
            [
                'viewHelper' => new RenderHelper($generatorConfiguration),
                'generatorConfiguration' => $generatorConfiguration,
                'nestedProperty' => $nestedProperty,
                // Rendered as its own template and embedded via viewHelper.indent() instead of being inlined
                // directly: the shared validation body sits at a different real nesting depth depending on
                // whether collectErrors() wraps it in a bare block or a try {} - one literal indentation can't
                // be correct for both, so the body is written once, at its own canonical depth, and the two
                // call sites in SchemaDependency.phptpl each indent the rendered result to their own real depth.
                'renderSchemaDependencyBody' => function () use ($generatorConfiguration, $nestedProperty): string {
                    return $this->getRenderer()->renderTemplate(
                        DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'SchemaDependencyBody.phptpl',
                        [
                            'viewHelper' => new RenderHelper($generatorConfiguration),
                            'nestedProperty' => $nestedProperty,
                        ],
                    );
                },
            ],
            InvalidSchemaDependencyException::class,
            ['&$dependencyException'],
        );
    }

    /**
     * Initialize all variables which are required to execute a property names validator
     */
    public function getValidatorSetUp(): string
    {
        return '$dependencyException = null;';
    }
}
