<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\PropertyProcessor\Filter\FilterInterface;
use PHPModelGenerator\Utils\RenderHelper;
use ReflectionException;
use ReflectionMethod;

class FilterValidator extends PropertyTemplateValidator
{
    /**
     * FilterValidator constructor.
     *
     * @param GeneratorConfiguration $generatorConfiguration
     * @param FilterInterface $filter
     * @param PropertyInterface $property
     * @param Schema $schema
     * @param array $filterOptions
     *
     * @throws ReflectionException
     * @throws SchemaException
     */
    public function __construct(
        GeneratorConfiguration $generatorConfiguration,
        FilterInterface $filter,
        PropertyInterface $property,
        Schema $schema,
        array $filterOptions = []
    ) {
        if (!empty($filter->getAcceptedTypes()) &&
            $property->getType() &&
            !in_array($property->getType(), $filter->getAcceptedTypes())
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

        // check if the return type of the provided filter transforms the value. If the value is transformed by the
        // filter make sure the filter is only executed if a non-transformed value is provided.
        // This is required as a setter (eg. for a string property which is modified by the DateTime filter into a
        // DateTime object) also accepts a transformed value (in this case a DateTime object).
        $typeAfterFilter = (new ReflectionMethod($filter->getFilter()[0], $filter->getFilter()[1]))->getReturnType();
        if ($typeAfterFilter &&
            $typeAfterFilter->getName() &&
            !in_array($typeAfterFilter->getName(), $filter->getAcceptedTypes())
        ) {
            $transformedCheck = (new ReflectionTypeCheckValidator($typeAfterFilter, $property, $schema))->getCheck();
        }

        parent::__construct(
            sprintf(
                'Filter %s is not compatible with property type " . gettype($value) . " for property %s',
                $filter->getToken(),
                $property->getName()
            ),
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'Filter.phptpl',
            [
                'skipTransformedValuesCheck' => $transformedCheck ?? '',
                // check if the given value has a type matched by the filter
                'typeCheck' => !empty($filter->getAcceptedTypes())
                    ? '($value !== null && (!is_' .
                        implode('($value) && !is_', $filter->getAcceptedTypes()) .
                        '($value)))'
                    : '',
                'filterClass' => $filter->getFilter()[0],
                'filterMethod' => $filter->getFilter()[1],
                'filterOptions' => var_export($filterOptions, true),
                'transferExceptionMessage' => '{$e->getMessage()}',
                'viewHelper' => new RenderHelper($generatorConfiguration),
            ]
        );
    }
}