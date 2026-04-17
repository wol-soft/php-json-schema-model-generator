<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft\Modifier;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\TypeConverter;

class DefaultValueModifier implements ModifierInterface
{
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $json = $propertySchema->getJson();

        if (!array_key_exists('default', $json)) {
            return;
        }

        $default = $json['default'];
        $types   = isset($json['type']) ? (array) $json['type'] : [];

        if (empty($types)) {
            $property->setDefaultValue($default);
            return;
        }

        foreach ($types as $jsonType) {
            $phpType = TypeConverter::jsonSchemaToPHP($jsonType);

            // Allow integer literals as defaults for 'number' (float) properties
            if ($phpType === 'float' && is_int($default)) {
                $default = (float) $default;
            }

            $typeCheckFn = 'is_' . $phpType;
            if (function_exists($typeCheckFn) && $typeCheckFn($default)) {
                $property->setDefaultValue($default);
                return;
            }
        }

        throw new SchemaException(
            sprintf(
                'Invalid type for default value of property %s in file %s',
                $property->getName(),
                $propertySchema->getFile(),
            ),
        );
    }
}
