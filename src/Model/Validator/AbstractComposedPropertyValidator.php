<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;

/**
 * Class AbstractComposedPropertyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
abstract class AbstractComposedPropertyValidator extends ExtractedMethodValidator
{
    /** @var string */
    protected $compositionProcessor;
    /** @var CompositionPropertyDecorator[] */
    protected $composedProperties;

    private bool $trackEvaluation = false;

    public function getCompositionProcessor(): string
    {
        return $this->compositionProcessor;
    }

    /**
     * @return CompositionPropertyDecorator[]
     */
    public function getComposedProperties(): array
    {
        return $this->composedProperties;
    }

    /**
     * When true, this validator's rendered output will emit the _compositionEvaluations
     * cache field and per-branch slot writes needed for unevaluatedProperties tracking.
     */
    public function setTrackEvaluation(bool $trackEvaluation): void
    {
        $this->trackEvaluation = $trackEvaluation;
    }

    public function isTrackEvaluation(): bool
    {
        return $this->trackEvaluation;
    }
}
