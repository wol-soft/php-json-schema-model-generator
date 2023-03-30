<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\PropertyProcessor\ComposedValue\IfProcessor;

/**
 * Class ConditionalPropertyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class ConditionalPropertyValidator extends AbstractComposedPropertyValidator
{
    public function __construct(
        GeneratorConfiguration $generatorConfiguration,
        PropertyInterface $property,
        array $composedProperties,
        array $validatorVariables
    ) {
        $this->isResolved = true;

        parent::__construct(
            $generatorConfiguration,
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ConditionalComposedItem.phptpl',
            $validatorVariables,
            ConditionalException::class,
            ['&$ifException', '&$thenException', '&$elseException']
        );

        $this->compositionProcessor = IfProcessor::class;
        $this->composedProperties = $composedProperties;
    }

    public function getValidatorSetUp(): string
    {
        return '$ifException = $thenException = $elseException = null;';
    }
}
