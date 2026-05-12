<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\Filter;

use Exception;
use PHPModelGenerator\Draft\Draft;
use PHPModelGenerator\Draft\DraftFactoryInterface;
use PHPModelGenerator\Exception\InvalidFilterException;
use PHPModelGenerator\Exception\Object\InvalidInstanceOfException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Filter\ValidateOptionsInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\AbstractPropertyValidator;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\EnumValidator;
use PHPModelGenerator\Model\Validator\Factory\Composition\AllOfValidatorFactory;
use PHPModelGenerator\Model\Validator\FilterValidator;
use PHPModelGenerator\Model\Validator\FormatValidator;
use PHPModelGenerator\Model\Validator\InstanceOfValidator;
use PHPModelGenerator\Model\Validator\MultiTypeCheckValidator;
use PHPModelGenerator\Model\Validator\PassThroughTypeCheckValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\TypeCheckValidator;
use PHPModelGenerator\Utils\FilterReflection;
use PHPModelGenerator\Utils\RenderHelper;
use PHPModelGenerator\Utils\TypeCheck;
use ReflectionException;

/**
 * Class FilterProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Filter
 */
class FilterProcessor
{
    /**
     * Normalize a filter specification to a list of filter entries.
     *
     * Accepts a string token, a single filter-spec array (['filter' => 'token', ...]),
     * or a list of either. Always returns a list.
     */
    public static function normalizeFilterList(string|array $filterList): array
    {
        if (is_string($filterList) || (is_array($filterList) && isset($filterList['filter']))) {
            return [$filterList];
        }

        return $filterList;
    }

    /**
     * @throws InvalidFilterException
     * @throws ReflectionException
     * @throws SchemaException
     */
    public function process(
        PropertyInterface $property,
        string|array $filterList,
        GeneratorConfiguration $generatorConfiguration,
        Schema $schema,
        int $startPriority = 10,
    ): void {
        $filterList = self::normalizeFilterList($filterList);

        $transformingFilter = null;
        // apply a different priority to each filter to make sure the order is kept
        $filterPriority = $startPriority + count($property->getValidators());

        foreach ($filterList as $filterToken) {
            $filterOptions = [];
            if (is_array($filterToken)) {
                $filterOptions = array_diff_key($filterToken, ['filter' => null]);
                $filterToken = $filterToken['filter'] ?? '';
            }

            if (!($filter = $generatorConfiguration->getFilter($filterToken))) {
                throw new SchemaException(
                    sprintf(
                        'Unsupported filter %s on property %s in file %s',
                        $filterToken,
                        $property->getName(),
                        $property->getJsonSchema()->getFile(),
                    )
                );
            }

            if ($filter instanceof ValidateOptionsInterface) {
                try {
                    $filter->validateOptions($filterOptions);
                } catch (Exception $exception) {
                    throw new SchemaException(
                        sprintf(
                            'Invalid filter options on filter %s on property %s in file %s: %s',
                            $filterToken,
                            $property->getName(),
                            $property->getJsonSchema()->getFile(),
                            $exception->getMessage(),
                        )
                    );
                }
            }

            $isTransformingFilter = $filter instanceof TransformingFilterInterface;

            if ($isTransformingFilter) {
                if ($property->getType() && in_array('array', $property->getType()->getNames(), true)) {
                    throw new SchemaException(
                        sprintf(
                            'Applying a transforming filter to the array property %s is not supported in file %s',
                            $property->getName(),
                            $property->getJsonSchema()->getFile(),
                        )
                    );
                }
                if ($transformingFilter) {
                    throw new SchemaException(
                        sprintf(
                            'Applying multiple transforming filters for property %s is not supported in file %s',
                            $property->getName(),
                            $property->getJsonSchema()->getFile(),
                        )
                    );
                }
            }

            // $transformingFilter is still null here when the current filter IS the transforming
            // filter — FilterValidator correctly receives null (no previous transforming filter).
            $actualFilterPriority = $filterPriority++;
            $property->addValidator(
                new FilterValidator($generatorConfiguration, $filter, $property, $filterOptions, $transformingFilter),
                $actualFilterPriority,
            );

            if ($isTransformingFilter) {
                $returnTypeNames = FilterReflection::getReturnTypeNames($filter, $property);

                $inputTypeNames = FilterReflection::getAcceptedTypes($filter, $property);
                $builtDraft = $this->resolveBuiltDraft($generatorConfiguration, $property);
                $classifier = new CompositionBranchClassifier($builtDraft, $inputTypeNames, $returnTypeNames);
                $checker = new CompositionCompatibilityChecker($classifier, $property);
                $checker->checkTransformingFilterCompositionConflicts($property->getJsonSchema()->getJson());
                $checker->checkTransformingFilterRootCompositionConflicts($schema->getJsonSchema()->getJson());

                $this->reassignValidatorPriorities(
                    $property,
                    $actualFilterPriority,
                    $classifier,
                    $returnTypeNames,
                    $generatorConfiguration,
                );

                if (!empty($returnTypeNames)) {
                    // Wire pass-through checks on pre-transforming FilterValidators/EnumValidators
                    // so they are skipped when an already-transformed value is provided.
                    // Only validators present at this point (i.e. before the transforming filter)
                    // receive the check — post-transform validators are added later and use
                    // !$transformationFailed instead.
                    $this->addTransformedValuePassThrough($property, $filter, $returnTypeNames);

                    $objectReturnTypes = array_values(array_filter(
                        $returnTypeNames,
                        static fn(string $type): bool => !TypeCheck::isPrimitive($type),
                    ));
                    if (!empty($objectReturnTypes)) {
                        $this->addExtendedInstanceOfCheckForObjectBranches(
                            $property,
                            $objectReturnTypes,
                            $actualFilterPriority,
                        );
                    }

                    // Eagerly set the output type when the base type is already known.
                    // This preserves the output type through property cloning in merged composition
                    // schemas (where validators are stripped but the type fields are retained).
                    // When the base type is null (type comes from a sibling allOf branch), this is
                    // skipped and TransformingFilterOutputTypePostProcessor handles it after
                    // composition has resolved the final base type.
                    $baseType = $property->getType();
                    if ($baseType !== null) {
                        $this->applyOutputType(
                            $property,
                            $filter,
                            $returnTypeNames,
                            $baseType,
                            $generatorConfiguration,
                            $schema,
                        );
                    }
                }

                $transformingFilter = $filter;
            }
        }
    }

