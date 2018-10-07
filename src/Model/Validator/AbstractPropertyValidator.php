<?php

namespace PHPModelGenerator\Model\Validator;

/**
 * Class AbstractPropertyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
abstract class AbstractPropertyValidator
{
    /** @var string */
    protected $exceptionClass;
    /** @var string */
    protected $exceptionMessage;

    /**
     * Get the exception class to be thrown if the validation fails
     *
     * @return string
     */
    public function getExceptionClass(): string
    {
        return $this->exceptionClass;
    }

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
