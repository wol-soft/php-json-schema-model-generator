<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\PropertyProcessor\Filter\FilterInterface;

class FilterValidator extends PropertyValidator
{
    /**
     * FilterValidator constructor.
     *
     * @param FilterInterface $filter
     * @param PropertyInterface $property
     *
     * @throws SchemaException
     */
    public function __construct(FilterInterface $filter, PropertyInterface $property)
    {
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
            // check if the given value has a type matched by the filter
            (!empty($filter->getAcceptedTypes())
                ? '($value !== null && (!is_' . implode('($value) && !is_', $filter->getAcceptedTypes()) . '($value)))'
                : ''
            ) . sprintf(
                // Call the filter, afterwards make sure the condition is false so no exception will be thrown
                ' || (($value = call_user_func([\%s::class, "%s"], $value)) && false)',
                $filter->getFilter()[0],
                $filter->getFilter()[1]
            ),
            sprintf(
                'Filter %s is not compatible with property type " . gettype($value) . " for property %s',
                $filter->getToken(),
                $property->getName()
            )
        );
    }
}