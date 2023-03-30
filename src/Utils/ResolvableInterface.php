<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

interface ResolvableInterface
{
    /**
     * Adds a callback which will be executed after the property is set up completely
     */
    public function onResolve(callable $callback): void;

    /**
     * Check if the property set up is finished
     */
    public function isResolved(): bool;
}
