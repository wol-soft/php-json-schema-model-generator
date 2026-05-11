<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft\Modifier;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\ContentValidator;
use PHPModelGenerator\PropertyProcessor\Filter\FilterProcessor;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use ReflectionException;

/**
 * Handles contentMediaType and contentEncoding keywords on string properties.
 *
 * When either keyword is present, the property is transformed from a plain string to a
 * MediaString (mutable) or ImmutableMediaString (readOnly / writeOnly / global immutability)
 * by wiring the appropriate built-in transforming filter via FilterProcessor.
 *
 * The filter is registered at startPriority=100 — above the default format validator priority
 * of 99 — so that format validation runs on the raw string before the transformation.
 */
class MediaStringModifier implements ModifierInterface
{
    private const FILTER_START_PRIORITY = 100;

    /**
     * @throws SchemaException
     * @throws ReflectionException
     */
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $json = $propertySchema->getJson();

        if (!isset($json['contentMediaType']) && !isset($json['contentEncoding'])) {
            return;
        }

        $generatorConfiguration = $schemaProcessor->getGeneratorConfiguration();

        $useImmutable = $property->isReadOnly()
            || $property->isWriteOnly()
            || $generatorConfiguration->isImmutable();

        $mediaType = $json['contentMediaType'] ?? null;
        $encoding  = $json['contentEncoding'] ?? null;

        (new FilterProcessor())->process(
            $property,
            [
                'filter'    => $useImmutable ? 'immutableMediaString' : 'mediaString',
                'mediaType' => $mediaType,
                'encoding'  => $encoding,
            ],
            $generatorConfiguration,
            $schema,
            self::FILTER_START_PRIORITY,
        );

        $contentValidator = $generatorConfiguration->getContentValidator($mediaType, $encoding);
        if ($contentValidator !== null) {
            $property->addValidator(new ContentValidator($property, $contentValidator, $mediaType, $encoding));
        }
    }
}