    /**
     * Adds a property-level validator that rejects objects whose type is not in the filter's
     * declared non-primitive return types (e.g. rejects stdClass when the filter returns DateTime).
     *
     * Empty object schemas ({type: object} with no declared properties) in composition branches
     * have their strict instanceof check removed so that any PHP object passes the branch's
     * type check. Without a narrowing check at the property level, foreign objects would
     * silently pass through. Placing the validator at property level ensures
     * InvalidInstanceOfException propagates directly rather than being absorbed by the
     * composition template's catch block.
     *
     * Only adds the validator when at least one composition branch had its instanceof removed
     * (i.e. has an empty nested schema with no declared properties).
     *
     * @param string[] $objectReturnTypes Non-primitive PHP class names returned by the filter.
     * @param int      $filterPriority    Priority at which the transforming filter was added;
     *                                    the check is scheduled one step later so it runs on
     *                                    the already-transformed value.
     */
    private function addExtendedInstanceOfCheckForObjectBranches(
        PropertyInterface $property,
        array $objectReturnTypes,
        int $filterPriority,
    ): void {
        $hasEmptyObjectBranch = false;
        foreach ($property->getValidators() as $validatorContainer) {
            $validator = $validatorContainer->getValidator();

            // Unwrap a FilterPreTransformGuardValidator to reach the underlying composed validator.
            if ($validator instanceof FilterPreTransformGuardValidator) {
                $validator = $validator->getInnerValidator();
            }

            if (!is_a($validator, AbstractComposedPropertyValidator::class)) {
                continue;
            }

            /** @var AbstractComposedPropertyValidator $composedValidator */
            $composedValidator = $validator;

            foreach ($composedValidator->getComposedProperties() as $compositionProperty) {
                $nestedSchema = $compositionProperty->getNestedSchema();
                if ($nestedSchema === null || !empty($nestedSchema->getProperties())) {
                    continue;
                }

                $instanceOfRemoved = true;
                foreach ($compositionProperty->getValidators() as $compositionValidator) {
                    if (is_a($compositionValidator->getValidator(), InstanceOfValidator::class)) {
                        $instanceOfRemoved = false;
                        break;
                    }
                }

                if ($instanceOfRemoved) {
                    $hasEmptyObjectBranch = true;
                    break 2;
                }
            }
        }

        if (!$hasEmptyObjectBranch) {
            return;
        }

        $instanceOfParts = implode(' || ', array_map(
            static fn(string $cls): string => "\$value instanceof $cls",
            $objectReturnTypes,
        ));

        $property->addValidator(
            new PropertyValidator(
                $property,
                "is_object(\$value) && !(\$value instanceof \\Exception) && !($instanceOfParts)",
                InvalidInstanceOfException::class,
                [reset($objectReturnTypes)],
            ),
            $filterPriority + 1,
        );
    }

