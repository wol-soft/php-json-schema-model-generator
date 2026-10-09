<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\Factory\Composition\AllOfValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Composition\AnyOfValidatorFactory;
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
     * Whether the active branch hands the filtered value of the given branch property over to the schema which
     * contains the composition.
     *
     * - anyOf: never, several branches can match (a filtered property is rejected).
     * - allOf: only values forwarded from a nested composition. A property which is filtered directly in an
     *   allOf branch keeps its executed filter on the schema, all branches of an allOf are always active.
     * - oneOf, if/then/else: every filtered property of a branch. The condition of an if/then/else only
     *   selects the branch and never hands over values.
     */
    public function publishesFilteredValueOf(CompositionPropertyDecorator $branch, PropertyInterface $property): bool
    {
        if (
            is_a($this->compositionProcessor, AnyOfValidatorFactory::class, true)
            || ($this instanceof ConditionalPropertyValidator && $branch === $this->getIfBranch())
        ) {
            return false;
        }

        $isAllOf = is_a($this->compositionProcessor, AllOfValidatorFactory::class, true);

        foreach ($property->getValidators() as $propertyValidator) {
            $filterValidator = $propertyValidator->getValidator();

            if ($filterValidator instanceof FilterValidator && (!$isAllOf || !$filterValidator->isExecuted())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Describes the properties for which the active branch of this composition hands the filtered value
     * over to the schema which contains the composition: component index => property name =>
     * [attribute, name of the method validating the property].
     *
     * Only the compositions of a schema (base validators) hand values over. A composition on a named property
     * compiles its branches to classes of their own, nothing is transferred to the containing schema.
     *
     * @return array<int, array<string, array{string, string}>>
     */
    public function getBranchFilteredKeyMap(): array
    {
        if ($this->scope !== null && !($this->templateValues['isBaseValidator'] ?? false)) {
            return [];
        }

        $filteredKeyMap = [];

        foreach ($this->composedProperties as $branchIndex => $compositionProperty) {
            $nestedSchema = $compositionProperty->getNestedSchema();

            if ($nestedSchema === null) {
                continue;
            }

            foreach ($nestedSchema->getProperties() as $branchProperty) {
                if (
                    $branchProperty->isInternal()
                    || ($this->scope !== null && $this->scope->getProperty($branchProperty->getName()) === null)
                    || !$this->publishesFilteredValueOf($compositionProperty, $branchProperty)
                ) {
                    continue;
                }

                $filteredKeyMap[$branchIndex][$branchProperty->getName()] = [
                    $branchProperty->getAttribute(),
                    '_validate' . ucfirst($branchProperty->getAttribute()),
                ];
            }
        }

        return $filteredKeyMap;
    }

    /**
     * The published properties of all components: property name => [attribute, validation method].
     *
     * @return array<string, array{string, string}>
     */
    public function getFilteredKeys(): array
    {
        $filteredKeys = [];

        foreach ($this->getBranchFilteredKeyMap() as $componentFilteredKeys) {
            $filteredKeys += $componentFilteredKeys;
        }

        return $filteredKeys;
    }

    /**
     * @param int[]|null $componentIndices Restrict the lookup to the given components, null for all components
     *
     * @return string PHP code of an array containing the published property names as keys
     */
    protected function getFilteredKeyLookup(?array $componentIndices = null): string
    {
        $lookup = [];

        foreach ($this->getBranchFilteredKeyMap() as $componentIndex => $filteredKeys) {
            if ($componentIndices === null || in_array($componentIndex, $componentIndices, true)) {
                $lookup += array_fill_keys(array_keys($filteredKeys), true);
            }
        }

        return RenderHelper::varExportArray($lookup);
    }

    protected function publishesFilteredValues(): bool
    {
        return $this->getBranchFilteredKeyMap() !== [];
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
                    'filteredKeys' => RenderHelper::varExportArray(array_keys($this->getFilteredKeys())),
                ],
            ),
        );

        return true;
    }
}
