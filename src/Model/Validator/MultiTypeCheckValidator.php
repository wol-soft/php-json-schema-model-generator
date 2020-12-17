<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class MultiTypeCheckValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class MultiTypeCheckValidator extends PropertyValidator implements TypeCheckInterface
{
    /** @var string[] */
    protected $types;

    /**
     * MultiTypeCheckValidator constructor.
     *
     * @param string[]          $types
     * @param PropertyInterface $property
     * @param bool              $allowImplicitNull
     */
    public function __construct(array $types, PropertyInterface $property, bool $allowImplicitNull)
    {
        $this->types = $types;

        // if null is explicitly allowed we don't need an implicit null pass through
        if (in_array('null', $types)) {
            $allowImplicitNull = false;
        }

        parent::__construct(
            $property,
            join(
                ' && ',
                array_map(
                    function (string $allowedType) use ($property) : string {
                        return ReflectionTypeCheckValidator::fromType($allowedType, $property)->getCheck();
                    },
                    $types
                )
            ) . ($allowImplicitNull ? ' && $value !== null' : ''),
            InvalidTypeException::class,
            [$types]
        );
    }

    /**
     * @inheritDoc
     */
    public function getTypes(): array
    {
        return $this->types;
    }
}
