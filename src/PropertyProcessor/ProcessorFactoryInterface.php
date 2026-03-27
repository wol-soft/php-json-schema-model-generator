<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor;

use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Interface ProcessorFactoryInterface
 *
 * @package PHPModelGenerator\PropertyProcessor
 */
interface ProcessorFactoryInterface
{
    public function getProcessor(
        string $type,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        bool $required = false,
    ): PropertyProcessorInterface;
}
