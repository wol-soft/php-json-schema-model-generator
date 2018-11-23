<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\SchemaProperty;

use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\SchemaProcessor\SchemaPropertyProcessorInterface;

/**
 * Class PropertiesProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor\SchemaProperty
 */
class PropertiesProcessor implements SchemaPropertyProcessorInterface
{
    /**
     * @inheritdoc
     */
    public function process(SchemaProcessor $schemaProcessor, Schema $schema, array $structure): void
    {

    }
}
