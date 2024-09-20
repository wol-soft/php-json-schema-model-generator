<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Utils\ResolvableTrait;

/**
 * Class AbstractPropertyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
abstract class AbstractPropertyValidator implements PropertyValidatorInterface
{
    use ResolvableTrait;

    /**
     * AbstractPropertyValidator constructor.
     */
    public function __construct(
        protected PropertyInterface $property,
        protected string $exceptionClass,
        protected array $exceptionParams = [],
    ) {}

    /**
     * @inheritDoc
     */
    public function withProperty(PropertyInterface $property): PropertyValidatorInterface
    {
        $clone = clone $this;
        $clone->property = $property;

        return $clone;
    }

    /**
     * @inheritDoc
     */
    public function getExceptionClass(): string
    {
        return $this->exceptionClass;
    }

    /**
     * @inheritDoc
     */
    public function getExceptionParams(): array
    {
        return array_merge([$this->property->getName()], $this->exceptionParams);
    }

    /**
     * By default a validator doesn't require a set up
     */
    public function getValidatorSetUp(): string
    {
        return '';
    }

    /**
     * Helper function to remove a RequiredPropertyValidator
     */
    protected function removeRequiredPropertyValidator(PropertyInterface $property): void
    {
        $property->onResolve(static function () use ($property): void {
            $property->filterValidators(static fn(Validator $validator): bool =>
                !is_a($validator->getValidator(), RequiredPropertyValidator::class)
            );
        });
    }
}
