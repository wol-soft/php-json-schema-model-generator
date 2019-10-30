<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPMicroTemplate\Exception\FileSystemException;
use PHPMicroTemplate\Exception\SyntaxErrorException;
use PHPMicroTemplate\Exception\UndefinedSymbolException;
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
     *
     * @throws FileSystemException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    public function __construct(
        PropertyInterface $property,
        array $composedProperties,
        string $composedProcessor,
        array $validatorVariables
    ) {
        parent::__construct(
            $this->getRenderer()->renderTemplate(
                DIRECTORY_SEPARATOR . 'Exception' . DIRECTORY_SEPARATOR . 'ComposedValueException.phptpl',
                [
                    'propertyName' => $property->getName(),
                    'composedErrorMessage' => $validatorVariables['composedErrorMessage'],
                ]
            ),
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ComposedItem.phptpl',
            $validatorVariables
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
            $i = 0;
            $succeededCompositionElements = 0;
            $compositionErrorCollection = [];
        ';
    }
}
