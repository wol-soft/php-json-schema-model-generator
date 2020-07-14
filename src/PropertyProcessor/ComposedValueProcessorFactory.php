<?php

namespace PHPModelGenerator\PropertyProcessor;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Class ComposedValueProcessorFactory
 *
 * @package PHPModelGenerator\PropertyProcessor
 */
class ComposedValueProcessorFactory implements ProcessorFactoryInterface
{
    /** @var bool */
    private $rootLevelComposition;

    /**
     * ComposedValueProcessorFactory constructor.
     *
     * @param bool $rootLevelComposition is the composed value on object root level (true) or on property level (false)?
     */
    public function __construct(bool $rootLevelComposition)
    {
        $this->rootLevelComposition = $rootLevelComposition;
    }

    /**
     * @inheritdoc
     *
     * @throws SchemaException
     */
    public function getProcessor(
        $type,
        PropertyMetaDataCollection $propertyMetaDataCollection,
        SchemaProcessor $schemaProcessor,
        Schema $schema
    ): PropertyProcessorInterface {
        $processor = '\\PHPModelGenerator\\PropertyProcessor\\ComposedValue\\' . ucfirst($type) . 'Processor';

        return new $processor($propertyMetaDataCollection, $schemaProcessor, $schema, $this->rootLevelComposition);
    }
}
