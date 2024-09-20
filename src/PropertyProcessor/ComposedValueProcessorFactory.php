<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\PropertyProcessor\ComposedValue\AbstractComposedValueProcessor;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Class ComposedValueProcessorFactory
 *
 * @package PHPModelGenerator\PropertyProcessor
 */
class ComposedValueProcessorFactory implements ProcessorFactoryInterface
{
    /**
     * ComposedValueProcessorFactory constructor.
     *
     * @param bool $rootLevelComposition is the composed value on object root level (true) or on property level (false)?
     */
    public function __construct(private bool $rootLevelComposition) {}

    /**
     * @inheritdoc
     *
     * @throws SchemaException
     */
    public function getProcessor(
        $type,
        PropertyMetaDataCollection $propertyMetaDataCollection,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
    ): PropertyProcessorInterface {
        $processor = '\\PHPModelGenerator\\PropertyProcessor\\ComposedValue\\' . ucfirst($type) . 'Processor';

        $params = [$propertyMetaDataCollection, $schemaProcessor, $schema];

        if (is_a($processor, AbstractComposedValueProcessor::class, true)) {
            $params[] = $this->rootLevelComposition;
        }

        return new $processor(...$params);
    }
}
