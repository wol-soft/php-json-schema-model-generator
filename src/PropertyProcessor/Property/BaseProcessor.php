<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;

/**
 * Class BaseObjectProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class BaseProcessor extends AbstractPropertyProcessor
{
    protected const TYPE = 'object';

    /**
     * @inheritdoc
     */
    public function process(string $propertyName, array $propertyData): PropertyInterface
    {
        $property = new Property($propertyName, static::TYPE);

        $this->generateValidators($property, $propertyData);

        $this->addAdditionalPropertiesValidator($propertyData);
        $this->addMinPropertiesValidator($propertyData);
        $this->addMaxPropertiesValidator($propertyData);

        $propertyFactory = new PropertyFactory(new PropertyProcessorFactory());
        $propertyCollectionProcessor = new PropertyCollectionProcessor($propertyData['required'] ?? []);

        foreach ($propertyData['properties'] as $propertyName => $propertyStructure) {
            $this->schema->addProperty(
                $propertyFactory->create(
                    $propertyCollectionProcessor,
                    $this->schemaProcessor,
                    $this->schema,
                    $propertyName,
                    $propertyStructure
                )
            );
        }

        return $property;
    }

    public function addAdditionalPropertiesValidator(array $propertyData): void
    {
        if (!isset($propertyData['additionalProperties']) || $propertyData['additionalProperties'] === true) {
            return;
        }

        $this->schema->addBaseValidator(
            new PropertyValidator(
                sprintf(
                    'array_diff(array_keys($modelData), %s)',
                    preg_replace('(\d+\s=>)', '', var_export(array_keys($structure['properties'] ?? []), true))
                ),
                InvalidArgumentException::class,
                'Provided JSON contains not allowed additional properties'
            )
        );
    }

    public function addMaxPropertiesValidator(array $propertyData): void
    {
        if (!isset($propertyData['maxProperties'])) {
            return;
        }

        $this->schema->addBaseValidator(
            new PropertyValidator(
                sprintf('count($modelData) > %d', $propertyData['maxProperties']),
                InvalidArgumentException::class,
                "Provided object must not contain more than {$propertyData['maxProperties']} properties"
            )
        );
    }

    public function addMinPropertiesValidator(array $propertyData): void
    {
        if (!isset($propertyData['minProperties'])) {
            return;
        }

        $this->schema->addBaseValidator(
            new PropertyValidator(
                sprintf('count($modelData) < %d', $propertyData['minProperties']),
                InvalidArgumentException::class,
                "Provided object must not contain less than {$propertyData['minProperties']} properties"
            )
        );
    }
}
