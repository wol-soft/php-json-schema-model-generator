<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Filter\IncompatibleFilterException;
use PHPModelGenerator\Exception\Filter\InvalidFilterValueException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Filter\FilterInterface;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\RenderHelper;
use PHPModelGenerator\Utils\TypeConverter;
use ReflectionException;
use ReflectionMethod;

/**
 * Class FilterValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class FilterValidator extends PropertyTemplateValidator
{
    /**
     * FilterValidator constructor.
     *
     * @throws SchemaException
     * @throws ReflectionException
     */
    public function __construct(
        GeneratorConfiguration $generatorConfiguration,
        protected FilterInterface $filter,
        PropertyInterface $property,
        protected array $filterOptions = [],
        private readonly ?TransformingFilterInterface $transformingFilter = null,
    ) {
        $this->isResolved = true;

        $this->transformingFilter !== null
            ? $this->validateFilterCompatibilityWithTransformedType($this->filter, $this->transformingFilter, $property)
            : $this->runCompatibilityCheck($this->filter, $property);

        parent::__construct(
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'Filter.phptpl',
            [
                'skipTransformedValuesCheck' => $this->transformingFilter !== null ? '!$transformationFailed' : '',
                'isTransformingFilter' => $this->filter instanceof TransformingFilterInterface,
                // Positive type guard: the filter only executes when the value's runtime type
                // matches one of the acceptedTypes. Non-matching values skip the filter entirely
                // (the && short-circuits before the filter function is called).
                // 'mixed' or empty acceptedTypes means "run for all types" — no guard needed.
                'typeCheck' => !empty($this->filter->getAcceptedTypes()) &&
                               !in_array('mixed', $this->filter->getAcceptedTypes(), true)
                    ? '(' .
                        implode(
                            ' || ',
                            array_map(
                                static function (string $type): string {
                                    $primitives = ['int', 'float', 'string', 'bool', 'array', 'object', 'null'];
                                    if (in_array($type, $primitives)) {
                                        return "is_{$type}(\$value)";
                                    }

                                    $parts = explode('\\', $type);
                                    return '$value instanceof ' . end($parts);
                                },
                                array_map(TypeConverter::jsonSchemaToPHP(...), $this->filter->getAcceptedTypes()),
                            ),
                        ) .
                      ')'
                    : '',
                'filterClass' => $this->filter->getFilter()[0],
                'filterMethod' => $this->filter->getFilter()[1],
                'filterOptions' => var_export($this->filterOptions, true),
                'filterValueValidator' => new PropertyValidator(
                    $property,
                    '',
                    InvalidFilterValueException::class,
                    [$this->filter->getToken(), '&$filterException'],
                ),
                'viewHelper' => new RenderHelper($generatorConfiguration),
            ],
            IncompatibleFilterException::class,
            [$this->filter->getToken()],
        );
    }

    /**
     * Track if a transformation failed. If a transformation fails don't execute subsequent filter as they'd fail with
     * an invalid type
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
     * @throws ReflectionException
     */
    public function addTransformedCheck(TransformingFilterInterface $filter, PropertyInterface $property): self
    {
        $typeAfterFilter = (new ReflectionMethod($filter->getFilter()[0], $filter->getFilter()[1]))->getReturnType();

        if (
            $typeAfterFilter &&
            $typeAfterFilter->getName() &&
            !in_array(
                $typeAfterFilter->getName(),
                array_map(TypeConverter::jsonSchemaToPHP(...), $filter->getAcceptedTypes()),
            )
        ) {
            $this->templateValues['skipTransformedValuesCheck'] = ReflectionTypeCheckValidator::fromReflectionType(
                $typeAfterFilter,
                $property,
            )->getCheck();
        }

        return $this;
    }

    /**
     * Check if the given filter is compatible with the base type of the property defined in the schema.
     *
     * A filter is compatible when:
     * - it accepts all types (empty acceptedTypes or 'mixed'), or
     * - the property is untyped (any non-empty acceptedTypes has overlap with the infinite type space), or
     * - the property's types have at least one overlap with the filter's acceptedTypes.
     *
     * Only a complete zero overlap on a typed property is an error, because the filter could never
     * execute under any circumstances. Partial overlap is fine: the runtime typeCheck guard in the
     * generated code already skips the filter for non-matching value types.
     *
     * @throws SchemaException
     */
    private function runCompatibilityCheck(FilterInterface $filter, PropertyInterface $property): void
    {
        if (empty($filter->getAcceptedTypes()) || in_array('mixed', $filter->getAcceptedTypes(), true)) {
            return;
        }

        if ($property->getType() === null && $property->getNestedSchema() === null) {
            return;
        }

        $mappedAcceptedTypes = array_map(TypeConverter::jsonSchemaToPHP(...), $filter->getAcceptedTypes());
        $typeNames = $property->getNestedSchema() !== null
            ? ['object']
            : $property->getType()->getNames();
        $isNullable = $property->getType()?->isNullable() ?? false;

        $hasOverlap = !empty(array_intersect($typeNames, $mappedAcceptedTypes))
            || ($isNullable && in_array('null', $filter->getAcceptedTypes(), true));

        if (!$hasOverlap) {
            throw new SchemaException(
                sprintf(
                    'Filter %s is not compatible with property type %s for property %s in file %s',
                    $filter->getToken(),
                    implode('|', array_merge($typeNames, $isNullable ? ['null'] : [])),
                    $property->getName(),
                    $property->getJsonSchema()->getFile(),
                )
            );
        }
    }

    /**
     * Check if the given filter is compatible with the result of the given transformation filter.
     *
     * When the transforming filter's return type is unconstrained (null return type or 'mixed'),
     * the subsequent filter is only safe if it accepts every type ('mixed' or empty acceptedTypes).
     *
     * @throws ReflectionException
     * @throws SchemaException
     */
    private function validateFilterCompatibilityWithTransformedType(
        FilterInterface $filter,
        TransformingFilterInterface $transformingFilter,
        PropertyInterface $property,
    ): void {
        $transformedType = (new ReflectionMethod(
            $transformingFilter->getFilter()[0],
            $transformingFilter->getFilter()[1],
        ))->getReturnType();

        $typeName = $transformedType?->getName();
        if ($transformedType === null || $typeName === 'mixed' || $typeName === '') {
            // Unconstrained output: a subsequent filter is only safe if it accepts all types.
            if (
                !empty($filter->getAcceptedTypes()) &&
                !in_array('mixed', $filter->getAcceptedTypes(), true)
            ) {
                throw new SchemaException(
                    sprintf(
                        'Filter %s is not compatible with the unconstrained output of'
                            . ' transforming filter %s for property %s in file %s'
                            . ' (not all types are accepted)',
                        $filter->getToken(),
                        $transformingFilter->getToken(),
                        $property->getName(),
                        $property->getJsonSchema()->getFile(),
                    )
                );
            }

            return;
        }

        if (
            !empty($filter->getAcceptedTypes()) &&
            (
                !in_array($typeName, array_map(TypeConverter::jsonSchemaToPHP(...), $filter->getAcceptedTypes())) ||
                ($transformedType->allowsNull() && !in_array('null', $filter->getAcceptedTypes()))
            )
        ) {
            throw new SchemaException(
                sprintf(
                    'Filter %s is not compatible with transformed property type %s for property %s in file %s',
                    $filter->getToken(),
                    $transformedType->allowsNull()
                        ? "[null, {$typeName}]"
                        : $typeName,
                    $property->getName(),
                    $property->getJsonSchema()->getFile(),
                )
            );
        }
    }

    public function getFilter(): FilterInterface
    {
        return $this->filter;
    }

    public function getFilterOptions(): array
    {
        return $this->filterOptions;
    }
}
