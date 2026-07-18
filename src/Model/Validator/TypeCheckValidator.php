<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\TypeCheck;

/**
 * Class TypeCheckValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class TypeCheckValidator extends PropertyValidator implements TypeCheckInterface
{
    protected string $type;

    /**
     * TypeCheckValidator constructor.
     */
    public function __construct(string $type, PropertyInterface $property, bool $allowImplicitNull)
    {
        $this->type = strtolower($type);

        parent::__construct(
            $property,
            TypeCheck::buildNegatedJsonSchemaTypeCheck($this->type)
                . ($allowImplicitNull ? ' && $value !== null' : ''),
            InvalidTypeException::class,
            [$type],
        );
    }

    /**
     * @inheritDoc
     */
    public function getTypes(): array
    {
        return [$this->type];
    }
}
