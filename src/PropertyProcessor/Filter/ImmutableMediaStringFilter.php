<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\Filter;

use PHPModelGenerator\Filter\ImmutableMediaString as ImmutableMediaStringCallable;
use PHPModelGenerator\Filter\TransformingFilterInterface;

class ImmutableMediaStringFilter implements TransformingFilterInterface
{
    public function getToken(): string
    {
        return 'immutableMediaString';
    }

    public function getFilter(): array
    {
        return [ImmutableMediaStringCallable::class, 'filter'];
    }

    public function getSerializer(): array
    {
        return [ImmutableMediaStringCallable::class, 'serialize'];
    }
}
