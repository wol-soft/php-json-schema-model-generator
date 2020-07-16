<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Filter;

use PHPModelGenerator\Filter\FilterInterface;
use PHPModelGenerator\Filter\Trim;

/**
 * Class TrimFilter
 *
 * Trims a string
 *
 * @package PHPModelGenerator\PropertyProcessor\Filter
 */
class TrimFilter implements FilterInterface
{
    /**
     * @inheritDoc
     */
    public function getAcceptedTypes(): array
    {
        return ['string', 'null'];
    }

    /**
     * @inheritDoc
     */
    public function getToken(): string
    {
        return 'trim';
    }

    /**
     * @inheritDoc
     */
    public function getFilter(): array
    {
        return [Trim::class, 'filter'];
    }
}
