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
use PHPModelGenerator\Utils\FilterReflection;
use PHPModelGenerator\Utils\RenderHelper;
use PHPModelGenerator\Utils\TypeCheck;
use ReflectionException;

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

        $acceptedTypes = FilterReflection::getAcceptedTypes($this->filter, $property);

        $this->transformingFilter !== null
            ? $this->validateFilterCompatibilityWithTransformedType(
                $acceptedTypes,
                $this->transformingFilter,
                $property,
            )
            : $this->runCompatibilityCheck($acceptedTypes, $property);

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
                    ? TypeCheck::buildCompound($acceptedTypes)
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
     * @throws SchemaException
     */
    public function addTransformedCheck(TransformingFilterInterface $filter, PropertyInterface $property): self
    {
        $returnTypeNames = FilterReflection::getReturnTypeNames($filter, $property);
        $acceptedTypes = FilterReflection::getAcceptedTypes($filter, $property);
        $nonAccepted = array_values(array_diff($returnTypeNames, $acceptedTypes));

        if (!empty($nonAccepted)) {
            $this->templateValues['skipTransformedValuesCheck'] = TypeCheck::buildNegatedCompound($nonAccepted);
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
     * @param string[] $acceptedTypes Pre-computed accepted types of the filter.
     *
     * @throws SchemaException
     */
    private function runCompatibilityCheck(array $acceptedTypes, PropertyInterface $property): void
    {
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
                    $this->filter->getToken(),
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
     * All parts of the transformed output (including null when nullable) must be accepted by
     * the subsequent filter. Any unhandled return type is an error.
     *
     * @param string[] $filterAcceptedTypes Pre-computed accepted types of the current filter.
     *
     * @throws ReflectionException
     * @throws SchemaException
     */
    private function validateFilterCompatibilityWithTransformedType(
        array $filterAcceptedTypes,
        TransformingFilterInterface $transformingFilter,
        PropertyInterface $property,
    ): void {
        $returnTypeNames = FilterReflection::getReturnTypeNames($transformingFilter, $property);
        $returnNullable = FilterReflection::isReturnNullable($transformingFilter);

        if (empty($returnTypeNames) && !$returnNullable) {
            // Return type is mixed or null-only — subsequent filter must accept all types.
            if (!empty($filterAcceptedTypes)) {
                throw new SchemaException(
                    sprintf(
                        'Filter %s is not compatible with the unconstrained output of'
                            . ' transforming filter %s for property %s in file %s'
                            . ' (not all types are accepted)',
                        $this->filter->getToken(),
                        $transformingFilter->getToken(),
                        $property->getName(),
                        $property->getJsonSchema()->getFile(),
                    )
                );
            }

            return;
        }

        if (empty($filterAcceptedTypes)) {
            // Next filter accepts everything — always compatible.
            return;
        }

        // All parts of the return type must be handled by the next filter's accepted types.
        $allReturnTypes = $returnNullable
            ? array_merge($returnTypeNames, ['null'])
            : $returnTypeNames;
        $unhandled = array_diff($allReturnTypes, $filterAcceptedTypes);

        if (!empty($unhandled)) {
            $displayTypes = $returnNullable
                ? array_merge(['null'], $returnTypeNames)
                : $returnTypeNames;
            $typeDisplay = count($displayTypes) > 1
                ? '[' . implode(', ', $displayTypes) . ']'
                : $displayTypes[0];

            throw new SchemaException(
                sprintf(
                    'Filter %s is not compatible with transformed property type %s for property %s in file %s',
                    $this->filter->getToken(),
                    $typeDisplay,
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
