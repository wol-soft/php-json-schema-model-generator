<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor\Internal;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\UpdateAdditionalPropertiesValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\ArrayTypeHintDecorator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;

class AdditionalPropertiesPostProcessor extends PostProcessor
{
    /**
     * @throws SchemaException
     */
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $json = $schema->getJsonSchema()->getJson();

        // The validators decide as well as the keyword: a base-level `$ref` transfers the referenced
        // schema's AdditionalPropertiesValidator, which is one shared instance the referenced schema has
        // already switched to collecting. It writes `_additionalProperties` into every class that renders
        // it, so a class running it has to declare the field although its own JSON never spells the
        // keyword — created dynamically the field is deprecated since PHP 8.2 and an error in PHP 9.
        if (
            (isset($json['additionalProperties']) && $json['additionalProperties'] !== false)
            || $schema->getAdditionalPropertiesValidators() !== []
        ) {
            $this->addAdditionalPropertiesCollectionProperty($schema);
        }
    }

    /**
     * @throws SchemaException
     */
    public function addAdditionalPropertiesCollectionProperty(Schema $schema): void
    {
        // Declared by an earlier call: the internal post processor and the accessor post processor reach the
        // same schema when it declares the keyword and also receives a transferred validator. Only an internal
        // property counts, a schema may declare a property which is merely named like the keyword.
        if ($schema->getProperty('additionalProperties')?->isInternal()) {
            return;
        }

        $validationProperty = null;
        foreach ($schema->getAdditionalPropertiesValidators() as $validator) {
            $validator->setCollectAdditionalProperties(true);
            $validationProperty = $validator->getValidationProperty();
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

        // Only a schema without an additionalProperties validator needs one to store its additional properties
        // (nothing validates them). Where a validator exists - declared or transferred - it stores them itself,
        // and a second storage would list the keys the referenced schema declares as additional properties of this
        // class as well.
        $json = $schema->getJsonSchema()->getJson();
        if (
            $schema->getAdditionalPropertiesValidators() === []
            && (!isset($json['additionalProperties']) || $json['additionalProperties'] === true)
        ) {
            $this->addUpdateAdditionalProperties($schema);
        }
    }

    private function addUpdateAdditionalProperties(Schema $schema): void
    {
        $schema->addBaseValidator(new UpdateAdditionalPropertiesValidator($schema));
    }
}
