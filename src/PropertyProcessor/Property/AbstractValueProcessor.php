<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Class AbstractScalarValueProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
abstract class AbstractValueProcessor extends AbstractPropertyProcessor
{
    private $type = '';

    /**
     * AbstractValueProcessor constructor.
     *
     * @param PropertyCollectionProcessor $propertyCollectionProcessor
     * @param SchemaProcessor             $schemaProcessor
     * @param Schema                      $schema
     * @param string                      $type
     */
    public function __construct(
        PropertyCollectionProcessor $propertyCollectionProcessor,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $type = ''
    ) {
        parent::__construct($propertyCollectionProcessor, $schemaProcessor, $schema);
        $this->type = $type;
    }

    /**
     * @inheritdoc
     */
    public function process(string $propertyName, array $propertyData): PropertyInterface
    {
        $property = (new Property($propertyName, $this->type))
            ->setDescription($propertyData['description'] ?? '')
            ->setRequired($this->propertyCollectionProcessor->isAttributeRequired($propertyName));

        $this->generateValidators($property, $propertyData);

        return $property;
    }
}
