<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use JsonSerializable;
use PHPModelGenerator\Interfaces\SerializationInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Traits\SerializableTrait;

/**
 * Class SerializationPostProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor\PostProcessor
 */
class SerializationPostProcessor implements PostProcessorInterface
{
    public function process(Schema $schema): void
    {
        $schema
            ->addTrait(SerializableTrait::class)
            ->addInterface(JsonSerializable::class)
            ->addInterface(SerializationInterface::class);
    }
}
