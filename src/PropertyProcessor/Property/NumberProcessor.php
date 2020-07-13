<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\IntToFloatCastDecorator;

/**
 * Class NumberProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class NumberProcessor extends AbstractNumericProcessor
{
    protected const TYPE = 'float';

    /**
     * @inheritdoc
     */
    public function process(string $propertyName, JsonSchema $propertySchema): PropertyInterface
    {
        return parent::process($propertyName, $propertySchema)->addDecorator(new IntToFloatCastDecorator());
    }
}
