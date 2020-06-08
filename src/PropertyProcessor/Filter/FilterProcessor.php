<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Filter;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\FilterValidator;
use ReflectionException;
use ReflectionMethod;

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
                new FilterValidator($generatorConfiguration, $filter, $property, $filterOptions),
                3
            );
        }

        // check if the filter has changed the type of the property
        if ($filter) {
            $typeAfterFilter = (new ReflectionMethod($filter->getFilter()[0], $filter->getFilter()[1]))
                ->getReturnType();

            if ($typeAfterFilter->getName()) {
                $property->setType($property->getType(), $typeAfterFilter->getName());

                // check if a class needs to be imported to use the new return value
                if (!$typeAfterFilter->isBuiltin()) {
                    $schema->addUsedClass($typeAfterFilter->getName());
                }
            }
        }
    }
}
