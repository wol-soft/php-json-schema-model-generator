<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Dependency\InvalidPropertyDependencyException;
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
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'PropertyDependency.phptpl',
            [
                'dependencies' => preg_replace(
                    '(\d+\s=>)',
                    '',
                    var_export(array_values($dependencies), true)
                ),
            ],
            InvalidPropertyDependencyException::class,
            ['&$missingAttributes']
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
