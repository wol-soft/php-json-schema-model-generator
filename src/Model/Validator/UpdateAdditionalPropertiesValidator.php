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
class UpdateAdditionalPropertiesValidator extends PropertyTemplateValidator implements
    ObjectUpdateDependentValidatorInterface
{
    /** @var string[] */
    private readonly array $declaredProperties;

    public function __construct(Schema $schema)
    {
        $patternProperties = array_keys($schema->getJsonSchema()->getJson()['patternProperties'] ?? []);
        // numeric property names are integer array keys
        $this->declaredProperties = array_map(
            'strval',
            array_keys($schema->getJsonSchema()->getJson()['properties'] ?? []),
        );

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
                    ? RenderHelper::varExportPcrePatterns($patternProperties)
                    : null,
                'additionalProperties' => RenderHelper::varExportArray(
                    array_keys($schema->getJsonSchema()->getJson()['properties'] ?? []),
                ),
            ],
            InvalidAdditionalPropertiesException::class,
        );
    }

    /**
     * Only stores the additional properties, never rejects a value: the result of the validator can't change with an
     * update, so e.g. a composition containing it keeps its cached result. Whether the validator has to run to keep
     * the stored values up to date is answered by storesKey().
     */
    public function getObjectUpdateDependency(string $key): ObjectUpdateDependency
    {
        return ObjectUpdateDependency::None;
    }

    /**
     * Whether the validator stores the value of the key: a key which isn't declared by the properties keyword (e.g. a
     * property declared by a composition branch) is an additional property. The setter of the typed property of such
     * a key must update the stored value as well, the typed property and the stored additional property are two views
     * of the same key. Reporting a dependency on the value via getObjectUpdateDependency() was rejected: it made a
     * composition containing the validator validate its branches again for every update of an undeclared key.
     */
    public function storesKey(string $key): bool
    {
        return !in_array($key, $this->declaredProperties, true);
    }

    public function getUndeclaredKeyDependency(): ObjectUpdateDependency
    {
        return ObjectUpdateDependency::None;
    }
}
