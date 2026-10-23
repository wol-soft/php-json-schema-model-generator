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
    /** @var string[] */
    private readonly array $dependencies;

    public function __construct(PropertyInterface $property, array $dependencies)
    {
        $this->isResolved = true;
        $this->dependencies = array_map('strval', array_values($dependencies));

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
     * The dependency requires keys to be present: adding the property or a required key may change the result (an
     * update never removes a key, so it can't violate the dependency, but it may satisfy it - relevant for the
     * cached result of a composition branch).
     */
    public function getObjectUpdateDependency(string $key): ObjectUpdateDependency
    {
        return $key === $this->property->getName() || in_array($key, $this->dependencies, true)
            ? ObjectUpdateDependency::AddedKey
            : ObjectUpdateDependency::None;
    }

    /**
     * A required key may be an undeclared property.
     */
    public function getUndeclaredKeyDependency(): ObjectUpdateDependency
    {
        return ObjectUpdateDependency::AddedKey;
    }
}
