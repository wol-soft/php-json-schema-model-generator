<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\PropertyProcessor\Filter\FilterInterface;
use PHPModelGenerator\PropertyProcessor\Filter\TransformingFilterInterface;
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
    /**
     * FilterValidator constructor.
     *
     * @param GeneratorConfiguration $generatorConfiguration
     * @param FilterInterface $filter
     * @param PropertyInterface $property
     * @param array $filterOptions
     *
     * @throws SchemaException
     */
    public function __construct(
        GeneratorConfiguration $generatorConfiguration,
        FilterInterface $filter,
        PropertyInterface $property,
        array $filterOptions = []
    ) {
        if (!empty($filter->getAcceptedTypes()) &&
            $property->getType() &&
            !in_array($property->getType(), $this->mapDataTypes($filter->getAcceptedTypes()))
        ) {
            throw new SchemaException(
                sprintf(
                    'Filter %s is not compatible with property type %s for property %s',
                    $filter->getToken(),
                    $property->getType(),
                    $property->getName()
                )
            );
        }

        parent::__construct(
            sprintf(
                'Filter %s is not compatible with property type " . gettype($value) . " for property %s',
                $filter->getToken(),
                $property->getName()
            ),
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'Filter.phptpl',
            [
                'skipTransformedValuesCheck' => false,
                // check if the given value has a type matched by the filter
                'typeCheck' => !empty($filter->getAcceptedTypes())
                    ? '($value !== null && (!is_' .
                        implode('($value) && !is_', $this->mapDataTypes($filter->getAcceptedTypes())) .
                        '($value)))'
                    : '',
                'filterClass' => $filter->getFilter()[0],
                'filterMethod' => $filter->getFilter()[1],
                'filterOptions' => var_export($filterOptions, true),
                'transferExceptionMessage' => sprintf(
                    'Invalid value for property %s denied by filter %s: {$e->getMessage()}',
                    $property->getName(),
                    $filter->getToken()
                ),
                'viewHelper' => new RenderHelper($generatorConfiguration),
            ]
        );
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
     * @param Schema $schema
     *
     * @return self
     *
     * @throws ReflectionException
     */
    public function addTransformedCheck(
        TransformingFilterInterface $filter,
        PropertyInterface $property,
        Schema $schema
    ): self {
        $typeAfterFilter = (new ReflectionMethod($filter->getFilter()[0], $filter->getFilter()[1]))->getReturnType();

        if ($typeAfterFilter &&
            $typeAfterFilter->getName() &&
            !in_array($typeAfterFilter->getName(), $this->mapDataTypes($filter->getAcceptedTypes()))
        ) {
            $this->templateValues['skipTransformedValuesCheck'] =
                (new ReflectionTypeCheckValidator($typeAfterFilter, $property, $schema))->getCheck();
        }

        return $this;
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
                case 'string': return 'string';
                case 'boolean': return 'bool';
                case 'array': return 'array';

                default: throw new SchemaException("Invalid accepted type $jsonSchemaType");
            }
        }, $acceptedTypes);
    }
}
