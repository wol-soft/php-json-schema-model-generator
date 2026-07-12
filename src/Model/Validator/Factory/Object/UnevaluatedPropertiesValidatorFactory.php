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

        // A sibling `additionalProperties` (or the effective `false` produced by the
        // `denyAdditionalProperties()` generator flag) short-circuits the unevaluated bucket:
        //   - `true`      — every extra flows to the model unchecked, but the accumulator does
        //                    not credit those keys, so unevaluatedProperties would still fire.
        //                    Emitting it defeats the intent of `additionalProperties: true`
        //                    (accept every extra), so we suppress and warn.
        //   - `{schema}`  — every extra is claimed by additionalProperties, leaving the
        //                    unevaluated set permanently empty.
        //   - `false`     — every extra is rejected by additionalProperties before the
        //                    post-composition phase, so the unevaluated validator never sees
        //                    any keys.
        $deadCodeReason = $this->deadCodeReason($schemaProcessor, $json);
        if ($deadCodeReason !== null) {
            if ($schemaProcessor->getGeneratorConfiguration()->isOutputEnabled()) {
                echo sprintf(
                    "Warning: unevaluatedProperties on %s is dead code — %s\n",
                    $schema->getClassName(),
                    $deadCodeReason,
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

    /**
     * Returns the human-readable reason unevaluatedProperties is dead code, or null when the
     * validator must still be emitted. Consolidates the four sibling shapes that leave the
     * unevaluated bucket permanently empty at this schema level.
     */
    private function deadCodeReason(SchemaProcessor $schemaProcessor, array $json): ?string
    {
        if (array_key_exists('additionalProperties', $json)) {
            $additionalProperties = $json['additionalProperties'];

            if ($additionalProperties === true) {
                return 'sibling additionalProperties: true accepts every extra without crediting'
                    . ' the unevaluated accumulator';
            }

            if ($additionalProperties === false) {
                return 'sibling additionalProperties: false rejects every extra before the'
                    . ' unevaluated phase runs';
            }

            return 'sibling additionalProperties: {schema} already validates every extra key';
        }

        if ($schemaProcessor->getGeneratorConfiguration()->denyAdditionalProperties()) {
            return 'denyAdditionalProperties() flips missing additionalProperties to false,'
                . ' rejecting every extra before the unevaluated phase runs';
        }

        return null;
    }
}
