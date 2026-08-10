<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft;

use PHPModelGenerator\Draft\Element\Type;
use PHPModelGenerator\Draft\Producer\PropertyProducerInterface;
use PHPModelGenerator\Exception\SchemaException;

final class Draft
{
    /**
     * @param Type[]                      $types
     * @param PropertyProducerInterface[] $producers Keyed by keyword, in registry order
     */
    public function __construct(
        private readonly array $types,
        private readonly array $producers = [],
    ) {
    }

    public function getProducerForKeyword(string $keyword): ?PropertyProducerInterface
    {
        return $this->producers[$keyword] ?? null;
    }

    /**
     * Returns every registered producer whose keyword is present in the given schema node, keyed
     * by keyword and in registry order. With only the $ref producer registered today this yields
     * 0 or 1 element, but the contract is N-producer capable for future co-occurring reference
     * keywords. Keeping the keyword as the key lets callers name the offending keyword(s) when
     * reporting a conflict (e.g. multiple mutually exclusive producers present on one node).
     *
     * @param array<string, mixed> $json
     *
     * @return array<string, PropertyProducerInterface>
     */
    public function getProducersForSchema(array $json): array
    {
        $producers = [];

        foreach ($this->producers as $keyword => $producer) {
            if (array_key_exists($keyword, $json)) {
                $producers[$keyword] = $producer;
            }
        }

        return $producers;
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
     * Returns the JSON Schema type names (e.g. 'string', 'integer', 'object') whose registered
     * modifiers or validator-factories carry the given schema keyword.
     *
     * @return string[]
     */
    public function getTypesForKeyword(string $keyword): array
    {
        $typeNames = [];

        foreach ($this->types as $typeName => $type) {
            if (array_key_exists($keyword, $type->getModifiers())) {
                $typeNames[] = $typeName;
            }
        }

        return $typeNames;
    }

    /**
     * Returns the schema keywords registered as validator factories for the given type (e.g.
     * 'properties', 'required', 'additionalProperties', … for 'object'). Modifiers added via
     * addModifier() (not keyed by keyword) are excluded, so this only surfaces keywords that
     * actually drive validation for the type.
     *
     * @return string[]
     */
    public function getKeywordsForType(string $type): array
    {
        return array_values(array_filter(array_keys($this->types[$type]->getModifiers()), 'is_string'));
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
