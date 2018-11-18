<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor;

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
    /** @var PropertyProcessorFactory */
    protected $propertyProcessorFactory;

    /**
     * PropertyFactory constructor.
     */
    public function __construct()
    {
        $this->propertyProcessorFactory = new PropertyProcessorFactory();
    }

    /**
     * Create a property
     *
     * @param PropertyCollectionProcessor $propertyCollectionProcessor
     * @param SchemaProcessor             $schemaProcessor
     * @param Schema                      $schema
     * @param string                      $propertyName
     * @param array                       $propertyStructure
     *
     * @return PropertyInterface
     */
    public function create(
        PropertyCollectionProcessor $propertyCollectionProcessor,
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

        $property = $this->propertyProcessorFactory
            ->getPropertyProcessor(
                $propertyStructure['type'] ?? 'any',
                $propertyCollectionProcessor,
                $schemaProcessor,
                $schema
            )
            ->process($propertyName, $propertyStructure);

        return $property;
    }
}