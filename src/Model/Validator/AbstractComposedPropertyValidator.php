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

    /**
     * Whether a branch of the composition depends on keys of the object it doesn't declare, see
     * CompositionPropertyDecorator::dependsOnAllObjectProperties(). Such a composition must be validated again by
     * every update of the object.
     */
    public function dependsOnAllObjectProperties(): bool
    {
        foreach ($this->composedProperties as $composedProperty) {
            if ($composedProperty->dependsOnAllObjectProperties()) {
                return true;
            }
        }

        return false;
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
            $this->templateValues['allBranchDefaultAttributeMap'] = RenderHelper::varExportArray([]);

            return false;
        }

        $isBaseValidator = $this->scope !== null && in_array($this, $this->scope->getBaseValidators(), true);
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

                // Only the branch properties of a base validator (a composition of the schema itself) are transferred
                // onto the containing schema (via SchemaProcessor::transferComposedPropertiesToSchema()) and are real
                // attributes of $this. A composition on a named property (e.g. `target: {"oneOf": [...]}`) never
                // transfers its branches' properties - an object-typed branch compiles to its own generated class
                // which applies its defaults in its own constructor, so no external reset is needed.
                // Deciding by a name lookup of the branch property on the scope was rejected: it also matches an
                // unrelated sibling property of the outer schema with the same name, whose value would be
                // overwritten with the default of the nested branch.
                if (!$isBaseValidator) {
                    continue;
                }

                $scopeProperty = $this->scope->getProperty($branchProperty->getName());

                // The branch properties of a `not` composition are never transferred onto the scope (only
                // compositions of a ComposedPropertiesValidatorFactoryInterface are), so they are no attributes of
                // $this: resetting them would create a dynamic property.
                if ($scopeProperty === null) {
                    continue;
                }

                // Do not include properties that already have a root-level default on the
                // parent schema — root defaults are applied unconditionally via PHP field
                // initializers and must not be overwritten or reset by the branch mechanism.
                if ($scopeProperty->getDefaultValue() !== null) {
                    continue;
                }

                // The generated code writes to the attribute declared on $this, which belongs to the scope property
                // (not to the branch property of the nested schema). A name starting with a digit is prefixed there,
                // writing to the plain attribute name would create a dynamic property.
                $allBranchDefaultAttributeMap[$branchProperty->getName()] = $scopeProperty->getAttribute(true);
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
                ],
            ),
        );

        return true;
    }
}
