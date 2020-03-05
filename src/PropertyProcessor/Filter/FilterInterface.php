<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Filter;

interface FilterInterface
{
    /**
     * Return a list of accepted data types for the filter (eg. ['string', 'int']). If the filter is applied to a
     * value which doesn't match an accepted type an exception will be thrown. If an empty array is returned the filter
     * will accept all types.
     *
     * @return array
     */
    public function getAcceptedTypes(): array;

    /**
     * Return the token for the filter
     *
     * @return string
     */
    public function getToken(): string;

    /**
     * Return the filter to apply. Make sure the returned array is a callable which is also callable after the
     * render process
     *
     * @return array
     */
    public function getFilter(): array;
}
