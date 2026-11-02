<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Object\AdditionalPropertiesException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\RenderHelper;

class NoAdditionalPropertiesValidator extends PropertyTemplateValidator implements
    ObjectUpdateDependentValidatorInterface
{
    /** @var string[] */
    private readonly array $declaredProperties;

    public function __construct(PropertyInterface $property, array $json)
    {
        $this->isResolved = true;
        // numeric property names are integer array keys
        $this->declaredProperties = array_map('strval', array_keys($json['properties'] ?? []));

        parent::__construct(
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'NoAdditionalProperties.phptpl',
            [
                'properties' => RenderHelper::varExportArray(array_keys($json['properties'] ?? [])),
                // Delimiter of the regex first, then the single quoted string of the template: escaping the quote
                // together with the delimiter left a backslash of a pattern unescaped, the regex of the string differs.
                'pattern' => RenderHelper::escapeSingleQuoted(
                    addcslashes(join('|', array_keys($json['patternProperties'] ?? [])), '/'),
                ),
            ],
            AdditionalPropertiesException::class,
            ['&$additionalProperties'],
        );
    }

    /**
     * A declared property is no additional property, any other key is rejected by its presence.
     */
    public function getObjectUpdateDependency(string $key): ObjectUpdateDependency
    {
        return in_array($key, $this->declaredProperties, true)
            ? ObjectUpdateDependency::None
            : ObjectUpdateDependency::AddedKey;
    }

    public function getUndeclaredKeyDependency(): ObjectUpdateDependency
    {
        return ObjectUpdateDependency::AddedKey;
    }
}
