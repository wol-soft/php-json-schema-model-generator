<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\ComposedValue\InvalidComposedValueException;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator;

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
     * @param string                         $compositionProcessor
     * @param array                          $validatorVariables
     */
    public function __construct(
        PropertyInterface $property,
        array $composedProperties,
        string $compositionProcessor,
        array $validatorVariables
    ) {
        parent::__construct(
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ComposedItem.phptpl',
            $validatorVariables,
            $this->getExceptionByProcessor($compositionProcessor),
            ['&$succeededCompositionElements', '&$compositionErrorCollection']
        );

        $this->compositionProcessor = $compositionProcessor;
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
     * Creates a copy of the validator and strips all nested composition validations from the composed properties.
     * See usage in BaseProcessor for more details why the nested validators can be filtered out.
     *
     * @return $this
     */
    public function withoutNestedCompositionValidation(): self
    {
        $validator = clone $this;

        foreach ($validator->composedProperties as $composedProperty) {
            $composedProperty->filterValidators(function (Validator $validator): bool {
                return !is_a($validator->getValidator(), AbstractComposedPropertyValidator::class);
            });
        }

        return $validator;
    }

    /**
     * Parse the composition type (allOf, anyOf, ...) from the given processor and get the corresponding exception class
     *
     * @param string $compositionProcessor
     *
     * @return string
     */
    private function getExceptionByProcessor(string $compositionProcessor): string
    {
        return str_replace(
                DIRECTORY_SEPARATOR,
                '\\',
                dirname(str_replace('\\', DIRECTORY_SEPARATOR, InvalidComposedValueException::class))
            ) . '\\' . str_replace(
                'Processor',
                '',
                substr($compositionProcessor, strrpos($compositionProcessor, '\\') + 1)
            ) . 'Exception';
    }
}
