<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Filter\IncompatibleFilterException;
use PHPModelGenerator\Exception\Filter\InvalidFilterValueException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Filter\FilterInterface;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\RenderHelper;
use ReflectionException;
use ReflectionMethod;

/**
 * Class FilterValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class FilterValidator extends PropertyTemplateValidator
{
    /** @var FilterInterface $filter */
    protected $filter;
    /** @var array */
    protected $filterOptions;

    /**
     * FilterValidator constructor.
     *
     * @param GeneratorConfiguration           $generatorConfiguration
     * @param FilterInterface                  $filter
     * @param PropertyInterface                $property
     * @param array                            $filterOptions
     * @param TransformingFilterInterface|null $transformingFilter
     *
     * @throws SchemaException
     * @throws ReflectionException
     */
    public function __construct(
        GeneratorConfiguration $generatorConfiguration,
        FilterInterface $filter,
        PropertyInterface $property,
        array $filterOptions = [],
        ?TransformingFilterInterface $transformingFilter = null
    ) {
        $this->filter = $filter;
        $this->filterOptions = $filterOptions;

        $transformingFilter === null
            ? $this->validateFilterCompatibilityWithBaseType($filter, $property)
            : $this->validateFilterCompatibilityWithTransformedType($filter, $transformingFilter, $property);

        parent::__construct(
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'Filter.phptpl',
            [
                'skipTransformedValuesCheck' => $transformingFilter !== null ? '!$transformationFailed' : '',
                'isTransformingFilter' => $filter instanceof TransformingFilterInterface,
                // check if the given value has a type matched by the filter
                'typeCheck' => !empty($filter->getAcceptedTypes())
                    ? '(' .
                        implode(' && ', array_map(function (string $type) use ($property): string {
                            return ReflectionTypeCheckValidator::fromType($type, $property)->getCheck();
                        }, $this->mapDataTypes($filter->getAcceptedTypes()))) .
                      ')'
                    : '',
                'filterClass' => $filter->getFilter()[0],
                'filterMethod' => $filter->getFilter()[1],
                'filterOptions' => var_export($filterOptions, true),
                'filterValueValidator' => new PropertyValidator(
                    $property,
                    '',
                    InvalidFilterValueException::class,
                    [$filter->getToken(), '&$filterException']
                ),
                'viewHelper' => new RenderHelper($generatorConfiguration),
            ],
            IncompatibleFilterException::class,
            [$filter->getToken()]
        );
    }

    /**
     * Track if a transformation failed. If a transformation fails don't execute subsequent filter as they'd fail with
     * an invalid type
     *
     * @return string
     */
    public function getValidatorSetUp(): string
    {
        return $this->filter instanceof TransformingFilterInterface
            ? '$transformationFailed = false;'
            : '';
    }

    /**
     * Make sure the filter is only executed if a non-transformed value is provided.
     * This is required as a setter (eg. for a string property which is modified by the DateTime filter into a DateTime
     * object) also accepts a transformed value (in this case a DateTime object).
     * If transformed values are provided neither filters defined before the transforming filter in the filter chain nor
     * the transforming filter must be executed as they are only compatible with the original value
     *
     * @param TransformingFilterInterface $filter
     * @param PropertyInterface $property
     *
     * @return self
     *
     * @throws ReflectionException
     */
    public function addTransformedCheck(TransformingFilterInterface $filter, PropertyInterface $property): self {
        $typeAfterFilter = (new ReflectionMethod($filter->getFilter()[0], $filter->getFilter()[1]))->getReturnType();

        if ($typeAfterFilter &&
            $typeAfterFilter->getName() &&
            !in_array($typeAfterFilter->getName(), $this->mapDataTypes($filter->getAcceptedTypes()))
        ) {
            $this->templateValues['skipTransformedValuesCheck'] = ReflectionTypeCheckValidator::fromReflectionType(
                $typeAfterFilter,
                $property
            )->getCheck();
        }

        return $this;
    }

    /**
     * Check if the given filter is compatible with the base type of the property defined in the schema
     *
     * @param FilterInterface $filter
     * @param PropertyInterface $property
     *
     * @throws SchemaException
     */
    private function validateFilterCompatibilityWithBaseType(FilterInterface $filter, PropertyInterface $property)
    {
        if (empty($filter->getAcceptedTypes()) || !$property->getType()) {
            return;
        }

        if (
            (
                $property->getType()->getName() &&
                !in_array($property->getType()->getName(), $this->mapDataTypes($filter->getAcceptedTypes()))
            ) || (
                $property->getType()->isNullable() && !in_array('null', $filter->getAcceptedTypes())
            )
        ) {
            throw new SchemaException(
                sprintf(
                    'Filter %s is not compatible with property type %s for property %s in file %s',
                    $filter->getToken(),
                    $property->getType()->getName(),
                    $property->getName(),
                    $property->getJsonSchema()->getFile()
                )
            );
        }
    }

    /**
     * Check if the given filter is compatible with the result of the given transformation filter
     *
     * @param FilterInterface $filter
     * @param TransformingFilterInterface $transformingFilter
     * @param PropertyInterface $property
     *
     * @throws ReflectionException
     * @throws SchemaException
     */
    private function validateFilterCompatibilityWithTransformedType(
        FilterInterface $filter,
        TransformingFilterInterface $transformingFilter,
        PropertyInterface $property
    ): void {
        $transformedType = (new ReflectionMethod(
            $transformingFilter->getFilter()[0],
            $transformingFilter->getFilter()[1]
        ))->getReturnType();

        if (!empty($filter->getAcceptedTypes()) &&
            (
                !in_array($transformedType->getName(), $this->mapDataTypes($filter->getAcceptedTypes())) ||
                ($transformedType->allowsNull() && !in_array('null', $filter->getAcceptedTypes()))
            )
        ) {
            throw new SchemaException(
                sprintf(
                    'Filter %s is not compatible with transformed property type %s for property %s in file %s',
                    $filter->getToken(),
                    $transformedType->allowsNull()
                        ? "[null, {$transformedType->getName()}]"
                        : $transformedType->getName(),
                    $property->getName(),
                    $property->getJsonSchema()->getFile()
                )
            );
        }
    }

    /**
     * Map a list of accepted data types to their corresponding PHP types
     *
     * @param array $acceptedTypes
     *
     * @return array
     */
    private function mapDataTypes(array $acceptedTypes): array
    {
        return array_map(function (string $jsonSchemaType): string {
            switch ($jsonSchemaType) {
                case 'integer': return 'int';
                case 'number': return 'float';
                case 'boolean': return 'bool';

                default: return $jsonSchemaType;
            }
        }, $acceptedTypes);
    }

    /**
     * @return FilterInterface
     */
    public function getFilter(): FilterInterface
    {
        return $this->filter;
    }

    /**
     * @return array
     */
    public function getFilterOptions(): array
    {
        return $this->filterOptions;
    }
}
