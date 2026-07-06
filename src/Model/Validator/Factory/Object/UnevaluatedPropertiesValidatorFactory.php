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

        $unevaluatedProperties = $json[$this->key];

        if (!is_bool($unevaluatedProperties) && !is_array($unevaluatedProperties)) {
            throw new SchemaException(
                sprintf(
                    "Invalid unevaluatedProperties %s for property '%s' in file %s",
                    str_replace("\n", '', var_export($unevaluatedProperties, true)),
                    $schema->getClassName(),
                    $propertySchema->getFile(),
                ),
            );
        }

        // When the same schema declares a non-false `additionalProperties`, every key not
        // claimed by `properties`/`patternProperties` is already evaluated by it. Composition
        // contributions cannot retract that — `additionalProperties` only succeeds when every
        // matched key passes — so the unevaluated set is guaranteed empty and the validator
        // would be a no-op. Skip emission entirely and warn the developer that the keyword
        // is dead code at this schema level.
        if (isset($json['additionalProperties']) && $json['additionalProperties'] !== false) {
            if ($schemaProcessor->getGeneratorConfiguration()->isOutputEnabled()) {
                echo sprintf(
                    "Warning: unevaluatedProperties on %s is dead code — sibling additionalProperties"
                        . " already claims every extra key\n",
                    $schema->getClassName(),
                );
            }

            return;
        }

        $unevaluatedPointer = $propertySchema->getPointer() . '/' . $this->key;

        if ($unevaluatedProperties === false) {
            $schema->addPostCompositionValidator(
                (new NoUnevaluatedPropertiesValidator($schema, $propertySchema))
                    ->withJsonPointer($unevaluatedPointer),
            );

            return;
        }

        $schema->addPostCompositionValidator(
            (new UnevaluatedPropertiesValidator($schemaProcessor, $schema, $propertySchema))
                ->withJsonPointer($unevaluatedPointer),
        );
    }
}
