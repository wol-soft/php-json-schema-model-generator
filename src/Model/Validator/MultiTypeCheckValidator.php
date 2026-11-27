<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Model\Property\PropertyInterface;

class MultiTypeCheckValidator extends PropertyValidator implements TypeCheckInterface
{
    /**
     * @param string[]          $types
     * @param bool              $treatObjectAsUninstantiatedShape True when "object" candidacy
     *                                                             must be checked against a raw,
     *                                                             not-yet-instantiated value - the
     *                                                             property's own composition
     *                                                             validator owns instantiation
     *                                                             instead of ObjectInstantiationDecorator.
     *                                                             See PropertyFactory::createMultiTypeProperty().
     */
    public function __construct(
        protected array $types,
        PropertyInterface $property,
        private readonly bool $allowImplicitNull,
        private readonly bool $treatObjectAsUninstantiatedShape = false,
    ) {
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
                        ReflectionTypeCheckValidator::fromType(
                            $allowedType,
                            $property,
                            $allowedType === 'object' && $treatObjectAsUninstantiatedShape,
                        )->getCheck(),
                    $this->types,
                )
            ) . ($allowImplicitNull ? ' && $value !== null' : ''),
            InvalidTypeException::class,
            [$this->types],
        );
    }

    /**
     * A copy of this check for the same property and JSON pointer which accepts $types instead
     *
     * @param string[] $types
     */
    public function withTypes(array $types): self
    {
        return (new self($types, $this->property, $this->allowImplicitNull, $this->treatObjectAsUninstantiatedShape))
            ->withJsonPointer($this->jsonPointer);
    }

    /**
     * @inheritDoc
     */
    public function getTypes(): array
    {
        return $this->types;
    }
}
