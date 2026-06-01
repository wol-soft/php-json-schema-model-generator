<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Validator\Factory\Composition\NotValidatorFactory;

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

    private bool $evaluationTrackingEnabled = false;

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
    public function enableEvaluationTracking(): void
    {
        $this->evaluationTrackingEnabled = true;
    }

    public function hasEvaluationTrackingEnabled(): bool
    {
        return $this->evaluationTrackingEnabled;
    }

    /**
     * Returns true when this validator implements `not` composition semantics.
     *
     * When true, composition templates unconditionally roll back _compositionEvaluations
     * after the not-branch runs so that any annotations it wrote cannot leak to the parent.
     */
    public function isNotComposition(): bool
    {
        return $this->compositionProcessor === NotValidatorFactory::class;
    }
}