    /**
     * After identifying a transforming filter at priority P, scan all existing validators
     * on the property and adjust their run order:
     *
     * - Validators with a source key whose Draft-registered types are a subset of the
     *   filter's output type-space → leave at their current priority (post-transform).
     * - All other validators with a source key (input-space, mixed, ambiguous) that are
     *   currently scheduled to run after the filter → move to just before the filter (P-1),
     *   so they execute against the raw input value.
     * - Validators without a source key (type-check, required, non-transforming filters)
     *   are untouched regardless of their priority.
     * - Composition validators (AbstractComposedPropertyValidator) are classified by their
     *   branch type-space and repositioned accordingly:
     *     - All input-space or ambiguous → moved to P-1, wrapped in a skip guard so that
     *       already-transformed values bypass the pre-transform check.
     *     - All output-space → left post-filter (default).
     *     - Mixed-space allOf (some input, some output branches) → split into a pre-filter
     *       input-only subset and a post-filter output-only subset; the original is removed.
     *
     * @param int      $filterPriority  The actual priority at which the transforming filter was added.
     * @param string[] $returnTypeNames Non-null return type names of the transforming filter;
     *                                  used to build the skip condition for the pre-transform guards.
     */
    private function reassignValidatorPriorities(
        PropertyInterface $property,
        int $filterPriority,
        CompositionBranchClassifier $classifier,
        array $returnTypeNames,
        GeneratorConfiguration $generatorConfiguration,
    ): void {
        $skipCheck = !empty($returnTypeNames) ? TypeCheck::buildCompound($returnTypeNames) : '';

        [$inputSpaceComposed, $mixedAllOf] = $this->classifyValidatorAdjustments(
            $property,
            $filterPriority,
            $classifier,
            $returnTypeNames,
        );

        $this->wrapInputSpaceGuards(
            $property,
            $inputSpaceComposed,
            $filterPriority,
            $skipCheck,
            $generatorConfiguration,
        );

        $this->splitMixedSpaceAllOf(
            $property,
            $mixedAllOf,
            $filterPriority,
            $skipCheck,
            $returnTypeNames,
            $generatorConfiguration,
        );
    }

