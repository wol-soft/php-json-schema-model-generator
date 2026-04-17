<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Arrays;

use PHPModelGenerator\Exception\Arrays\UniqueItemsException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Model\Validator\PropertyTemplateValidator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

class UniqueItemsValidatorFactory extends AbstractValidatorFactory
{
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $json = $propertySchema->getJson();

        if (!isset($json[$this->key]) || $json[$this->key] !== true) {
            return;
        }

        $property->addValidator(
            new PropertyTemplateValidator(
                $property,
                DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ArrayUnique.phptpl',
                [],
                UniqueItemsException::class,
            ),
        );
    }
}
