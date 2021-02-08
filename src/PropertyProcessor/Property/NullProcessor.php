<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintDecorator;

/**
 * Class NullProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class NullProcessor extends AbstractTypedValueProcessor
{
    protected const TYPE = 'null';

    /**
     * Explicitly unset the type of the property
     *
     * @param string $propertyName
     * @param JsonSchema $propertySchema
     *
     * @return PropertyInterface
     */
    public function process(string $propertyName, JsonSchema $propertySchema): PropertyInterface
    {
        return (parent::process($propertyName, $propertySchema))
            ->setType(null)
            ->addTypeHintDecorator(new TypeHintDecorator(['null']));
    }
}
