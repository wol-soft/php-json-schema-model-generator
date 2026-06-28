<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft\Producer;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * A producer creates (resolves/replaces) the property for a schema keyword, in contrast to a
 * {@see \PHPModelGenerator\Draft\Modifier\ModifierInterface}, which only augments an already
 * built property. A producer is required for keywords such as $ref that must *return* the
 * property object — something ModifierInterface::modify() cannot do, as it returns void.
 *
 * Producers are independent: produce() receives no incoming property and resolves only.
 * Combining the output of multiple producers and applying sibling keywords is the caller's
 * responsibility, never the producer's.
 */
interface PropertyProducerInterface
{
    public function produce(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $propertyName,
        JsonSchema $propertySchema,
        bool $required,
    ): PropertyInterface;
}
