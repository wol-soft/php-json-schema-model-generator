<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft\Modifier;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\IntegralFloatToIntCastDecorator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * An integer property accepts an integral float and holds it as int (see IntegralFloatToIntCastDecorator)
 */
class IntegralFloatToIntModifier implements ModifierInterface
{
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $property->addDecorator(new IntegralFloatToIntCastDecorator());
    }
}
