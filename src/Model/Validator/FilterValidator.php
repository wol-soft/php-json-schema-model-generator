<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\PropertyProcessor\Filter\FilterInterface;
use PHPModelGenerator\Utils\RenderHelper;

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

        parent::__construct(
            sprintf(
                'Filter %s is not compatible with property type " . gettype($value) . " for property %s',
                $filter->getToken(),
                $property->getName()
            ),
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'Filter.phptpl',
            [
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