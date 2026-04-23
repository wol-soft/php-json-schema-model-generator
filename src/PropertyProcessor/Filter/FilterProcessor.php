<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\Filter;

use Exception;
use PHPModelGenerator\Draft\Draft;
use PHPModelGenerator\Draft\DraftFactoryInterface;
use PHPModelGenerator\Exception\InvalidFilterException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Filter\ValidateOptionsInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\EnumValidator;
use PHPModelGenerator\Model\Validator\FilterValidator;
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
    public static function normalizeFilterList(mixed $filterList): array
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
        mixed $filterList,
        GeneratorConfiguration $generatorConfiguration,
        Schema $schema,
    ): void {
        $filterList = self::normalizeFilterList($filterList);

        $transformingFilter = null;
        // apply a different priority to each filter to make sure the order is kept
        $filterPriority = 10 + count($property->getValidators());

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

                $this->reassignValidatorPriorities($property, $actualFilterPriority, $classifier);

                if (!empty($returnTypeNames)) {
                    // Wire pass-through checks on pre-transforming FilterValidators/EnumValidators
                    // so they are skipped when an already-transformed value is provided.
                    // Only validators present at this point (i.e. before the transforming filter)
                    // receive the check — post-transform validators are added later and use
                    // !$transformationFailed instead.
                    $this->addTransformedValuePassThrough($property, $filter, $returnTypeNames);

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
     * - Composition validators (AbstractComposedPropertyValidator) are left for Phase 4,
     *   which will split them into pre- and post-transform blocks.
     *
     * @param int $filterPriority The actual priority at which the transforming filter was added.
     */
    private function reassignValidatorPriorities(
        PropertyInterface $property,
        int $filterPriority,
        CompositionBranchClassifier $classifier,
    ): void {
        foreach ($property->getValidators() as $validatorContainer) {
            // Validators already scheduled before the filter need no adjustment.
            if ($validatorContainer->getPriority() < $filterPriority) {
                continue;
            }

            // Skip the filter validators themselves.
            if (is_a($validatorContainer->getValidator(), FilterValidator::class)) {
                continue;
            }

            // Composition validators are handled in Phase 4.
            if (is_a($validatorContainer->getValidator(), AbstractComposedPropertyValidator::class)) {
                continue;
            }

            $sourceKey = $validatorContainer->getSourceKey();
            if ($sourceKey === null) {
                // No source key: validator was not produced by a Draft AbstractValidatorFactory
                // (e.g. PassThroughTypeCheckValidator from addTransformedValuePassThrough).
                // Leave at its current position.
                continue;
            }

            $typeSpace = $classifier->classifySchemaKey($sourceKey);

            // Pure output-space validators (e.g. 'minimum' for a string→int filter) are
            // correct where they are: they must validate the transformed value.
            if ($typeSpace === TypeSpace::Output) {
                continue;
            }

            // Input-space, mixed, and ambiguous validators must run before the filter
            // so they validate the raw input value.
            $validatorContainer->setPriority($filterPriority - 1);
        }
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

            if ($validator instanceof EnumValidator) {
                $property->filterValidators(
                    static fn(Validator $enumCandidate): bool =>
                        !is_a($enumCandidate->getValidator(), EnumValidator::class),
                );

                // Shift the name from the validator to avoid adding it twice by wrapping it.
                $exceptionParams = $validator->getExceptionParams();
                array_shift($exceptionParams);

                $property->addValidator(
                    new PropertyValidator(
                        $property,
                        sprintf(
                            '%s && %s',
                            TypeCheck::buildNegatedCompound($returnTypeNames),
                            $validator->getCheck(),
                        ),
                        $validator->getExceptionClass(),
                        $exceptionParams,
                    ),
                    3,
                );
            }
        }
    }
}
