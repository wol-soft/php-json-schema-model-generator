<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

/**
 * Interface PropertyValidatorInterface
 *
 * @package PHPModelGenerator\Model\Validator
 */
interface PropertyValidatorInterface
{
    /**
     * Get the source code for the check to perform
     *
     * @return string
     */
    public function getCheck(): string;

    /**
     * Get the message of the exception which is thrown if the validation fails
     *
     * @return string
     */
    public function getExceptionMessage(): string;
}
