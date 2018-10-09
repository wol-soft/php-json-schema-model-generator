<?php

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Model\Property;

/**
 * Class RenderHelper
 *
 * @package PHPModelGenerator\Utils
 */
class RenderHelper
{
    /**
     * @param string $value
     *
     * @return string
     */
    public function ucfirst(string $value): string
    {
        return ucfirst($value);
    }

    /**
     * @param string $fqcn
     *
     * @return string
     */
    public function getSimpleClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }

    /**
     * Resolve all associated decorators of a property
     *
     * @param Property $property
     *
     * @return string
     */
    public function resolvePropertyDecorator(Property $property): string
    {
        return $property->hasDecorators()
            ? 'if ($value !== null) { $value = ' . $property->resolveDecorator('$value') . '; }'
            : '';
    }
}