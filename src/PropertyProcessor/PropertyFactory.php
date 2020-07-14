<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Class PropertyFactory
 *
 * @package PHPModelGenerator\PropertyProcessor
 */
class PropertyFactory
{
    /** @var ProcessorFactoryInterface */
    protected $processorFactory;

    /**
     * PropertyFactory constructor.
     *
     * @param ProcessorFactoryInterface $processorFactory
     */
    public function __construct(ProcessorFactoryInterface $processorFactory)
    {
        $this->processorFactory = $processorFactory;
    }

    /**
     * Create a property
     *
     * @param PropertyMetaDataCollection $propertyMetaDataCollection
     * @param SchemaProcessor            $schemaProcessor
     * @param Schema                     $schema
     * @param string                     $propertyName
     * @param JsonSchema                 $propertySchema
     *
     * @return PropertyInterface
     * @throws SchemaException
     */
    public function create(
        PropertyMetaDataCollection $propertyMetaDataCollection,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $propertyName,
        JsonSchema $propertySchema
    ): PropertyInterface {
        $json = $propertySchema->getJson();

        // redirect properties with a constant value to the ConstProcessor
        if (isset($json['const'])) {
            $json['type'] = 'const';
        }
        // redirect references to the ReferenceProcessor
        if (isset($json['$ref'])) {
            $json['type'] = isset($json['type']) && $json['type'] === 'base'
                ? 'baseReference'
                : 'reference';
        }

        return $this->processorFactory
            ->getProcessor(
                $json['type'] ?? 'any',
                $propertyMetaDataCollection,
                $schemaProcessor,
                $schema
            )
            ->process($propertyName, $propertySchema);
    }
}