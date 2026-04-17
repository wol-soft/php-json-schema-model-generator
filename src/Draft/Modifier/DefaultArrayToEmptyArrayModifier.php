<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft\Modifier;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\DefaultArrayToEmptyArrayDecorator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

class DefaultArrayToEmptyArrayModifier implements ModifierInterface
{
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        if (
            $property->isRequired() ||
            !$schemaProcessor->getGeneratorConfiguration()->isDefaultArraysToEmptyArrayEnabled()
        ) {
            return;
        }

        $property->addDecorator(new DefaultArrayToEmptyArrayDecorator());

        if ($property->getType()) {
            $property->setType(
                $property->getType(),
                new PropertyType($property->getType(true)->getNames(), false),
            );
        }

        if (!$property->getDefaultValue()) {
            $property->setDefaultValue([]);
        }
    }
}
