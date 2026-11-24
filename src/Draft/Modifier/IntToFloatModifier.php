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
    /**
     * Adds the cast once, like TypeCheckModifier adds the type check once: the type modifiers of a
     * property may run more than once (the merge of a `$ref` with its siblings re-applies them for
     * the merged type), and a second cast decorator is rendered nested into the first one, which
     * produces code PHP can't parse (an unparenthesized nested ternary).
     */
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        foreach ($property->getDecorators() as $decorator) {
            if ($decorator instanceof IntToFloatCastDecorator) {
                return;
            }
        }

        $property->addDecorator(new IntToFloatCastDecorator());
    }
}
