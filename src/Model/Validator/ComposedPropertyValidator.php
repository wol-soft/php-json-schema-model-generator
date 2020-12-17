<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\ComposedValue\InvalidComposedValueException;
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
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ComposedItem.phptpl',
            $validatorVariables,
            $this->getExceptionByProcessor($composedProcessor),
            ['&$succeededCompositionElements', '&$compositionErrorCollection']
        );

        $this->composedProcessor = $composedProcessor;
        $this->composedProperties = $composedProperties;
    }

    /**
     * Initialize all variables which are required to execute a composed property validator
     *
     * @return string
     */
    public function getValidatorSetUp(): string
    {
        return '
            $succeededCompositionElements = 0;
            $compositionErrorCollection = [];
        ';
    }

    /**
     * Parse the composition type (allOf, anyOf, ...) from the given processor and get the corresponding exception class
     *
     * @param string $composedProcessor
     *
     * @return string
     */
    private function getExceptionByProcessor(string $composedProcessor): string
    {
        return str_replace(
                DIRECTORY_SEPARATOR,
                '\\',
                dirname(str_replace('\\', DIRECTORY_SEPARATOR, InvalidComposedValueException::class))
            ) . '\\' . str_replace(
                'Processor',
                '',
                substr($composedProcessor, strrpos($composedProcessor, '\\') + 1)
            ) . 'Exception';
    }
}
