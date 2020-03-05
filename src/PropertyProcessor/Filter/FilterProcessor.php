<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Filter;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\FilterValidator;

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
     *
     * @throws SchemaException
     */
    public function process(
        PropertyInterface $property,
        $filterList,
        GeneratorConfiguration $generatorConfiguration
    ): void {
        if (is_string($filterList)) {
            $filterList = [$filterList];
        }

        foreach ($filterList as $filterToken) {
            if (!($filter = $generatorConfiguration->getFilter($filterToken))) {
                throw new SchemaException("Unsupported filter $filterToken");
            }

            $property->addValidator(new FilterValidator($filter, $property), 3);
        }
    }
}
