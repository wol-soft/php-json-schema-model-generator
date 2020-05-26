<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class PropertyDependencyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class PropertyDependencyValidator extends PropertyTemplateValidator
{
    /**
     * PropertyDependencyValidator constructor.
     *
     * @param PropertyInterface $property
     * @param array $dependencies
     */
    public function __construct(PropertyInterface $property, array $dependencies)
    {
        parent::__construct(
            sprintf(
                'Missing required attributes which are dependants of %s:' .
                    '\n  - " . join("\n  - ", $missingAttributes) . "',
                $property->getName(),
            ),
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'PropertyDependency.phptpl',
            [
                'propertyName' => $property->getName(),
                'dependencies' => preg_replace(
                    '(\d+\s=>)',
                    '',
                    var_export(array_values($dependencies), true),
                ),
            ],
        );
    }

    /**
     * Initialize all variables which are required to execute a property names validator
     *
     * @return string
     */
    public function getValidatorSetUp(): string
    {
        return '$missingAttributes = [];';
    }
}
