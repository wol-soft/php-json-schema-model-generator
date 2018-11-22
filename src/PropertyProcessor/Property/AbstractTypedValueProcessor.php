<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\TypeCheckValidator;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Class AbstractScalarValueProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
abstract class AbstractTypedValueProcessor extends AbstractValueProcessor
{
    protected const TYPE = '';

    /**
     * AbstractTypedValueProcessor constructor.
     *
     * @param PropertyCollectionProcessor $propertyCollectionProcessor
     * @param SchemaProcessor             $schemaProcessor
     * @param Schema                      $schema
     */
    public function __construct(
        PropertyCollectionProcessor $propertyCollectionProcessor,
        SchemaProcessor $schemaProcessor,
        Schema $schema
    ) {
        parent::__construct($propertyCollectionProcessor, $schemaProcessor, $schema, static::TYPE);
    }

    /**
     * @inheritdoc
     */
    protected function generateValidators(PropertyInterface $property, array $propertyData): void
    {
        parent::generateValidators($property, $propertyData);

        $property->addValidator(new TypeCheckValidator(static::TYPE, $property), 2);
    }

    protected function getTypeCheck(): string
    {
        return 'is_' . strtolower(static::TYPE) . '($value) && ';
    }
}
