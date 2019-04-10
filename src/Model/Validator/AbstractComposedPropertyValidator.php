<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;

/**
 * Class AbstractComposedPropertyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
abstract class AbstractComposedPropertyValidator extends PropertyTemplateValidator
{
    /** @var string */
    protected $composedProcessor;
    /** @var CompositionPropertyDecorator[] */
    protected $composedProperties;

    /**
     * @return string
     */
    public function getComposedProcessor(): string
    {
        return $this->composedProcessor;
    }

    /**
     * @return CompositionPropertyDecorator[]
     */
    public function getComposedProperties(): array
    {
        return $this->composedProperties;
    }
}
