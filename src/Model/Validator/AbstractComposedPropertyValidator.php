<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Validator\Factory\Composition\NotValidatorFactory;
use PHPModelGenerator\SchemaProcessor\PostProcessor\RenderedMethod;

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
    protected string $modifiedValuesMethod = '';

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

    protected function initModifiedValuesMethod(): void
    {
        $this->modifiedValuesMethod = '_getModifiedValues_' . substr(md5(spl_object_hash($this)), 0, 5);
    }

    /**
     * Returns true when at least one composition branch has a nested schema with declared
     * properties, meaning the modified-values helper method may produce non-empty results.
     */
    protected function hasNestedSchemaWithProperties(): bool
    {
        foreach ($this->composedProperties as $compositionProperty) {
            $nestedSchema = $compositionProperty->getNestedSchema();
            if ($nestedSchema !== null && !empty($nestedSchema->getProperties())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sets up the allBranchDefaultAttributeMap template variable and registers the
     * _getModifiedValues_* helper method on the schema scope. Properties that already carry
     * a root-level (unconditional) default in the parent schema are excluded from the map;
     * those defaults are applied via PHP field initializers and must not be reset by the
     * per-branch mechanism.
     *
     * Returns true when the helper method was registered (at least one branch has a nested
     * schema with properties), false otherwise.
     */
    protected function setupBranchDefaultHelpers(): bool
    {
        $hasNestedSchemaWithProperties = $this->hasNestedSchemaWithProperties();

        $this->templateValues['hasModifiedValuesMethod'] = $hasNestedSchemaWithProperties;

        if (!$hasNestedSchemaWithProperties) {
            $this->templateValues['allBranchDefaultAttributeMap'] = var_export([], true);

            return false;
        }

        $allBranchDefaultAttributeMap = [];
        $componentDefaultValueMap = [];
        $propertyAccessors = [];

        foreach ($this->composedProperties as $branchIndex => $compositionProperty) {
            if (!$compositionProperty->getNestedSchema()) {
                continue;
            }

            foreach ($compositionProperty->getNestedSchema()->getProperties() as $branchProperty) {
                $propertyAccessors[$branchProperty->getName()] = 'get' . ucfirst($branchProperty->getAttribute());

                if ($branchProperty->getDefaultValue() === null) {
                    continue;
                }

                $componentDefaultValueMap[$branchIndex][] = $branchProperty->getName();

                // Do not include properties that already have a root-level default on the
                // parent schema — root defaults are applied unconditionally via PHP field
                // initializers and must not be overwritten or reset by the branch mechanism.
                if ($this->scope?->getProperty($branchProperty->getName())?->getDefaultValue() !== null) {
                    continue;
                }

                $allBranchDefaultAttributeMap[$branchProperty->getName()] = $branchProperty->getAttribute();
            }
        }

        $this->templateValues['allBranchDefaultAttributeMap'] = var_export($allBranchDefaultAttributeMap, true);
        $this->templateValues['modifiedValuesMethod'] = $this->modifiedValuesMethod;

        $this->scope->addMethod(
            $this->modifiedValuesMethod,
            new RenderedMethod(
                $this->scope,
                $this->generatorConfiguration,
                'GetModifiedValues.phptpl',
                [
                    'modifiedValuesMethod' => $this->modifiedValuesMethod,
                    'componentDefaultValueMap' => var_export($componentDefaultValueMap, true),
                    'propertyAccessors' => var_export($propertyAccessors, true),
                ],
            ),
        );

        return true;
    }
}
