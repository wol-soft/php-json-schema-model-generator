<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
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
    /** @var string */
    private $variableSuffix = '';
    /** @var PropertyInterface */
    private $nestedProperty;

    /**
     * ArrayItemValidator constructor.
     *
     * @param SchemaProcessor   $schemaProcessor
     * @param Schema            $schema
     * @param JsonSchema        $itemStructure
     * @param PropertyInterface $property
     *
     * @throws SchemaException
     */
    public function __construct(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        JsonSchema $itemStructure,
        PropertyInterface $property
    ) {
        $nestedPropertyName = "item of array {$property->getName()}";
        $this->variableSuffix = '_' . md5($nestedPropertyName);

        // an item of the array behaves like a nested property to add item-level validation
        $this->nestedProperty = (new PropertyFactory(new PropertyProcessorFactory()))
            ->create(
                new PropertyMetaDataCollection([$nestedPropertyName]),
                $schemaProcessor,
                $schema,
                $nestedPropertyName,
                $itemStructure
            );

        $property->addTypeHintDecorator(new ArrayTypeHintDecorator($this->nestedProperty));

        parent::__construct(
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ArrayItem.phptpl',
            [
                'nestedProperty' => $this->nestedProperty,
                'viewHelper' => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
                'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                'suffix' => $this->variableSuffix,
            ],
            InvalidItemException::class,
            ["&\$invalidItems{$this->variableSuffix}"]
        );
    }

    /**
     * @inheritDoc
     */
    public function getCheck(): string
    {
        $this->removeRequiredPropertyValidator($this->nestedProperty);

        return parent::getCheck();
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
