<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Exception;

use Throwable;

/**
 * Class ErrorRegistryExceptionInterface
 *
 * @package PHPModelGenerator\Exception
 */
interface ErrorRegistryExceptionInterface extends Throwable
{
    public function addError(string $message): self;

    public function getErrors(): array;
}
