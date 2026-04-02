<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

abstract class SimplePropertyValidatorFactory extends AbstractValidatorFactory
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

        $property->addValidator(
            $this->getValidator($property, $propertySchema->getJson()[$this->key]),
        );
    }

    protected function hasValidValue(PropertyInterface $property, JsonSchema $propertySchema): bool
    {
        $json = $propertySchema->getJson();

        if (!isset($json[$this->key])) {
            return false;
        }

        if (!$this->isValueValid($json[$this->key])) {
            throw new SchemaException(
                sprintf(
                    "Invalid %s %s for property '%s' in file %s",
                    $this->key,
                    str_replace("\n", '', var_export($json[$this->key], true)),
                    $property->getName(),
                    $propertySchema->getFile(),
                ),
            );
        }

        return true;
    }

    abstract protected function isValueValid(mixed $value): bool;

    abstract protected function getValidator(PropertyInterface $property, mixed $value): PropertyValidatorInterface;
}
