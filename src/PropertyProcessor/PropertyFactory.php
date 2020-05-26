<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
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
     * @param array                      $propertyStructure
     *
     * @return PropertyInterface
     * @throws SchemaException
     */
    public function create(
        PropertyMetaDataCollection $propertyMetaDataCollection,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $propertyName,
        array $propertyStructure
    ): PropertyInterface {
        // redirect properties with a constant value to the ConstProcessor
        if (isset($propertyStructure['const'])) {
            $propertyStructure['type'] = 'const';
        }
        // redirect references to the ReferenceProcessor
        if (isset($propertyStructure['$ref'])) {
            $propertyStructure['type'] = 'reference';
        }

        $property = $this->processorFactory
            ->getProcessor(
                $propertyStructure['type'] ?? 'any',
                $propertyMetaDataCollection,
                $schemaProcessor,
                $schema
            )
            ->process($propertyName, $propertyStructure);

        return $property;
    }
}