<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class ComposedPropertyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class ComposedPropertyValidator extends PropertyTemplateValidator
{
    /** @var string */
    protected $composedProcessor;
    /** @var PropertyInterface[] */
    protected $composedProperties;

    /**
     * ComposedPropertyValidator constructor.
     *
     * @param PropertyInterface   $property
     * @param PropertyInterface[] $composedProperties
     * @param string              $composedProcessor
     * @param array               $validatorVariables
     */
    public function __construct(
        PropertyInterface $property,
        array $composedProperties,
        string $composedProcessor,
        array $validatorVariables
    ) {
        parent::__construct(
            InvalidArgumentException::class,
            "Invalid value for {$property->getName()} declined by composition constraint",
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ComposedItem.phptpl',
            $validatorVariables
        );

        $this->composedProcessor = $composedProcessor;
        $this->composedProperties = $composedProperties;
    }

    /**
     * @return string
     */
    public function getComposedProcessor(): string
    {
        return $this->composedProcessor;
    }

    /**
     * @return PropertyInterface[]
     */
    public function getComposedProperties(): array
    {
        return $this->composedProperties;
    }
}
