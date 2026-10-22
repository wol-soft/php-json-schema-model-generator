<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Dependency\InvalidPropertyDependencyException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class PropertyDependencyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class PropertyDependencyValidator extends PropertyTemplateValidator implements
    DependencyValidatorInterface,
    ObjectUpdateDependentValidatorInterface
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

    /**
     * The dependency requires keys to be present: an update never removes a key, it can't violate the dependency of
     * another property. The dependency of the updated property itself is validated with the property.
     */
    public function getObjectUpdateDependency(string $key): ObjectUpdateDependency
    {
        return ObjectUpdateDependency::None;
    }
}
