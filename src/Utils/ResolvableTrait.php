<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

trait ResolvableTrait
{
    /** @var bool */
    protected $isResolved = false;
    /** @var callable[] */
    protected $onResolveCallbacks = [];

    public function onResolve(callable $callback): void
    {
        $this->isResolved
            ? $callback()
            : $this->onResolveCallbacks[] = $callback;
    }

    public function isResolved(): bool
    {
        return $this->isResolved;
    }

    public function resolve(): void
    {
        $this->isResolved = true;

        foreach ($this->onResolveCallbacks as $callback) {
            $callback();
        }

        $this->onResolveCallbacks = [];
    }
}
