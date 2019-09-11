<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

/**
 * Class AbstractPropertyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
abstract class AbstractPropertyValidator implements PropertyValidatorInterface
{
    /** @var string */
    protected $exceptionMessage;

    /**
     * Get the message of the exception which is thrown if the validation fails
     *
     * @return string
     */
    public function getExceptionMessage(): string
    {
        return $this->exceptionMessage;
    }
}
