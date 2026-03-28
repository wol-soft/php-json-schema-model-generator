<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Object;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Model\Validator\PatternPropertiesValidator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

class PatternPropertiesValidatorFactory extends AbstractValidatorFactory
{
    /**
     * @throws SchemaException
     */
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $json = $propertySchema->getJson();

        if (!isset($json[$this->key])) {
            return;
        }

        foreach ($json[$this->key] as $pattern => $patternSchema) {
            $escapedPattern = addcslashes((string) $pattern, '/');

            if (@preg_match("/$escapedPattern/", '') === false) {
                throw new SchemaException(
                    "Invalid pattern '$pattern' for pattern property in file {$propertySchema->getFile()}",
                );
            }

            $schema->addBaseValidator(
                new PatternPropertiesValidator(
                    $schemaProcessor,
                    $schema,
                    $pattern,
                    $propertySchema->withJson($patternSchema),
                )
            );
        }
    }
}
