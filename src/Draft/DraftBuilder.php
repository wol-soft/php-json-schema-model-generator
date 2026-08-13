<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft;

use PHPModelGenerator\Draft\Element\Type;
use PHPModelGenerator\Draft\Producer\PropertyProducerInterface;

class DraftBuilder
{
    /** @var Type[] */
    private array $types = [];

    /** @var PropertyProducerInterface[] Keyed by keyword; insertion order preserved */
    private array $producers = [];

    public function addType(Type $type): self
    {
        $this->types[$type->getType()] = $type;

        return $this;
    }

    public function getType(string $type): ?Type
    {
        return $this->types[$type] ?? null;
    }

    /**
     * Register a producer for a schema keyword. Re-registering a keyword overwrites the producer
     * while keeping its registry position
     */
    public function addProducer(string $keyword, PropertyProducerInterface $producer): self
    {
        $this->producers[$keyword] = $producer;

        return $this;
    }

    public function build(): Draft
    {
        return new Draft($this->types, $this->producers);
    }
}
