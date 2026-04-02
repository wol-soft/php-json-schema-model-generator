<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Object;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\AdditionalPropertiesValidator;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Model\Validator\NoAdditionalPropertiesValidator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

class AdditionalPropertiesValidatorFactory extends AbstractValidatorFactory
{
    /**
     * @throws SchemaException
     */
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $json = $propertySchema->getJson();

        if (
            !isset($json[$this->key]) &&
            $schemaProcessor->getGeneratorConfiguration()->denyAdditionalProperties()
        ) {
            $json[$this->key] = false;
        }

        if (!isset($json[$this->key]) || $json[$this->key] === true) {
            return;
        }

        if (!is_bool($json[$this->key])) {
            $schema->addBaseValidator(
                new AdditionalPropertiesValidator(
                    $schemaProcessor,
                    $schema,
                    $propertySchema,
                )
            );

            return;
        }

        $schema->addBaseValidator(
            new NoAdditionalPropertiesValidator(
                new Property($schema->getClassName(), null, $propertySchema),
                $json,
            )
        );
    }
}
