<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Dependency\InvalidPropertyDependencyException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class PropertyDependencyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class PropertyDependencyValidator extends PropertyTemplateValidator
{
    /**
     * PropertyDependencyValidator constructor.
     */
    public function __construct(PropertyInterface $property, array $dependencies)
    {
        $this->isResolved = true;

        parent::__construct(
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'PropertyDependency.phptpl',
            [
                'dependencies' => RenderHelper::varExportArray(array_values($dependencies)),
            ],
            InvalidPropertyDependencyException::class,
            ['&$missingAttributes'],
        );
    }

    /**
     * Initialize all variables which are required to execute a property names validator
     */
    public function getValidatorSetUp(): string
    {
        return '$missingAttributes = [];';
    }
}
