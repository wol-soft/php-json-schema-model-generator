<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Arrays\ContainsException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Validates the `contains` keyword: at least one item in the array must satisfy the
 * embedded subschema. Optionally counts matches when minContains / maxContains are used,
 * and optionally exports the per-index match map into a local accumulator so a surrounding
 * composition branch can credit the matched indices to its evaluated set.
 */
class ArrayContainsValidator extends PropertyTemplateValidator
{
    public function __construct(
        PropertyInterface $property,
        private readonly PropertyInterface $nestedProperty,
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        bool $countMatches,
        bool $allowNoMatch,
    ) {
        parent::__construct(
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ArrayContains.phptpl',
            [
                'property' => $nestedProperty,
                'schema' => $schema,
                'countMatches' => $countMatches,
                'allowNoMatch' => $allowNoMatch,
                'trackBranchMatches' => false,
                'trackEvaluatedItems' => false,
                'trackEvaluatedItemsProperty' => '',
                'capturedVariables' => '',
                'viewHelper' => new RenderHelper($generatorConfiguration),
                'generatorConfiguration' => $generatorConfiguration,
            ],
            ContainsException::class,
        );
    }

    /**
     * The property which validates each item of the array. Exposed so post processors can recurse into it - mirrors
     * ArrayItemValidator::getNestedProperty().
     */
    public function getNestedProperty(): PropertyInterface
    {
        return $this->nestedProperty;
    }

    /**
     * Enable the per-index match map: the rendered IIFE captures `$branchContainsMatches`
     * by reference from its surrounding scope and writes `true` at each matched index. The
     * composition template in a tracked branch sets up the accumulator and unions it into
     * the branch's evaluated index set on success.
     */
    public function setTrackBranchMatches(bool $trackBranchMatches): void
    {
        $this->templateValues['trackBranchMatches'] = $trackBranchMatches;
    }

    /**
     * Enable direct-sibling index crediting: the rendered IIFE writes each matched index into
     * `$this->_evaluatedItemIndices[<property>]` so a sibling unevaluatedItems validator on the
     * same property sees the matched indices as evaluated. Used when the `contains` keyword sits
     * directly on an array property (not inside a composition branch). The property name is the
     * validator's own bound property — the same name the sibling unevaluatedItems check keys on.
     */
    public function setTrackEvaluatedItems(): void
    {
        $this->templateValues['trackEvaluatedItems'] = true;
        $this->templateValues['trackEvaluatedItemsProperty'] = $this->property->getName();
    }

    /**
     * The variables the closure shares with its surrounding scope depend on the tracking which is enabled after the
     * validator was created.
     */
    public function getCheck(): string
    {
        $capturedVariables = array_filter([
            $this->templateValues['countMatches'] ? '&$containsMatches' : null,
            $this->templateValues['trackBranchMatches'] ? '&$branchContainsMatches' : null,
        ]);

        $this->templateValues['capturedVariables'] = $capturedVariables
            ? ' use (' . implode(', ', $capturedVariables) . ')'
            : '';

        return parent::getCheck();
    }

    public function getValidatorSetUp(): string
    {
        return $this->templateValues['countMatches'] ? '
            $containsMatches = 0;
        ' : '';
    }
}
