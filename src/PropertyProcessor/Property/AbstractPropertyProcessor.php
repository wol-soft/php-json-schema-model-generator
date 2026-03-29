<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorInterface;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Class AbstractPropertyProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
abstract class AbstractPropertyProcessor implements PropertyProcessorInterface
{
    public function __construct(
        protected SchemaProcessor $schemaProcessor,
        protected Schema $schema,
        protected bool $required = false,
    ) {}

    /**
     * Generates the validators for the property
     *
     * @throws SchemaException
     */
    protected function generateValidators(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        if ($property->isRequired() && !str_starts_with($property->getName(), 'item of array ')) {
            $property->addValidator(new RequiredPropertyValidator($property), 1);
        }
    }

    /**
     * Check if implicit null values are allowed for the given property (a not required property which has no
     * explicit null type and is passed with a null value will be accepted)
     */
    protected function isImplicitNullAllowed(PropertyInterface $property): bool
    {
        return $this->schemaProcessor->getGeneratorConfiguration()->isImplicitNullAllowed() && !$property->isRequired();
    }
}
