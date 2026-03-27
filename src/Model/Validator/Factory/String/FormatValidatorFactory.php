<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\String;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Model\Validator\FormatValidator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

class FormatValidatorFactory extends AbstractValidatorFactory
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

        $format = $json[$this->key];
        $formatValidator = $schemaProcessor->getGeneratorConfiguration()->getFormat($format);

        if (!$formatValidator) {
            throw new SchemaException(
                sprintf(
                    'Unsupported format %s for property %s in file %s',
                    $format,
                    $property->getName(),
                    $propertySchema->getFile(),
                ),
            );
        }

        $property->addValidator(
            new FormatValidator(
                $property,
                $formatValidator,
                [$format],
            ),
        );
    }
}
