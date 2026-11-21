<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Object\RequiredValueException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\RenderHelper;

class RequiredPropertyValidator extends PropertyValidator
{
    public function __construct(PropertyInterface $property)
    {
        parent::__construct($property, '', RequiredValueException::class);
    }

    public function getCheck(): string
    {
        $escapedName = RenderHelper::escapeSingleQuoted($this->property->getName());

        return "!array_key_exists('$escapedName', \$modelData)";
    }
}
