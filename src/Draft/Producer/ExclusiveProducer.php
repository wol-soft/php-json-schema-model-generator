<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft\Producer;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Marks the wrapped producer as exclusive over sibling keywords: when a schema node carries this
 * producer's keyword, every other keyword on the same node is suppressed. This models the Draft-07
 * (and earlier) $ref semantics, where the presence of $ref means all sibling keywords are ignored.
 *
 * The decorator carries no behaviour of its own beyond delegating produce(); the exclusivity is a
 * marker the caller detects (via instanceof) to decide whether to skip the sibling applicator loop.
 * Keeping it a decorator rather than a flag on the interface keeps the producer contract a single
 * method and makes the policy reusable for future exclusive keywords.
 */
final class ExclusiveProducer implements PropertyProducerInterface
{
    public function __construct(private readonly PropertyProducerInterface $producer)
    {
    }

    public function produce(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $propertyName,
        JsonSchema $propertySchema,
        bool $required,
        bool $isArrayItem = false,
    ): PropertyInterface {
        return $this->producer->produce(
            $schemaProcessor,
            $schema,
            $propertyName,
            $propertySchema,
            $required,
            $isArrayItem,
        );
    }
}
