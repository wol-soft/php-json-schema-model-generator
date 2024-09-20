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
    /**
     * MultiTypeCheckValidator constructor.
     *
     * @param string[]          $types
     */
    public function __construct(protected array $types, PropertyInterface $property, bool $allowImplicitNull)
    {
        // if null is explicitly allowed we don't need an implicit null pass through
        if (in_array('null', $this->types)) {
            $allowImplicitNull = false;
        }

        parent::__construct(
            $property,
            join(
                ' && ',
                array_map(
                    static fn(string $allowedType): string =>
                        ReflectionTypeCheckValidator::fromType($allowedType, $property)->getCheck(),
                    $this->types,
                )
            ) . ($allowImplicitNull ? ' && $value !== null' : ''),
            InvalidTypeException::class,
            [$this->types],
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
