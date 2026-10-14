<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\RenderedMethod;
use PHPModelGenerator\Utils\RenderHelper;

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
     * The class attributes (as used in the generated code) which the branch defaults of this composition write to,
     * see setupBranchDefaultHelpers().
     *
     * @return string[]
     */
    public function getBranchDefaultAttributes(): array
    {
        return array_map(
            fn(string $propertyName): string => $this->scope->getProperty($propertyName)->getAttribute(true),
            array_keys($this->collectBranchDefaults()[0]),
        );
    }

    /**
     * @return array{array<string, string>, array<int, string[]>, array<string, string>} The attributes of the
     *         branch defaults which are reset by the composition (property name => attribute), the names of the
     *         properties with a default per component and the getters of all branch properties
     */
    private function collectBranchDefaults(): array
    {
        $allBranchDefaultAttributeMap = [];
        $componentDefaultValueMap = [];
        $propertyAccessors = [];

        foreach ($this->composedProperties as $branchIndex => $compositionProperty) {
            if (!$compositionProperty->getNestedSchema()) {
                continue;
            }

            foreach ($compositionProperty->getNestedSchema()->getProperties() as $branchProperty) {
                // Internal machinery properties are never real branch data and must not be
                // transferred as a branch default of the outer composition. This covers both the
                // composition state tracker propertyValidationState of a re-routed composition
                // branch class and bookkeeping properties such as _skipNotProvidedPropertiesMap
                // added by SerializationPostProcessor - neither gets a getter generated, and
                // misreading their default values as a branch default both clobbers the outer
                // schema's own internal attributes and, for a mixed object/scalar composition,
                // feeds a non-array scalar input into the branch-default array_key_exists lookup.
                if ($branchProperty->isInternal()) {
                    continue;
                }

                $propertyAccessors[$branchProperty->getName()] = 'get' . ucfirst($branchProperty->getAttribute());

                if ($branchProperty->getDefaultValue() === null) {
                    continue;
                }

                $componentDefaultValueMap[$branchIndex][] = $branchProperty->getName();

                $scopeProperty = $this->scope?->getProperty($branchProperty->getName());

                // Only a branch property which was actually transferred onto the containing
                // schema (root-schema/base compositions, via
                // SchemaProcessor::transferComposedPropertiesToSchema()) is a real attribute of
                // $this. A composition on a named property (e.g. `target: {"oneOf": [...]}`)
                // never transfers its branches' properties — an object-typed branch instead
                // compiles to its own separate generated class, instantiated as a nested object.
                // Resetting such a branch-local property (e.g. the internal
                // `additionalProperties`/`patternProperties` bookkeeping properties) on $this
                // would create a dynamic property on the wrong object instead of doing nothing,
                // which is what's correct here: that nested object already initializes its own
                // default state through its own constructor, so no external reset is needed.
                if ($scopeProperty === null) {
                    continue;
                }

                // Do not include properties that already have a root-level default on the
                // parent schema — root defaults are applied unconditionally via PHP field
                // initializers and must not be overwritten or reset by the branch mechanism.
                if ($scopeProperty->getDefaultValue() !== null) {
                    continue;
                }

                $allBranchDefaultAttributeMap[$branchProperty->getName()] = $branchProperty->getAttribute();
            }
        }

        return [$allBranchDefaultAttributeMap, $componentDefaultValueMap, $propertyAccessors];
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
            $this->templateValues['allBranchDefaultAttributeMap'] = RenderHelper::varExportArray([]);

            return false;
        }

        [$allBranchDefaultAttributeMap, $componentDefaultValueMap, $propertyAccessors] = $this->collectBranchDefaults();

        $this->templateValues['allBranchDefaultAttributeMap'] = RenderHelper::varExportArray(
            $allBranchDefaultAttributeMap,
        );
        $this->templateValues['modifiedValuesMethod'] = $this->modifiedValuesMethod;

        $this->scope->addMethod(
            $this->modifiedValuesMethod,
            new RenderedMethod(
                $this->scope,
                $this->generatorConfiguration,
                'GetModifiedValues.phptpl',
                [
                    'modifiedValuesMethod' => $this->modifiedValuesMethod,
                    'componentDefaultValueMap' => RenderHelper::varExportArray($componentDefaultValueMap),
                    'propertyAccessors' => RenderHelper::varExportArray($propertyAccessors),
                ],
            ),
        );

        return true;
    }
}
