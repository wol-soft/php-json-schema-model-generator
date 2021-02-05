<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator;

/**
 * Class AbstractPropertyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
abstract class AbstractPropertyValidator implements PropertyValidatorInterface
{
    /** @var string */
    protected $exceptionClass;
    /** @var array */
    protected $exceptionParams;
    /** @var PropertyInterface */
    protected $property;

    /**
     * AbstractPropertyValidator constructor.
     *
     * @param PropertyInterface $property
     * @param string $exceptionClass
     * @param array $exceptionParams
     */
    public function __construct(PropertyInterface $property, string $exceptionClass, array $exceptionParams = [])
    {
        $this->property = $property;
        $this->exceptionClass = $exceptionClass;
        $this->exceptionParams = $exceptionParams;
    }

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
     *
     * @return string
     */
    public function getValidatorSetUp(): string
    {
        return '';
    }

    /**
     * Helper function to remove a RequiredPropertyValidator
     *
     * @param PropertyInterface $property
     */
    protected function removeRequiredPropertyValidator(PropertyInterface $property): void
    {
        $property->filterValidators(function (Validator $validator): bool {
            return !is_a($validator->getValidator(), RequiredPropertyValidator::class);
        });
    }
}
