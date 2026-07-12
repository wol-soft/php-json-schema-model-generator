<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\AbstractPropertyValidator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\JsonSchema as JsonSchemaUtil;

abstract class SimpleBaseValidatorFactory extends SimplePropertyValidatorFactory
{
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        if (!$this->hasValidValue($property, $propertySchema)) {
            return;
        }

        $validator = $this->getValidator($property, $propertySchema->getJson()[$this->key]);

        if ($validator instanceof AbstractPropertyValidator) {
            $validator = $validator->withJsonPointer(
                $propertySchema->getPointer() . '/' . JsonSchemaUtil::encodePointer($this->key),
            );
        }

        $schema->addBaseValidator($validator);
    }
}
