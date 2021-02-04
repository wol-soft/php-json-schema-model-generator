<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;

/**
 * Class RenderHelper
 *
 * @package PHPModelGenerator\Utils
 */
class RenderHelper
{
    /** @var GeneratorConfiguration */
    protected $generatorConfiguration;

    /**
     * RenderHelper constructor.
     *
     * @param GeneratorConfiguration $generatorConfiguration
     */
    public function __construct(GeneratorConfiguration $generatorConfiguration)
    {
        $this->generatorConfiguration = $generatorConfiguration;
    }

    /**
     * @param string $value
     *
     * @return string
     */
    public function ucfirst(string $value): string
    {
        return ucfirst($value);
    }

    /**
     * @param $value
     *
     * @return bool
     */
    public function isNull($value): bool
    {
        return $value === null;
    }

    /**
     * @param string $fqcn
     *
     * @return string
     */
    public function getSimpleClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }

    /**
     * @param array $fqcns
     *
     * @return string
     */
    public function joinClassNames(array $fqcns): string
    {
        return join(', ', array_map([$this, 'getSimpleClassName'], $fqcns));
    }

    /**
     * Resolve all associated decorators of a property
     *
     * @param PropertyInterface $property
     * @param bool $nestedProperty
     *
     * @return string
     */
    public function resolvePropertyDecorator(PropertyInterface $property, bool $nestedProperty = false): string
    {
        if (!$property->getDecorators()) {
            return '';
        }

        return $property->isRequired()
            ? '$value = ' . $property->resolveDecorator('$value', $nestedProperty) . ';'
            : 'if ($value !== null) { $value = ' . $property->resolveDecorator('$value', $nestedProperty) . '; }';
    }

    /**
     * Generate code to handle a validation error
     *
     * @param PropertyValidatorInterface $validator
     *
     * @return string
     */
    public function validationError(PropertyValidatorInterface $validator): string
    {
        $exceptionConstructor = sprintf(
            'new \%s($value ?? null, ...%s)',
            $validator->getExceptionClass(),
            preg_replace('/\'&(\$\w+)\'/i', '$1', var_export($validator->getExceptionParams(), true))
        );

        if ($this->generatorConfiguration->collectErrors()) {
            return "\$this->_errorRegistry->addError($exceptionConstructor);";
        }

        return "throw $exceptionConstructor;";
    }

    /**
     * check if the property may contain/accept null
     * - if the property is required the property may never contain null (if it's a null property null is already
     *   contained in the proprety type hints)
     * - if the output type is requested null may be contained (if the property was not set)
     *   if implicitNull is enabled null may be set for the property
     * - except the property contains a default value and implicit null is disabled. in this case null is not
     *   possible
     *
     * @param PropertyInterface $property
     * @param bool $outputType
     *
     * @return bool
     */
    public function isPropertyNullable(PropertyInterface $property, bool $outputType = false): bool
    {
        return !$property->isRequired()
            && ($outputType || $this->generatorConfiguration->isImplicitNullAllowed())
            && !($property->getDefaultValue() && !$this->generatorConfiguration->isImplicitNullAllowed());
    }

    public function getTypeHintAnnotation(PropertyInterface $property, bool $outputType = false): string
    {
        $typeHint = $property->getTypeHint($outputType);

        if (!$typeHint) {
            return 'mixed';
        }

        if ($this->isPropertyNullable($property, $outputType)) {
            $typeHint = implode('|', array_unique(array_merge(explode('|', $typeHint), ['null'])));
        }

        return $typeHint;
    }
}
