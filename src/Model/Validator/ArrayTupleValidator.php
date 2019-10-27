<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPMicroTemplate\Exception\FileSystemException;
use PHPMicroTemplate\Exception\SyntaxErrorException;
use PHPMicroTemplate\Exception\UndefinedSymbolException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class ArrayTupleValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class ArrayTupleValidator extends PropertyTemplateValidator
{
    /**
     * ArrayTupleValidator constructor.
     *
     * @param SchemaProcessor $schemaProcessor
     * @param Schema          $schema
     * @param array           $propertiesStructure
     * @param string          $propertyName
     *
     * @throws SchemaException
     * @throws FileSystemException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    public function __construct(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        array $propertiesStructure,
        string $propertyName
    ) {
        $propertyFactory = new PropertyFactory(new PropertyProcessorFactory());

        $tupleProperties = [];
        foreach ($propertiesStructure as $tupleIndex => $tupleItem) {
            $tupleItemName = "tuple item #$tupleIndex of array $propertyName";

            // an item of the array behaves like a nested property to add item-level validation
            $tupleProperties[] = $propertyFactory->create(
                new PropertyCollectionProcessor([$tupleItemName]),
                $schemaProcessor,
                $schema,
                $tupleItemName,
                $tupleItem
            );

            $this->removeRequiredPropertyValidator(end($tupleProperties));
        }

        parent::__construct(
            $this->getRenderer()->renderTemplate(
                DIRECTORY_SEPARATOR . 'Exception' . DIRECTORY_SEPARATOR . 'InvalidArrayTuplesException.phptpl',
                ['propertyName' => $propertyName]
            ),
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ArrayTuple.phptpl',
            [
                'tupleProperties' => $tupleProperties,
                'viewHelper' => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
                'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
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
        return '$invalidTuples = [];';
    }
}
