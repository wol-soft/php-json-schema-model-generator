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
        $this->initModifiedValuesMethod();
        $this->isResolved = true;

        parent::__construct(
            $generatorConfiguration,
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ConditionalComposedItem.phptpl',
            array_merge($validatorVariables, ['modifiedValuesMethod' => $this->modifiedValuesMethod]),
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
     * Registers the branch-default helper method and sets the per-branch default map
     * and the then/else component indices as template variables so that
     * ConditionalComposedItem.phptpl can apply branch defaults at runtime.
     */
    public function getCheck(): string
    {
        // Late-bind `compositionValidator` so template guards see the flags set on the current
        // clone rather than on the pre-`withJsonPointer()` original.
        $this->templateValues['compositionValidator'] = $this;

        $this->setupBranchDefaultHelpers();

        $thenProperty = $this->templateValues['thenProperty'] ?? null;
        $elseProperty = $this->templateValues['elseProperty'] ?? null;

        // Determine which index in $composedProperties corresponds to the then and else branches.
        // The if-branch is always at index 0; then is at 1 when present, else follows.
        $this->templateValues['thenComponentIndex'] = $thenProperty !== null
            ? (int) array_search($thenProperty, $this->composedProperties, true)
            : -1;

        $this->templateValues['elseComponentIndex'] = $elseProperty !== null
            ? (int) array_search($elseProperty, $this->composedProperties, true)
            : -1;

        return parent::getCheck();
    }

    /**
     * Initialize variables required by the conditional validator.
     */
    public function getValidatorSetUp(): string
    {
        return '$ifException = $thenException = $elseException = null;';
    }
}
