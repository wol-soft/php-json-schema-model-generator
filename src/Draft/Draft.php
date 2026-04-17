<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft;

use PHPModelGenerator\Draft\Element\Type;
use PHPModelGenerator\Exception\SchemaException;

final class Draft
{
    /**
     * @param Type[] $types
     */
    public function __construct(private readonly array $types)
    {
    }

    /**
     * @return Type[]
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    public function hasType(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /**
     * Returns the Type entries whose modifiers apply to a property of the given type(s).
     * The special type 'any' always applies to every property; passing 'any' returns all types.
     *
     * @param string|string[] $type
     *
     * @return Type[]
     *
     * @throws SchemaException
     */
    public function getCoveredTypes(string | array $type): array
    {
        if (!is_array($type)) {
            $type = [$type];
        }

        if (in_array('any', $type, true)) {
            return $this->types;
        }

        // 'any' modifiers always apply regardless of the concrete type
        $type[] = 'any';

        $unknownTypes = array_diff($type, array_keys($this->types));
        if ($unknownTypes) {
            throw new SchemaException(sprintf(
                'Unsupported property type %s',
                count($unknownTypes) === 1
                    ? reset($unknownTypes)
                    : '[' . implode(',', $unknownTypes) . ']',
            ));
        }

        return array_intersect_key($this->types, array_fill_keys($type, null));
    }
}
