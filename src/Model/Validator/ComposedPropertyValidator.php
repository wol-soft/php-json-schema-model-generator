<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class ComposedPropertyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class ComposedPropertyValidator extends AbstractComposedPropertyValidator
{
    public function __construct(
        GeneratorConfiguration $generatorConfiguration,
        PropertyInterface $property,
        array $composedProperties,
        string $compositionProcessor,
        string $exceptionClass,
        array $validatorVariables,
    ) {
        $this->initModifiedValuesMethod();
        $this->isResolved = true;

        $schema = $validatorVariables['schema'];

        parent::__construct(
            $generatorConfiguration,
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ComposedItem.phptpl',
            array_merge($validatorVariables, [
                'modifiedValuesMethod' => $this->modifiedValuesMethod,
                // Rendered as its own template and embedded via viewHelper.indent() instead of being inlined
                // directly: the shared per-composition-element validation body sits at a different real nesting
                // depth depending on whether isMutableBaseValidator holds (it wraps the body in an extra
                // "} else {" for its cache-check) - one literal indentation can't be correct for both, so the
                // body is written once, at its own canonical depth, and the two call sites in ComposedItem.phptpl
                // each indent the rendered result to their own real depth. Rendered once per composition
                // property, so it must be a closure invoked from inside the {% foreach %} loop rather than a
                // value precomputed here.
                'renderComposedItemBody' => function (
                    $compositionProperty,
                    $isMutableBaseValidator,
                    $hasModifiedValuesMethod,
                    $modifiedValuesMethod,
                    $postPropose,
                ) use (
                    $schema,
                    $generatorConfiguration,
                ): string {
                    return $this->getRenderer()->renderTemplate(
                        DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ComposedItemBody.phptpl',
                        [
                            'compositionProperty' => $compositionProperty,
                            'schema' => $schema,
                            'generatorConfiguration' => $generatorConfiguration,
                            'viewHelper' => new RenderHelper($generatorConfiguration),
                            'isMutableBaseValidator' => $isMutableBaseValidator,
                            'hasModifiedValuesMethod' => $hasModifiedValuesMethod,
                            'modifiedValuesMethod' => $modifiedValuesMethod,
                            'postPropose' => $postPropose,
                        ],
                    );
                },
            ]),
            $exceptionClass,
            ['&$succeededCompositionElements', '&$compositionErrorCollection'],
        );

        $this->compositionProcessor = $compositionProcessor;
        $this->composedProperties = $composedProperties;
    }

    /**
     * Registers the helper method that propagates filter-transformed values from nested
     * composition objects back to the merged model, but only when at least one composition
     * branch has a nested schema with declared properties (the only case where the method
     * would ever produce non-empty results).
     */
    public function getCheck(): string
    {
        $this->setupBranchDefaultHelpers();

        return parent::getCheck();
    }

    /**
     * Initialize all variables which are required to execute a composed property validator.
     */
    public function getValidatorSetUp(): string
    {
        return <<<'CODE'
            $succeededCompositionElements = 0;
            $compositionErrorCollection = [];
            CODE;
    }

    /**
     * Create a subset of this validator containing only the composition branches at the
     * given indices. Used by FilterProcessor to split an allOf validator whose branches
     * span both input-space and output-space around a transforming filter.
     *
     * @param int[]  $branchIndices Branch indices (0-based) to retain.
     * @param string $methodSuffix  Appended to the extracted method name to keep
     *                              the subset validators distinct in the generated class.
     *
     * @return self
     */
    public function createSubsetValidator(array $branchIndices, string $methodSuffix): self
    {
        $filteredProperties = array_values(
            array_intersect_key($this->composedProperties, array_flip($branchIndices)),
        );
        $availableAmount = count($filteredProperties);

        $subsetValidator = clone $this;

        // Give the subset validator a unique extracted method name so it generates
        // its own method in the target class instead of colliding with the original.
        $subsetValidator->extractedMethodName = $this->getExtractedMethodName() . $methodSuffix;

        // Regenerate the modifiedValuesMethod name so the subset validator's helper
        // method is distinct from the original's.
        $subsetValidator->modifiedValuesMethod =
            '_getModifiedValues_' . substr(md5(spl_object_hash($subsetValidator)), 0, 5);

        $subsetValidator->composedProperties = $filteredProperties;
        $subsetValidator->templateValues = array_merge($this->templateValues, [
            'compositionProperties' => $filteredProperties,
            'availableAmount'       => $availableAmount,
            'composedValueValidation' => "\$succeededCompositionElements === $availableAmount",
            'mergedProperty'        => null,
            'modifiedValuesMethod'  => $subsetValidator->modifiedValuesMethod,
        ]);

        return $subsetValidator;
    }

    /**
     * Creates a copy of the validator and strips all nested composition validations from the composed properties.
     * See usage in BaseProcessor for more details why the nested validators can be filtered out.
     *
     * @return $this
     */
    public function withoutNestedCompositionValidation(): self
    {
        $validator = clone $this;

        /** @var CompositionPropertyDecorator $composedProperty */
        foreach ($validator->composedProperties as $composedProperty) {
            $composedProperty->onResolve(static function () use ($composedProperty): void {
                $composedProperty->filterValidators(
                    static fn(Validator $validator): bool =>
                        !is_a($validator->getValidator(), AbstractComposedPropertyValidator::class)
                );
            });
        }

        return $validator;
    }
}
