<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Object;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Model\Validator\NoUnevaluatedPropertiesValidator;
use PHPModelGenerator\Model\Validator\UnevaluatedPropertiesValidator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

class UnevaluatedPropertiesValidatorFactory extends AbstractValidatorFactory
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

        // `unevaluatedProperties: true` is the spec default — every unevaluated key is allowed,
        // so no validator is needed. Absent keyword is treated the same way.
        if (!isset($json[$this->key]) || $json[$this->key] === true) {
            return;
        }

        // When the same schema declares a non-false `additionalProperties`, every key not
        // claimed by `properties`/`patternProperties` is already evaluated by it. Composition
        // contributions cannot retract that — `additionalProperties` only succeeds when every
        // matched key passes — so the unevaluated set is guaranteed empty and the validator
        // would be a no-op. Skip emission entirely.
        if (isset($json['additionalProperties']) && $json['additionalProperties'] !== false) {
            return;
        }

        if ($json[$this->key] === false) {
            $schema->addPostCompositionValidator(
                new NoUnevaluatedPropertiesValidator($schema, $propertySchema),
            );

            return;
        }

        $schema->addPostCompositionValidator(
            new UnevaluatedPropertiesValidator($schemaProcessor, $schema, $propertySchema),
        );
    }
}
