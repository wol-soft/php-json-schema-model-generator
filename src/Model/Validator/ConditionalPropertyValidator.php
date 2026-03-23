<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\PropertyProcessor\ComposedValue\IfProcessor;

/**
 * Class ConditionalPropertyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class ConditionalPropertyValidator extends AbstractComposedPropertyValidator
{
    /** @var CompositionPropertyDecorator[] */
    private array $conditionBranches;

    public function __construct(
        GeneratorConfiguration $generatorConfiguration,
        PropertyInterface $property,
        array $composedProperties,
        array $conditionBranches,
        array $validatorVariables,
    ) {
        $this->isResolved = true;

        parent::__construct(
            $generatorConfiguration,
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ConditionalComposedItem.phptpl',
            $validatorVariables,
            ConditionalException::class,
            ['&$ifException', '&$thenException', '&$elseException'],
        );

        $this->compositionProcessor = IfProcessor::class;
        $this->composedProperties = $composedProperties;
        $this->conditionBranches = $conditionBranches;
    }

    /**
     * Returns the then/else branches, excluding the if condition branch.
     *
     * @return CompositionPropertyDecorator[]
     */
    public function getConditionBranches(): array
    {
        return $this->conditionBranches;
    }

    public function getValidatorSetUp(): string
    {
        return '$ifException = $thenException = $elseException = null;';
    }
}
