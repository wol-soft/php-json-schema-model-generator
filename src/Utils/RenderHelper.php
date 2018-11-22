<?php

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Model\Property\PropertyInterface;

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
     * Escape all single quotes in the given $input string
     *
     * @param string $input
     *
     * @return string
     */
    public function escapeSingleQuotes(string $input): string
    {
        return addcslashes($input, "'");
    }

    /**
     * Resolve all associated decorators of a property
     *
     * @param PropertyInterface $property
     *
     * @return string
     */
    public function resolvePropertyDecorator(PropertyInterface $property): string
    {
        if (!$property->hasDecorators()) {
            return '';
        }

        return $property->isRequired()
            ? '$value = ' . $property->resolveDecorator('$value') . ';'
            : 'if ($value !== null) { $value = ' . $property->resolveDecorator('$value') . '; }';
    }
}
