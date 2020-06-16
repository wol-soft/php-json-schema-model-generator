<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class TypeCheckValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class TypeCheckValidator extends PropertyValidator
{
    /**
     * TypeCheckValidator constructor.
     *
     * @param string            $type
     * @param PropertyInterface $property
     * @param bool              $allowImplicitNull
     */
    public function __construct(string $type, PropertyInterface $property, bool $allowImplicitNull)
    {
        parent::__construct(
            '!is_' . strtolower($type) . '($value)' . ($allowImplicitNull ? ' && $value !== null' : ''),
            sprintf('Invalid type for %s. Requires %s, got " . gettype($value) . "', $property->getName(), $type)
        );
    }
}
