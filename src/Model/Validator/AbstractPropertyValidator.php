<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\Property;
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
        return $this->exceptionParams;
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
        if ($property instanceof Property) {
            $property->filterValidators(function (Validator $validator): bool {
                return !is_a($validator->getValidator(), RequiredPropertyValidator::class);
            });
        }
    }
}
