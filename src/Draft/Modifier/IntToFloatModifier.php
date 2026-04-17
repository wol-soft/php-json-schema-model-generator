<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft\Modifier;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\IntToFloatCastDecorator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

class IntToFloatModifier implements ModifierInterface
{
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $property->addDecorator(new IntToFloatCastDecorator());
    }
}
