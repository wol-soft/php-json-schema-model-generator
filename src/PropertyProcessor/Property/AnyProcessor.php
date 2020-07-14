<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

/**
 * Class AnyProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class AnyProcessor extends AbstractValueProcessor
{
    /**
     * @param string $propertyName
     * @param JsonSchema $propertySchema
     *
     * @return PropertyInterface
     */
    public function process(string $propertyName, JsonSchema $propertySchema): PropertyInterface
    {
        $property = parent::process($propertyName, $propertySchema);

        if (isset($propertySchema->getJson()['default'])) {
            $property->setDefaultValue($propertySchema->getJson()['default']);
        }

        return $property;
    }
}
