<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use LogicException;
use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\Factory\Composition\IfValidatorFactory;

/**
 * Class ConditionalPropertyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class ConditionalPropertyValidator extends AbstractComposedPropertyValidator
{
    /** @var CompositionPropertyDecorator[] */
    private array $conditionBranches;

    private ?CompositionPropertyDecorator $thenBranch;
    private ?CompositionPropertyDecorator $elseBranch;

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

        $this->compositionProcessor = IfValidatorFactory::class;
        $this->composedProperties = $composedProperties;
        $this->conditionBranches = $conditionBranches;
        $this->thenBranch = $validatorVariables['thenProperty'] ?? null;
        $this->elseBranch = $validatorVariables['elseProperty'] ?? null;
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

    /**
     * Returns the if-condition branch.
     *
     * The if branch is always present — ConditionalPropertyValidator cannot be constructed
     * without a valid if schema (IfValidatorFactory throws SchemaException otherwise).
     */
    public function getIfBranch(): CompositionPropertyDecorator
    {
        foreach ($this->composedProperties as $composedProperty) {
            if (!in_array($composedProperty, $this->conditionBranches, true)) {
                return $composedProperty;
            }
        }

        // @codeCoverageIgnoreStart
        throw new LogicException('ConditionalPropertyValidator has no if branch — this is a bug.');
        // @codeCoverageIgnoreEnd
    }

    public function getThenBranch(): ?CompositionPropertyDecorator
    {
        return $this->thenBranch;
    }

    public function getElseBranch(): ?CompositionPropertyDecorator
    {
        return $this->elseBranch;
    }

    /**
     * Initialize variables required by the conditional validator.
     */
    public function getValidatorSetUp(): string
    {
        return '$ifException = $thenException = $elseException = null;';
    }
}