    /**
     * Scan all post-filter validators on the property, move scalar input-space validators
     * to just before the filter, and return two accumulator lists for deferred structural
     * changes that cannot be applied while iterating the validator list:
     *
     *  - $inputSpaceComposed: uniform input-space composition validators that need a
     *    pre-transform guard when return types are known.
     *  - $mixedAllOf: allOf validators whose branches span both type-spaces and must be
     *    split into separate pre- and post-filter subsets.
     *
     * @param string[] $returnTypeNames
     *
     * @return array{
     *     list<array{container: Validator, validator: AbstractComposedPropertyValidator}>,
     *     list<array{
     *         container: Validator, validator: ComposedPropertyValidator,
     *         inputIndices: int[], outputIndices: int[]
     *     }>
     * }
     */
    private function classifyValidatorAdjustments(
        PropertyInterface $property,
        int $filterPriority,
        CompositionBranchClassifier $classifier,
        array $returnTypeNames,
    ): array {
        /** @var list<array{container: Validator, validator: AbstractComposedPropertyValidator}> */
        $inputSpaceComposed = [];

        /** @var list<array{container: Validator, validator: ComposedPropertyValidator, inputIndices: int[], outputIndices: int[]}> */
        $mixedAllOf = [];

        foreach ($property->getValidators() as $validatorContainer) {
            if ($validatorContainer->getPriority() < $filterPriority) {
                continue; // Already scheduled before the filter; no adjustment needed.
            }

            if (is_a($validatorContainer->getValidator(), FilterValidator::class)) {
                continue; // Skip the filter validators themselves.
            }

            if (is_a($validatorContainer->getValidator(), AbstractComposedPropertyValidator::class)) {
                /** @var AbstractComposedPropertyValidator $composedValidator */
                $composedValidator = $validatorContainer->getValidator();

                [$inputIndices, $outputIndices] = $this->classifyComposedValidatorBranches(
                    $composedValidator,
                    $classifier,
                );

                // Only allOf can have mixed spaces (static rejection guarantees anyOf/oneOf/not/
                // if-then-else have uniform spaces). Collect mixed-space allOf validators for
                // splitting in splitMixedSpaceAllOf().
                if (
                    !empty($inputIndices) && !empty($outputIndices)
                    && $composedValidator instanceof ComposedPropertyValidator
                    && $composedValidator->getCompositionProcessor() === AllOfValidatorFactory::class
                ) {
                    $mixedAllOf[] = [
                        'container'     => $validatorContainer,
                        'validator'     => $composedValidator,
                        'inputIndices'  => $inputIndices,
                        'outputIndices' => $outputIndices,
                    ];
                    continue;
                }

                if (empty($outputIndices)) {
                    // All branches are input-space or ambiguous (Empty → Input by liberal policy).
                    // Defer guard wrapping when return types are known; otherwise move directly.
                    if (!empty($returnTypeNames)) {
                        $inputSpaceComposed[] = [
                            'container' => $validatorContainer,
                            'validator' => $composedValidator,
                        ];
                    } else {
                        $validatorContainer->setPriority($filterPriority - 1);
                    }
                }
                // Output-space composition validators: leave at their current post-filter position.

                continue;
            }

            $sourceKey = $validatorContainer->getSourceKey();
            if ($sourceKey === null) {
                // No source key: validator was not produced by a Draft AbstractValidatorFactory
                // (e.g. PassThroughTypeCheckValidator). Leave at its current position.
                continue;
            }

            $typeSpace = $classifier->classifySchemaKey($sourceKey);

            if ($typeSpace === TypeSpace::Output) {
                continue; // Output-space validators belong after the filter.
            }

            // Input-space, mixed, and ambiguous validators must run before the filter
            // so they validate the raw input value.
            $validatorContainer->setPriority($filterPriority - 1);
        }

        return [$inputSpaceComposed, $mixedAllOf];
    }

    /**
     * Replace each uniform input-space composition validator with a FilterPreTransformGuardValidator
     * that short-circuits when the value is already in the filter's output type-space.
     *
     * @param list<array{container: Validator, validator: AbstractComposedPropertyValidator}> $inputSpaceComposed
     */
    private function wrapInputSpaceGuards(
        PropertyInterface $property,
        array $inputSpaceComposed,
        int $filterPriority,
        string $skipCheck,
        GeneratorConfiguration $generatorConfiguration,
    ): void {
        foreach ($inputSpaceComposed as ['container' => $originalContainer, 'validator' => $composedValidator]) {
            $property->filterValidators(
                static fn(Validator $container): bool => $container !== $originalContainer,
            );
            $property->addValidator(
                new FilterPreTransformGuardValidator(
                    $generatorConfiguration,
                    $property,
                    $composedValidator,
                    $skipCheck,
                ),
                $filterPriority - 1,
            );
        }
    }

