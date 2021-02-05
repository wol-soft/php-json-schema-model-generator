<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class TypeCheckValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class TypeCheckValidator extends PropertyValidator implements TypeCheckInterface
{
    /** @var string */
    protected $type;

    /**
     * TypeCheckValidator constructor.
     *
     * @param string            $type
     * @param PropertyInterface $property
     * @param bool              $allowImplicitNull
     */
    public function __construct(string $type, PropertyInterface $property, bool $allowImplicitNull)
    {
        $this->type = strtolower($type);

        parent::__construct(
            $property,
            "!is_$type(\$value)" . ($allowImplicitNull ? ' && $value !== null' : ''),
            InvalidTypeException::class,
            [$type]
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
