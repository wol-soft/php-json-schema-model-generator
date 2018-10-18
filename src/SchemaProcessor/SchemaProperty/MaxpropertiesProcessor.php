<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\SchemaProperty;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\SchemaProcessor\SchemaPropertyProcessorInterface;

/**
 * Class MaxpropertiesProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor\SchemaProperty
 */
class MaxpropertiesProcessor implements SchemaPropertyProcessorInterface
{
    /**
     * @inheritdoc
     */
    public function process(SchemaProcessor $schemaProcessor, Schema $schema, array $structure): void
    {
        $schema->addBaseValidator(
            new PropertyValidator(
                sprintf('count($modelData) > %d', $structure['maxProperties']),
                InvalidArgumentException::class,
                "Provided object must not contain more than {$structure['maxProperties']} properties"
            )
        );
    }
}
