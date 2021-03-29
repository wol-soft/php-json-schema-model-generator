<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Generic\EnumException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class EnumValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class EnumValidator extends PropertyValidator
{
    /**
     * EnumValidator constructor.
     *
     * @param PropertyInterface $property
     * @param array $allowedValues
     */
    public function __construct(PropertyInterface $property, array $allowedValues)
    {

        parent::__construct(
            $property,
            '!in_array($value, ' . RenderHelper::varExportArray($allowedValues) . ', true)',
            EnumException::class,
            [$allowedValues]
        );
    }
}
