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
class DateTimeFilter implements TransformingFilterInterface
{
    /**
     * @inheritDoc
     */
    public function getAcceptedTypes(): array
    {
        return ['string'];
    }

    /**
     * @inheritDoc
     */
    public function getToken(): string
    {
        return 'dateTime';
    }

    /**
     * @inheritDoc
     */
    public function getFilter(): array
    {
        return [DateTime::class, 'filter'];
    }

    /**
     * @inheritDoc
     */
    public function getSerializer(): array
    {
        return [DateTime::class, 'serialize'];
    }
}
