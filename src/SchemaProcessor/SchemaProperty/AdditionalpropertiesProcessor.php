<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\SchemaProperty;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\SchemaProcessor\SchemaPropertyProcessorInterface;

/**
 * Class AdditionalpropertiesProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor\SchemaProperty
 */
class AdditionalpropertiesProcessor implements SchemaPropertyProcessorInterface
{
    /**
     * @inheritdoc
     */
    public function process(SchemaProcessor $schemaProcessor, Schema $schema, array $structure): void
    {
        if (!isset($structure['additionalProperties']) || $structure['additionalProperties'] === true) {
            return;
        }

        $schema->addBaseValidator(
            new PropertyValidator(
                sprintf(
                    'array_diff(array_keys($modelData), %s)',
                    preg_replace('(\d+\s=>)', '', var_export(array_keys($structure['properties'] ?? []), true))
                ),
                InvalidArgumentException::class,
                'Provided JSON contains not allowed additional properties'
            )
        );
    }
}
