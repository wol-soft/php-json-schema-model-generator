<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor\Internal;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\AdditionalPropertiesValidator;
use PHPModelGenerator\Model\Validator\UpdateAdditionalPropertiesValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\ArrayTypeHintDecorator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;

/**
 * Class AdditionalPropertiesPostProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor\PostProcessor\Internal
 */
class AdditionalPropertiesPostProcessor extends PostProcessor
{
    /**
     * @throws SchemaException
     */
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $json = $schema->getJsonSchema()->getJson();

        if (isset($json['additionalProperties']) && $json['additionalProperties'] !== false) {
            $this->addAdditionalPropertiesCollectionProperty($schema);
        }
    }

    /**
     * @throws SchemaException
     */
    public function addAdditionalPropertiesCollectionProperty(Schema $schema): void
    {
        $validationProperty = null;
        foreach ($schema->getBaseValidators() as $validator) {
            if (is_a($validator, AdditionalPropertiesValidator::class)) {
                $validator->setCollectAdditionalProperties(true);
                $validationProperty = $validator->getValidationProperty();
            }
        }

        $additionalPropertiesCollectionProperty = (new Property(
            'additionalProperties',
            new PropertyType('array'),
            new JsonSchema(__FILE__, []),
            'Collect all additional properties provided to the schema',
        ))
            ->setDefaultValue([])
            ->setInternal(true);

        if ($validationProperty) {
            $additionalPropertiesCollectionProperty->addTypeHintDecorator(
                new ArrayTypeHintDecorator($validationProperty),
            );
        }

        $schema->addProperty($additionalPropertiesCollectionProperty);

        $json = $schema->getJsonSchema()->getJson();
        if (!isset($json['additionalProperties']) || $json['additionalProperties'] === true) {
            $this->addUpdateAdditionalProperties($schema);
        }
    }

    private function addUpdateAdditionalProperties(Schema $schema): void
    {
        $schema->addBaseValidator(new UpdateAdditionalPropertiesValidator($schema));
    }
}
