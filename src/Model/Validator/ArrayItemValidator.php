<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPMicroTemplate\Exception\FileSystemException;
use PHPMicroTemplate\Exception\SyntaxErrorException;
use PHPMicroTemplate\Exception\UndefinedSymbolException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\ArrayTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\PropertyMetaDataCollection;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class ArrayItemValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class ArrayItemValidator extends PropertyTemplateValidator
{
    private $variableSuffix = '';

    /**
     * ArrayItemValidator constructor.
     *
     * @param SchemaProcessor   $schemaProcessor
     * @param Schema            $schema
     * @param array             $itemStructure
     * @param PropertyInterface $property
     *
     * @throws SchemaException
     * @throws FileSystemException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    public function __construct(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        array $itemStructure,
        PropertyInterface $property
    ) {
        $this->variableSuffix = '_' . uniqid();

        // an item of the array behaves like a nested property to add item-level validation
        $nestedProperty = (new PropertyFactory(new PropertyProcessorFactory()))
            ->create(
                new PropertyMetaDataCollection(),
                $schemaProcessor,
                $schema,
                "item of array {$property->getName()}",
                $itemStructure
            );

        $this->removeRequiredPropertyValidator($nestedProperty);
        $property->addTypeHintDecorator(new ArrayTypeHintDecorator($nestedProperty));

        parent::__construct(
            $this->getRenderer()->renderTemplate(
                DIRECTORY_SEPARATOR . 'Exception' . DIRECTORY_SEPARATOR . 'InvalidArrayItemsException.phptpl',
                ['propertyName' => $property->getName(), 'suffix' => $this->variableSuffix]
            ),
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ArrayItem.phptpl',
            [
                'nestedProperty' => $nestedProperty,
                'viewHelper' => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
                'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                'suffix' => $this->variableSuffix,
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
        return "\$invalidItems{$this->variableSuffix} = [];";
    }
}
