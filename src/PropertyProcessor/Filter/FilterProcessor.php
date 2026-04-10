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
use PHPModelGenerator\Model\Validator\ReflectionTypeCheckValidator;
use PHPModelGenerator\Model\Validator\TypeCheckValidator;
use PHPModelGenerator\Utils\RenderHelper;
use ReflectionException;
use ReflectionMethod;
use ReflectionType;

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

            // Holds the resolved output type when this filter is a transforming filter whose
            // output type differs from the current property type; null otherwise.
            $typeAfterFilter = null;

            if ($filter instanceof TransformingFilterInterface) {
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

                $resolvedType = (new ReflectionMethod($filter->getFilter()[0], $filter->getFilter()[1]))
                    ->getReturnType();

                if (
                    $resolvedType &&
                    $resolvedType->getName() &&
                    (!$property->getType() ||
                        !in_array($resolvedType->getName(), $property->getType()->getNames(), true))
                ) {
                    $typeAfterFilter = $resolvedType;

                    $this->extendTypeCheckValidatorToAllowTransformedValue($property, $typeAfterFilter);

                    $property->setType(
                        $property->getType(),
                        new PropertyType(
                            (new RenderHelper($generatorConfiguration))
                                ->getSimpleClassName($typeAfterFilter->getName()),
                            $typeAfterFilter->allowsNull(),
                        )
                    );

                    if (!$typeAfterFilter->isBuiltin()) {
                        $schema->addUsedClass($typeAfterFilter->getName());
                    }
                }
            }

            // $transformingFilter is still null here when the current filter IS the transforming
            // filter — FilterValidator correctly receives null (no previous transforming filter).
            $property->addValidator(
                new FilterValidator($generatorConfiguration, $filter, $property, $filterOptions, $transformingFilter),
                $filterPriority++,
            );

            if ($filter instanceof TransformingFilterInterface) {
                // addTransformedValuePassThrough must run after addValidator so that the transforming
                // filter's own FilterValidator (just added above) also receives the pass-through check.
                if ($typeAfterFilter !== null) {
                    $this->addTransformedValuePassThrough($property, $filter, $typeAfterFilter);
                }

                $transformingFilter = $filter;
            }
        }
    }

    /**
     * Apply a check to each FilterValidator which is already associated with the given property to pass through values
     * which are already transformed.
     * By adding the pass through eg. a trim filter executed before a dateTime transforming filter will not be executed
     * if a DateTime object is provided for the property
     *
     * @throws ReflectionException
     */
    private function addTransformedValuePassThrough(
        PropertyInterface $property,
        TransformingFilterInterface $filter,
        ReflectionType $filteredType,
    ): void {
        foreach ($property->getValidators() as $validator) {
            $validator = $validator->getValidator();

            if ($validator instanceof FilterValidator) {
                $validator->addTransformedCheck($filter, $property);
            }

            if ($validator instanceof EnumValidator) {
                $property->filterValidators(
                    static fn(Validator $validator): bool => !is_a($validator->getValidator(), EnumValidator::class),
                );

                // shift the name from the validator to avoid adding it twice by wrapping the validator into another one
                $exceptionParams = $validator->getExceptionParams();
                array_shift($exceptionParams);

                $property->addValidator(
                    new PropertyValidator(
                        $property,
                        sprintf(
                            "%s && %s",
                            ReflectionTypeCheckValidator::fromReflectionType($filteredType, $property)->getCheck(),
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

    /**
     * Extend a type check of the given property so the type check also allows the type of $typeAfterFilter. This is
     * used to allow also already transformed values as valid input values
     */
    private function extendTypeCheckValidatorToAllowTransformedValue(
        PropertyInterface $property,
        ReflectionType $typeAfterFilter,
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
            // add a combined validator which checks for the transformed value or the original type of the property as a
            // replacement for the removed TypeCheckValidator
            $property->addValidator(
                new PassThroughTypeCheckValidator($typeAfterFilter, $property, $typeCheckValidator),
                2,
            );
        }
    }
}