    /**
     * Replace each mixed-space allOf validator with a pre-filter input-subset (wrapped in a guard
     * when return types are known) and a post-filter output-subset at the original priority.
     *
     * @param list<array{
     *     container: Validator, validator: ComposedPropertyValidator,
     *     inputIndices: int[], outputIndices: int[]
     * }> $mixedAllOf
     * @param string[] $returnTypeNames
     */
    private function splitMixedSpaceAllOf(
        PropertyInterface $property,
        array $mixedAllOf,
        int $filterPriority,
        string $skipCheck,
        array $returnTypeNames,
        GeneratorConfiguration $generatorConfiguration,
    ): void {
        foreach (
            $mixedAllOf as [
            'container' => $originalContainer,
            'validator'     => $originalValidator,
            'inputIndices'  => $inputIndices,
            'outputIndices' => $outputIndices,
            ]
        ) {
            $property->filterValidators(
                static fn(Validator $container): bool => $container !== $originalContainer,
            );

            // Input-space subset runs before the filter; wrap in a guard when return types are known.
            $preTransformValidator = $originalValidator->createSubsetValidator($inputIndices, '_pre_filter');
            if (!empty($returnTypeNames)) {
                $property->addValidator(
                    new FilterPreTransformGuardValidator(
                        $generatorConfiguration,
                        $property,
                        $preTransformValidator,
                        $skipCheck,
                    ),
                    $filterPriority - 1,
                );
            } else {
                $property->addValidator($preTransformValidator, $filterPriority - 1);
            }

            // Output-space subset runs at the original validator's priority so its position
            // relative to other post-transform validators is preserved.
            $postTransformValidator = $originalValidator->createSubsetValidator($outputIndices, '_post_filter');
            $property->addValidator($postTransformValidator, $originalContainer->getPriority());
        }
    }

    /**
     * Classify the branches of a composition validator into input-space and output-space
     * index lists using the given CompositionBranchClassifier.
     *
     * Empty/ambiguous branches (TypeSpace::Empty) are treated as input-space per the
     * liberal policy, consistent with CompositionBranchClassifier.
     *
     * @return array{int[], int[]}  [inputIndices, outputIndices]
     */
    private function classifyComposedValidatorBranches(
        AbstractComposedPropertyValidator $validator,
        CompositionBranchClassifier $classifier,
    ): array {
        $inputIndices  = [];
        $outputIndices = [];

        foreach ($validator->getComposedProperties() as $index => $compositionProperty) {
            $branchSchema = $compositionProperty->getBranchSchema()->getJson();
            $space = $classifier->classify($branchSchema);

            if ($space === TypeSpace::Output) {
                $outputIndices[] = $index;
            } else {
                // Input, Mixed (statically rejected — shouldn't occur), and Empty → Input
                $inputIndices[] = $index;
            }
        }

        return [$inputIndices, $outputIndices];
    }

    /**
     * Compute the output type using the bypass formula and apply it to the property.
     *
     * Formula:
     *   accepted      = filter callable's first-parameter types ([] = accepts all)
     *   bypass_names  = base_names − non-null accepted  ([] when accepted is empty)
     *   bypass_nullable = base_nullable AND 'null' NOT in accepted  (false when accepted is empty)
     *   output_names  = bypass_names ∪ return_type_names
     *   output_nullable = bypass_nullable OR return_nullable
     *
     * @param string[] $returnTypeNames Non-null return type names of the transforming filter.
     *
     * @throws ReflectionException
     * @throws SchemaException
     */
    public function applyOutputType(
        PropertyInterface $property,
        TransformingFilterInterface $filter,
        array $returnTypeNames,
        PropertyType $baseType,
        GeneratorConfiguration $generatorConfiguration,
        Schema $schema,
    ): void {
        $returnNullable = FilterReflection::isReturnNullable($filter);
        $acceptedTypes = FilterReflection::getAcceptedTypes($filter, $property);

        if (empty($acceptedTypes)) {
            $bypassNames = [];
            $bypassNullable = false;
        } else {
            $nonNullAccepted = array_values(
                array_filter($acceptedTypes, static fn(string $type): bool => $type !== 'null'),
            );
            $hasNullAccepted = in_array('null', $acceptedTypes, true);
            $bypassNames = array_values(array_diff($baseType->getNames(), $nonNullAccepted));
            $bypassNullable = ($baseType->isNullable() === true) && !$hasNullAccepted;
        }

        $baseNames = $baseType->getNames();
        $newReturnTypeNames = array_values(array_diff($returnTypeNames, $baseNames));

        if (empty($newReturnTypeNames)) {
            return;
        }

        $outputNames = array_values(array_unique(array_merge($bypassNames, $returnTypeNames)));
        $outputNullable = $bypassNullable || $returnNullable;

        $renderHelper = new RenderHelper($generatorConfiguration);
        $outputTypeNames = array_map(
            static fn(string $name): string => $renderHelper->getSimpleClassName($name),
            $outputNames,
        );

        $property->setType(
            $property->getType(),
            new PropertyType($outputTypeNames, $outputNullable),
        );

        foreach ($returnTypeNames as $typeName) {
            if (!TypeCheck::isPrimitive($typeName)) {
                $schema->addUsedClass($typeName);
            }
        }
    }

