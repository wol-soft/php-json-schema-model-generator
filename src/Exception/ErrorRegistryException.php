<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Exception;

use Exception;

/**
 * Class ErrorRegistryException
 *
 * @package PHPModelGenerator\Exception
 */
class ErrorRegistryException extends Exception implements ErrorRegistryExceptionInterface
{
    protected $errors = [];

    public function addError(string $message): ErrorRegistryExceptionInterface
    {
        $this->errors[] = $message;
        return $this;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
