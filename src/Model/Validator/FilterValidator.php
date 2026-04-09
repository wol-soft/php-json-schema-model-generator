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

        if ($this->transformingFilter !== null) {
            // Post-transforming filter: check against the transformed return type.
            $this->validateFilterCompatibilityWithTransformedType($this->filter, $this->transformingFilter, $property);
        } elseif (
            $property->getType() !== null ||
            $property->getNestedSchema() !== null ||
            $this->filter instanceof TransformingFilterInterface
        ) {
            // Typed property or transforming filter on untyped property: check immediately.
            // Transforming filters must not be deferred because FilterProcessor calls setType()
            // right after this constructor returns, mutating the property type before the
            // post-processor runs.
            $this->runCompatibilityCheck($this->filter, $property);
        }
        // Untyped property + regular non-transforming filter: defer to FilterCompatibilityPostProcessor.

        parent::__construct(
            $property,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'Filter.phptpl',
            [
                'skipTransformedValuesCheck' => $this->transformingFilter !== null ? '!$transformationFailed' : '',
                'isTransformingFilter' => $this->filter instanceof TransformingFilterInterface,
                // check if the given value has a type matched by the filter;
                // 'mixed' in acceptedTypes means "all types" — no runtime check needed
                'typeCheck' => !empty($this->filter->getAcceptedTypes()) &&
                               !in_array('mixed', $this->filter->getAcceptedTypes(), true)
                    ? '(' .
                        implode(
                            ' && ',
                            array_map(
                                static fn(string $type): string =>
                                    ReflectionTypeCheckValidator::fromType($type, $property)->getCheck(),
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
     * Core filter compatibility check. Handles both typed and untyped properties.
     *
     * For typed / nested-object properties, every property type must appear in the filter's
     * acceptedTypes. For untyped properties, the filter must cover every PHP primitive type so
     * that no runtime value can slip past the check silently.
     *
     * Called from the constructor (typed properties and transforming filters) and from
     * validateCompatibilityWithProperty (deferred check by the post-processor).
     *
     * @throws SchemaException
     */
    private function runCompatibilityCheck(FilterInterface $filter, PropertyInterface $property): void
    {
        if (empty($filter->getAcceptedTypes()) || in_array('mixed', $filter->getAcceptedTypes(), true)) {
            return;
        }

        $mappedAcceptedTypes = array_map(TypeConverter::jsonSchemaToPHP(...), $filter->getAcceptedTypes());

        if ($property->getType() !== null || $property->getNestedSchema() !== null) {
            $typeNames = $property->getNestedSchema() !== null
                ? ['object']
                : $property->getType()->getNames();

            $incompatibleTypes = array_diff($typeNames, $mappedAcceptedTypes);
            if ($property->getType()?->isNullable() && !in_array('null', $filter->getAcceptedTypes())) {
                $incompatibleTypes[] = 'null';
            }

            if (!empty($incompatibleTypes)) {
                throw new SchemaException(
                    sprintf(
                        'Filter %s is not compatible with property type %s for property %s in file %s',
                        $filter->getToken(),
                        implode('|', array_merge($typeNames, $property->getType()?->isNullable() ? ['null'] : [])),
                        $property->getName(),
                        $property->getJsonSchema()->getFile(),
                    )
                );
            }
        } else {
            // Untyped property: the filter must cover every PHP primitive type.
            $allPhpTypes    = ['string', 'int', 'float', 'bool', 'array', 'object', 'null'];
            $uncoveredTypes = array_diff($allPhpTypes, $mappedAcceptedTypes);

            if (!empty($uncoveredTypes)) {
                throw new SchemaException(
                    sprintf(
                        'Filter %s is not compatible with untyped property %s in file %s'
                            . ' (not all types are accepted: %s)',
                        $filter->getToken(),
                        $property->getName(),
                        $property->getJsonSchema()->getFile(),
                        implode('|', $uncoveredTypes),
                    )
                );
            }
        }
    }

    /**
     * Re-validate base-type compatibility after full composition resolution.
     * Called by FilterCompatibilityPostProcessor on the outer schema's final properties.
     *
     * @throws SchemaException
     */
    public function validateCompatibilityWithProperty(PropertyInterface $property): void
    {
        // Post-transforming filters were already verified against the transformed type in the
        // constructor — re-checking against the base type would be wrong.
        if ($this->transformingFilter !== null) {
            return;
        }

        // Transforming filters were already verified strictly in the constructor.
        // setType() has since mutated $property->getType() to the transformed type,
        // so re-running runCompatibilityCheck here would produce a false positive.
        if ($this->filter instanceof TransformingFilterInterface) {
            return;
        }

        $this->runCompatibilityCheck($this->filter, $property);
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
