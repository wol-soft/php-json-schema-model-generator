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
        PropertyInterface $nestedProperty,
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
                'viewHelper' => new RenderHelper($generatorConfiguration),
                'generatorConfiguration' => $generatorConfiguration,
            ],
            ContainsException::class,
        );
    }

    /**
     * Enable the per-index match map: the rendered IIFE captures `$_branchContainsMatches`
     * by reference from its surrounding scope and writes `true` at each matched index. The
     * composition template in a tracked branch sets up the accumulator and unions it into
     * the branch's evaluated index set on success.
     */
    public function setTrackBranchMatches(bool $trackBranchMatches): void
    {
        $this->templateValues['trackBranchMatches'] = $trackBranchMatches;
    }

    public function getValidatorSetUp(): string
    {
        return $this->templateValues['countMatches'] ? '
            $containsMatches = 0;
        ' : '';
    }
}
