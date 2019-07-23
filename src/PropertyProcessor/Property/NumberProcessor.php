<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Model\Property\PropertyInterface;
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
    public function process(string $propertyName, array $propertyData): PropertyInterface
    {
        return parent::process($propertyName, $propertyData)->addDecorator(new IntToFloatCastDecorator());
    }
}
