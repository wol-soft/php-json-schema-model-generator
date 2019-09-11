<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class ComposedPropertyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class ComposedPropertyValidator extends AbstractComposedPropertyValidator
{
    /**
     * ComposedPropertyValidator constructor.
     *
     * @param PropertyInterface              $property
     * @param CompositionPropertyDecorator[] $composedProperties
     * @param string                         $composedProcessor
     * @param array                          $validatorVariables
     */
    public function __construct(
        PropertyInterface $property,
        array $composedProperties,
        string $composedProcessor,
        array $validatorVariables
    ) {
        parent::__construct(
            "Invalid value for {$property->getName()} declined by composition constraint",
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ComposedItem.phptpl',
            $validatorVariables
        );

        $this->composedProcessor = $composedProcessor;
        $this->composedProperties = $composedProperties;
    }
}
