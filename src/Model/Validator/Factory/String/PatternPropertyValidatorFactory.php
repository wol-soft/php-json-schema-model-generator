<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\String;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\String\PatternException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

class PatternPropertyValidatorFactory extends AbstractValidatorFactory
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

        $pattern = (string) $json[$this->key];
        $escapedPattern = addcslashes($pattern, '/');

        if (@preg_match("/$escapedPattern/", '') === false) {
            throw new SchemaException(
                sprintf(
                    "Invalid pattern '%s' for property '%s' in file %s",
                    $pattern,
                    $property->getName(),
                    $propertySchema->getFile(),
                ),
            );
        }

        $encodedPattern = base64_encode("/$escapedPattern/");

        $property->addValidator(
            new PropertyValidator(
                $property,
                "is_string(\$value) && !preg_match(base64_decode('$encodedPattern'), \$value)",
                PatternException::class,
                [$pattern],
            ),
        );
    }
}
