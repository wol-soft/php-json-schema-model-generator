<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
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
                // Internal machinery properties (e.g. the composition state tracker
                // propertyValidationState of a re-routed composition branch class) are not schema
                // properties and must never be transferred as a branch default of the outer
                // composition - doing so both clobbers the outer schema's own internal attributes
                // and, for a mixed object/scalar composition, feeds a non-array scalar input into
                // the branch-default array_key_exists lookup.
                if ($branchProperty->isInternal()) {
                    continue;
                }

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
