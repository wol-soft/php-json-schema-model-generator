<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

abstract class SimpleBaseValidatorFactory extends SimplePropertyValidatorFactory
{
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        if (!$this->hasValidValue($property, $propertySchema)) {
            return;
        }

        $schema->addBaseValidator(
            $this->getValidator($property, $propertySchema->getJson()[$this->key]),
        );
    }
}
