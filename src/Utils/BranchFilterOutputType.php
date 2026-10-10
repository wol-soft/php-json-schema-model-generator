<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyType;

/**
 * Output type of a property whose transforming filter is owned by a branch of a composition.
 *
 * The branch may be inactive, so the property holds the value as provided or the transformed value: the output type
 * is the union of the input type, the current output type and the types returned by the filter. The output type of the
 * branch class alone only describes the transformed value, merging the copies of several branches would drop the
 * raw type.
 */
class BranchFilterOutputType
{
    /**
     * @param string[] $returnTypeNames
     */
    public static function create(
        PropertyType $inputType,
        ?PropertyType $currentOutputType,
        array $returnTypeNames,
        bool $returnNullable,
        GeneratorConfiguration $generatorConfiguration,
    ): PropertyType {
        $renderHelper = new RenderHelper($generatorConfiguration);
        $currentOutputType ??= $inputType;

        return new PropertyType(
            array_values(array_unique(array_merge(
                $inputType->getNames(),
                $currentOutputType->getNames(),
                array_map(
                    static fn(string $name): string => $renderHelper->getSimpleClassName($name),
                    $returnTypeNames,
                ),
            ))),
            $inputType->isNullable() === true || $currentOutputType->isNullable() === true || $returnNullable
                ? true
                : $currentOutputType->isNullable(),
        );
    }
}