    /**
     * Replace the property's TypeCheckValidator / MultiTypeCheckValidator with a
     * PassThroughTypeCheckValidator that also allows the given pass-through type names.
     *
     * When called a second time, the TypeCheckValidator has already been replaced by a
     * PassThroughTypeCheckValidator, which does not match the filter predicate, so the call
     * is silently skipped.
     *
     * @param string[] $passThroughTypeNames
     */
    public function extendTypeCheckValidatorToAllowTransformedValue(
        PropertyInterface $property,
        array $passThroughTypeNames,
    ): void {
        $typeCheckValidator = null;

        $property->filterValidators(static function (Validator $validator) use (&$typeCheckValidator): bool {
            if (
                is_a($validator->getValidator(), TypeCheckValidator::class) ||
                is_a($validator->getValidator(), MultiTypeCheckValidator::class)
            ) {
                $typeCheckValidator = $validator->getValidator();
                return false;
            }

            return true;
        });

        if (
            $typeCheckValidator instanceof TypeCheckValidator
            || $typeCheckValidator instanceof MultiTypeCheckValidator
        ) {
            $property->addValidator(
                new PassThroughTypeCheckValidator($passThroughTypeNames, $property, $typeCheckValidator),
                2,
            );
        }
    }

    /**
     * Build and return the Draft instance for the given property's schema.
     *
     * Resolves DraftFactoryInterface vs DraftInterface from the GeneratorConfiguration
     * and builds the immutable Draft registry.
     */
    private function resolveBuiltDraft(
        GeneratorConfiguration $generatorConfiguration,
        PropertyInterface $property,
    ): Draft {
        $configDraft = $generatorConfiguration->getDraft();

        $draftInterface = $configDraft instanceof DraftFactoryInterface
            ? $configDraft->getDraftForSchema($property->getJsonSchema())
            : $configDraft;

        return $draftInterface->getDefinition()->build();
    }

    /**
     * Apply a pass-through check to each FilterValidator and EnumValidator already associated
     * with the given property so that pre-transform filters and enum checks are skipped when
     * an already-transformed value is provided.
     *
     * @param string[] $returnTypeNames Non-null return type names of the transforming filter.
     */
    public function addTransformedValuePassThrough(
        PropertyInterface $property,
        TransformingFilterInterface $filter,
        array $returnTypeNames,
    ): void {
        foreach ($property->getValidators() as $propertyValidator) {
            $validator = $propertyValidator->getValidator();

            if ($validator instanceof FilterValidator) {
                $validator->addTransformedCheck($filter, $property);
            }

            if ($validator instanceof FormatValidator) {
                $this->replaceValidatorWithGuardedCheck(
                    $property,
                    $validator,
                    FormatValidator::class,
                    sprintf('is_string($value) && %s', $validator->getCheck()),
                );
            }

            if ($validator instanceof EnumValidator) {
                $this->replaceValidatorWithGuardedCheck(
                    $property,
                    $validator,
                    EnumValidator::class,
                    sprintf('%s && %s', TypeCheck::buildNegatedCompound($returnTypeNames), $validator->getCheck()),
                );
            }
        }
    }

    /**
     * Remove all validators of the given class from the property and re-add the same validation
     * logic wrapped in a new check expression, at priority 3.
     *
     * The property name is stripped from exceptionParams before re-adding because
     * AbstractPropertyValidator::getExceptionParams() prepends it again automatically.
     */
    private function replaceValidatorWithGuardedCheck(
        PropertyInterface $property,
        AbstractPropertyValidator $validator,
        string $validatorClass,
        string $guardedCheck,
    ): void {
        $property->filterValidators(
            static fn(Validator $candidate): bool => !is_a($candidate->getValidator(), $validatorClass),
        );

        $exceptionParams = $validator->getExceptionParams();
        array_shift($exceptionParams);

        $property->addValidator(
            new PropertyValidator(
                $property,
                $guardedCheck,
                $validator->getExceptionClass(),
                $exceptionParams,
            ),
            3,
        );
    }
}
