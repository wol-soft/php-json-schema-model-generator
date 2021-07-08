<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Object\AdditionalPropertiesException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class NoAdditionalPropertiesValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class NoAdditionalPropertiesValidator extends PropertyTemplateValidator
{
    /**
     * PropertyDependencyValidator constructor.
     *
     * @param PropertyInterface $property
     * @param array $json
     */
    public function __construct(PropertyInterface $property, array $json)
    {
        parent::__construct(
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'NoAdditionalProperties.phptpl',
            [
                'properties' => RenderHelper::varExportArray(array_keys($json['properties'] ?? [])),
                'pattern' => addcslashes(join('|', array_keys($json['patternProperties'] ?? [])), "'/"),
            ],
            AdditionalPropertiesException::class,
            ['&$additionalProperties']
        );
    }
}
