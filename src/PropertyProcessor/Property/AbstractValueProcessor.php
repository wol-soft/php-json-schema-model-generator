<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\PropertyProcessor\Filter\FilterProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyMetaDataCollection;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use ReflectionException;

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
     * @param PropertyMetaDataCollection $propertyMetaDataCollection
     * @param SchemaProcessor            $schemaProcessor
     * @param Schema                     $schema
     * @param string                     $type
     */
    public function __construct(
        PropertyMetaDataCollection $propertyMetaDataCollection,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $type = ''
    ) {
        parent::__construct($propertyMetaDataCollection, $schemaProcessor, $schema);
        $this->type = $type;
    }

    /**
     * @inheritdoc
     *
     * @throws ReflectionException
     * @throws SchemaException
     */
    public function process(string $propertyName, JsonSchema $propertySchema): PropertyInterface
    {
        $json = $propertySchema->getJson();

        $property = (new Property(
            $propertyName,
            $this->type ? new PropertyType($this->type) : null,
            $propertySchema,
            $json['description'] ?? ''
        ))
            ->setRequired($this->propertyMetaDataCollection->isAttributeRequired($propertyName))
            ->setReadOnly(
                (isset($json['readOnly']) && $json['readOnly'] === true) ||
                $this->schemaProcessor->getGeneratorConfiguration()->isImmutable()
            );

        $this->generateValidators($property, $propertySchema);

        if (isset($json['filter'])) {
            (new FilterProcessor())->process(
                $property,
                $json['filter'],
                $this->schemaProcessor->getGeneratorConfiguration(),
                $this->schema
            );
        }

        return $property;
    }
}
