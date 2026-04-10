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
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

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

        $acceptedTypes = $this->getAcceptedTypes($this->filter);

        parent::__construct(
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'Filter.phptpl',
            [
                'skipTransformedValuesCheck' => $this->transformingFilter !== null ? '!$transformationFailed' : '',
                'isTransformingFilter' => $this->filter instanceof TransformingFilterInterface,
                // Positive type guard: the filter only executes when the value's runtime type
                // matches one of the acceptedTypes. Non-matching values skip the filter entirely
                // (the && short-circuits before the filter function is called).
                // Empty acceptedTypes means "run for all types" — no guard needed.
                'typeCheck' => !empty($acceptedTypes)
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
                                $acceptedTypes,
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
     * Derive accepted PHP type names from the first parameter of the filter callable.
     *
     * Returns a flat string[] of PHP type names (e.g. ['string', 'null']).
     * Returns an empty array when the parameter has no type hint or is typed as 'mixed',
     * which means the filter accepts all types and no runtime type guard should be generated.
     *
     * @return string[]
     * @throws ReflectionException
     */
    private function getAcceptedTypes(FilterInterface $filter): array
    {
        $params = (new ReflectionMethod($filter->getFilter()[0], $filter->getFilter()[1]))->getParameters();

        if (empty($params)) {
            return [];
        }

        $type = $params[0]->getType();

        if ($type === null) {
            return [];
        }

        if ($type instanceof ReflectionNamedType) {
            if ($type->getName() === 'mixed') {
                return [];
            }

            $types = [$type->getName()];

            if ($type->allowsNull() && $type->getName() !== 'null') {
                $types[] = 'null';
            }

            return $types;
        }

        if ($type instanceof ReflectionUnionType) {
            return array_map(static fn(ReflectionNamedType $t): string => $t->getName(), $type->getTypes());
        }

        return [];
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
            !in_array($typeAfterFilter->getName(), $this->getAcceptedTypes($filter))
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
     * - it accepts all types (empty acceptedTypes derived from callable's first parameter type hint), or
     * - the property is untyped (any non-empty acceptedTypes has overlap with the infinite type space), or
     * - the property's types have at least one overlap with the filter's acceptedTypes.
     *
     * Only a complete zero overlap on a typed property is an error, because the filter could never
     * execute under any circumstances. Partial overlap is fine: the runtime typeCheck guard in the
     * generated code already skips the filter for non-matching value types.
     *
     * @throws SchemaException
     * @throws ReflectionException
     */
    private function runCompatibilityCheck(FilterInterface $filter, PropertyInterface $property): void
    {
        $acceptedTypes = $this->getAcceptedTypes($filter);

        if (empty($acceptedTypes)) {
            return;
        }

        if ($property->getType() === null && $property->getNestedSchema() === null) {
            return;
        }

        $typeNames = $property->getNestedSchema() !== null
            ? ['object']
            : $property->getType()->getNames();
        $isNullable = $property->getType()?->isNullable() ?? false;

        $hasOverlap = !empty(array_intersect($typeNames, $acceptedTypes))
            || ($isNullable && in_array('null', $acceptedTypes, true));

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
     * the subsequent filter is only safe if it accepts every type (empty acceptedTypes).
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
            if (!empty($this->getAcceptedTypes($filter))) {
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

        $acceptedTypes = $this->getAcceptedTypes($filter);

        if (
            !empty($acceptedTypes) &&
            (
                !in_array($typeName, $acceptedTypes) ||
                ($transformedType->allowsNull() && !in_array('null', $acceptedTypes))
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
