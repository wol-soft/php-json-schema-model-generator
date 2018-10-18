<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\SchemaProperty;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\SchemaProcessor\SchemaPropertyProcessorInterface;

/**
 * Class MinpropertiesProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor\SchemaProperty
 */
class MinpropertiesProcessor implements SchemaPropertyProcessorInterface
{
    /**
     * @inheritdoc
     */
    public function process(SchemaProcessor $schemaProcessor, Schema $schema, array $structure): void
    {
        $schema->addBaseValidator(
            new PropertyValidator(
                sprintf('count($modelData) < %d', $structure['minProperties']),
                InvalidArgumentException::class,
                "Provided object must not contain less than {$structure['minProperties']} properties"
            )
        );
    }
}
