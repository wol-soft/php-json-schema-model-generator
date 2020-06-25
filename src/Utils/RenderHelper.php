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
     * Resolve all associated decorators of a property
     *
     * @param PropertyInterface $property
     *
     * @return string
     */
    public function resolvePropertyDecorator(PropertyInterface $property): string
    {
        if (!$property->hasDecorators()) {
            return '';
        }

        return $property->isRequired()
            ? '$value = ' . $property->resolveDecorator('$value') . ';'
            : 'if ($value !== null) { $value = ' . $property->resolveDecorator('$value') . '; }';
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
            return "\$this->errorRegistry->addError($exceptionConstructor);";
        }

        return "throw $exceptionConstructor;";
    }

    public function implicitNull(PropertyInterface $property): bool
    {
        return $this->generatorConfiguration->isImplicitNullAllowed() && !$property->isRequired();
    }
}
