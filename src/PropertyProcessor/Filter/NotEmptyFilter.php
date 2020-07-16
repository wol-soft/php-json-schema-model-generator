<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Filter;

use PHPModelGenerator\Filter\FilterInterface;
use PHPModelGenerator\Filter\NotEmpty;

/**
 * Class NotEmptyFilter
 *
 * Removes empty elements from an array
 *
 * @package PHPModelGenerator\PropertyProcessor\Filter
 */
class NotEmptyFilter implements FilterInterface
{
    /**
     * @inheritDoc
     */
    public function getAcceptedTypes(): array
    {
        return ['array', 'null'];
    }

    /**
     * @inheritDoc
     */
    public function getToken(): string
    {
        return 'notEmpty';
    }

    /**
     * @inheritDoc
     */
    public function getFilter(): array
    {
        return [NotEmpty::class, 'filter'];
    }
}
