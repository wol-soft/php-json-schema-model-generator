<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\Filter;

use Exception;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Filter\ValidateOptionsInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator;
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
            $property->addValidator(
                new FilterValidator($generatorConfiguration, $filter, $property, $filterOptions, $transformingFilter),
                $filterPriority++,
            );

            if ($isTransformingFilter) {
                $returnTypeNames = FilterReflection::getReturnTypeNames($filter, $property);

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
