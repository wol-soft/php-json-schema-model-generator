<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\Filter;

use PHPModelGenerator\Filter\MediaString as MediaStringCallable;
use PHPModelGenerator\Filter\TransformingFilterInterface;

class MediaStringFilter implements TransformingFilterInterface
{
    public function getToken(): string
    {
        return 'mediaString';
    }

    public function getFilter(): array
    {
        return [MediaStringCallable::class, 'filter'];
    }

    public function getSerializer(): array
    {
        return [MediaStringCallable::class, 'serialize'];
    }
}
