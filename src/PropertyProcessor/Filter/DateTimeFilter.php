<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Filter;

use PHPModelGenerator\Filter\DateTime;

/**
 * Class DateTimeFilter
 *
 * Trims a string
 *
 * @package PHPModelGenerator\PropertyProcessor\Filter
 */
class DateTimeFilter implements FilterInterface
{
    /**
     * Return a list of accepted data types for the filter (eg. ['string', 'int']). If the filter is applied to a
     * value which doesn't match an accepted type an exception will be thrown
     *
     * @return array
     */
    public function getAcceptedTypes(): array
    {
        return ['string'];
    }

    /**
     * Return the token for the filter
     *
     * @return string
     */
    public function getToken(): string
    {
        return 'dateTime';
    }

    /**
     * Return the filter to apply. Make sure the returned array is a callable which is also callable after the
     * render process
     *
     * @return array
     */
    public function getFilter(): array
    {
        return [DateTime::class, 'filter'];
    }
}
