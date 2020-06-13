<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Filter;

interface TransformingFilterInterface extends FilterInterface
{
    /**
     * Return the serializer to apply to transformed values.
     * Make sure the returned array is a callable which is also callable after the render process
     *
     * @return array
     */
    public function getSerializer(): array;
}
