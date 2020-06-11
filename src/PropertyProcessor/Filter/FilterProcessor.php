<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Filter;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\FilterValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\ReflectionTypeCheckValidator;
use PHPModelGenerator\Model\Validator\TypeCheckValidator;
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
     * @param $filterList
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
        if (is_string($filterList)) {
            $filterList = [$filterList];
        }

        $filter = null;

        foreach ($filterList as $filterToken) {
            $filterOptions = [];
            if (is_array($filterToken)) {
                $filterOptions = array_diff_key($filterToken, ['filter' => null]);
                $filterToken = $filterToken['filter'] ?? '';
            }

            if (!($filter = $generatorConfiguration->getFilter($filterToken))) {
                throw new SchemaException("Unsupported filter $filterToken");
            }

            $property->addValidator(
                new FilterValidator($generatorConfiguration, $filter, $property, $schema, $filterOptions),
                3
            );
        }

        // check if the last applied filter has changed the type of the property
        if ($filter) {
            $typeAfterFilter = (new ReflectionMethod($filter->getFilter()[0], $filter->getFilter()[1]))
                ->getReturnType();

            if ($typeAfterFilter &&
                $typeAfterFilter->getName() &&
                $property->getType() !== $typeAfterFilter->getName()
            ) {
                $this->extendTypeCheckValidatorToAllowTransformedValue($property, $schema, $typeAfterFilter);

                $property->setType($property->getType(), $typeAfterFilter->getName());
            }
        }
    }

    /**
     * Extend a type check of the given property so the type check also allows the type of $typeAfterFilter. This is
     * used to allow also already transformed values as valid input values
     *
     * @param PropertyInterface $property
     * @param Schema $schema
     * @param ReflectionType $typeAfterFilter
     */
    private function extendTypeCheckValidatorToAllowTransformedValue(
        PropertyInterface $property,
        Schema $schema,
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
                new PropertyValidator(
                    sprintf(
                        '%s && %s',
                        (new ReflectionTypeCheckValidator($typeAfterFilter, $property, $schema))->getCheck(),
                        $typeCheckValidator->getCheck()
                    ),
                    sprintf(
                        'Invalid type for %s. Requires [%s, %s], got " . gettype($value) . "',
                        $property->getName(),
                        $typeAfterFilter->getName(),
                        $property->getType()
                    )
                ),
                2
            );
        }
    }
}
