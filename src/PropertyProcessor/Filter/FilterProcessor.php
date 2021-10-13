<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Filter;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\EnumValidator;
use PHPModelGenerator\Model\Validator\FilterValidator;
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
     * @param PropertyInterface $property
     * @param mixed $filterList
     * @param GeneratorConfiguration $generatorConfiguration
     * @param Schema $schema
     *
     * @throws ReflectionException
     * @throws SchemaException
     */
    public function process(
        PropertyInterface $property,
        $filterList,
        GeneratorConfiguration $generatorConfiguration,
        Schema $schema
    ): void {
        if (is_string($filterList) || (is_array($filterList) && isset($filterList['filter']))) {
            $filterList = [$filterList];
        }

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
                        $property->getJsonSchema()->getFile()
                    )
                );
            }

            $property->addValidator(
                new FilterValidator($generatorConfiguration, $filter, $property, $filterOptions, $transformingFilter),
                $filterPriority++
            );

            if ($filter instanceof TransformingFilterInterface) {
                if ($property->getType() && $property->getType()->getName() === 'array') {
                    throw new SchemaException(
                        sprintf(
                            'Applying a transforming filter to the array property %s is not supported in file %s',
                            $property->getName(),
                            $property->getJsonSchema()->getFile()
                        )
                    );
                }
                if ($transformingFilter) {
                    throw new SchemaException(
                        sprintf(
                            'Applying multiple transforming filters for property %s is not supported in file %s',
                            $property->getName(),
                            $property->getJsonSchema()->getFile()
                        )
                    );
                }

                // keep track of the transforming filter to modify type checks for following filters
                $transformingFilter = $filter;

                $typeAfterFilter = (new ReflectionMethod($filter->getFilter()[0], $filter->getFilter()[1]))
                    ->getReturnType();

                if ($typeAfterFilter &&
                    $typeAfterFilter->getName() &&
                    (!$property->getType() || $property->getType()->getName() !== $typeAfterFilter->getName())
                ) {
                    $this->addTransformedValuePassThrough($property, $filter, $typeAfterFilter);
                    $this->extendTypeCheckValidatorToAllowTransformedValue($property, $typeAfterFilter);

                    $property->setType(
                        $property->getType(),
                        new PropertyType(
                            (new RenderHelper($generatorConfiguration))
                                ->getSimpleClassName($typeAfterFilter->getName()),
                            $typeAfterFilter->allowsNull()
                        )
                    );

                    if (!$typeAfterFilter->isBuiltin()) {
                        $schema->addUsedClass($typeAfterFilter->getName());
                    }
                }
            }
        }
    }

    /**
     * Apply a check to each FilterValidator which is already associated with the given property to pass through values
     * which are already transformed.
     * By adding the pass through eg. a trim filter executed before a dateTime transforming filter will not be executed
     * if a DateTime object is provided for the property
     *
     * @param PropertyInterface $property
     * @param TransformingFilterInterface $filter
     * @param ReflectionType $filteredType
     *
     * @throws ReflectionException
     */
    private function addTransformedValuePassThrough(
        PropertyInterface $property,
        TransformingFilterInterface $filter,
        ReflectionType $filteredType
    ): void {
        foreach ($property->getValidators() as $validator) {
            $validator = $validator->getValidator();

            if ($validator instanceof FilterValidator) {
                $validator->addTransformedCheck($filter, $property);
            }

            if ($validator instanceof EnumValidator) {
                $property->filterValidators(function (Validator $validator): bool {
                    return !is_a($validator->getValidator(), EnumValidator::class);
                });

                // shift the name from the validator to avoid adding it twice by wrapping the validator into another one
                $exceptionParams = $validator->getExceptionParams();
                array_shift($exceptionParams);

                $property->addValidator(
                    new PropertyValidator(
                        $property,
                        sprintf(
                            "%s && %s",
                            ReflectionTypeCheckValidator::fromReflectionType($filteredType, $property)->getCheck(),
                            $validator->getCheck()
                        ),
                        $validator->getExceptionClass(),
                        $exceptionParams
                    ),
                    3
                );
            }
        }
    }

    /**
     * Extend a type check of the given property so the type check also allows the type of $typeAfterFilter. This is
     * used to allow also already transformed values as valid input values
     *
     * @param PropertyInterface $property
     * @param ReflectionType $typeAfterFilter
     */
    private function extendTypeCheckValidatorToAllowTransformedValue(
        PropertyInterface $property,
        ReflectionType $typeAfterFilter
    ): void {
        $typeCheckValidator = null;

        $property->filterValidators(function (Validator $validator) use (&$typeCheckValidator): bool {
            if (is_a($validator->getValidator(), TypeCheckValidator::class)) {
                $typeCheckValidator = $validator->getValidator();
                return false;
            }

            return true;
        });

        if ($typeCheckValidator instanceof TypeCheckValidator) {
            // add a combined validator which checks for the transformed value or the original type of the property as a
            // replacement for the removed TypeCheckValidator
            $property->addValidator(
                new PassThroughTypeCheckValidator($typeAfterFilter, $property, $typeCheckValidator),
                2
            );
        }
    }
}
