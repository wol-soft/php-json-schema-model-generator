<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Object\InvalidAdditionalPropertiesException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Stores the additional properties of a schema without additional property constraints. Usually the
 * AdditionalPropertiesValidator validates all additional properties against the constraints and updates the internal
 * storage of the additional properties, without constraints no AdditionalPropertiesValidator is added to the
 * generated class.
 *
 * The validator never rejects a value.
 */
class UpdateAdditionalPropertiesValidator extends PropertyTemplateValidator
{
    public function __construct(Schema $schema)
    {
        $patternProperties = array_keys($schema->getJsonSchema()->getJson()['patternProperties'] ?? []);

        parent::__construct(
            new Property($schema->getClassName(), null, $schema->getJsonSchema()),
            join(
                DIRECTORY_SEPARATOR,
                [
                    '..',
                    'SchemaProcessor',
                    'PostProcessor',
                    'Templates',
                    'AdditionalProperties',
                    'UpdateAdditionalProperties.phptpl',
                ],
            ),
            [
                'patternProperties' => $patternProperties
                    ? RenderHelper::varExportArray($patternProperties)
                    : null,
                'additionalProperties' => RenderHelper::varExportArray(
                    array_keys($schema->getJsonSchema()->getJson()['properties'] ?? []),
                ),
            ],
            InvalidAdditionalPropertiesException::class,
        );
    }
}
