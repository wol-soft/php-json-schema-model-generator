<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Model\SchemaDefinition;

use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Identical schemas are mapped to one class by their signature. The order of a list only counts where it changes the
 * meaning or the generated code: a list which is a set (type, required, enum, the dependants of a property
 * dependency) is compared order-insensitive to deduplicate as much as possible, every other list keeps its order.
 */
class JsonSchemaSignatureTest extends TestCase
{
    private static function property(array $propertySchema): array
    {
        return ['type' => 'object', 'properties' => ['value' => $propertySchema]];
    }

    public static function sameSignatureProvider(): array
    {
        return [
            'order of the properties' => [
                ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']]],
                ['type' => 'object', 'properties' => ['b' => ['type' => 'integer'], 'a' => ['type' => 'string']]],
            ],
            'order of required' => [
                ['type' => 'object', 'required' => ['a', 'b']],
                ['type' => 'object', 'required' => ['b', 'a']],
            ],
            'order of a type list' => [
                self::property(['type' => ['string', 'integer']]),
                self::property(['type' => ['integer', 'string']]),
            ],
            'order of enum values' => [
                self::property(['enum' => ['a', 'b']]),
                self::property(['enum' => ['b', 'a']]),
            ],
            'order of the dependants of a property dependency' => [
                ['type' => 'object', 'dependencies' => ['a' => ['b', 'c']]],
                ['type' => 'object', 'dependencies' => ['a' => ['c', 'b']]],
            ],
            'order of the keys of a default object' => [
                self::property(['default' => ['a' => 1, 'b' => 2]]),
                self::property(['default' => ['b' => 2, 'a' => 1]]),
            ],
        ];
    }

    #[DataProvider('sameSignatureProvider')]
    public function testListsWhichAreSetsDoNotChangeTheSignature(array $first, array $second): void
    {
        $this->assertSame(
            (new JsonSchema('', $first))->getSignature(),
            (new JsonSchema('', $second))->getSignature(),
        );
    }

    public static function differentSignatureProvider(): array
    {
        return [
            // The filters are executed in the declared order.
            'order of the filters' => [
                self::property(['type' => 'string', 'filter' => ['trim', 'upper']]),
                self::property(['type' => 'string', 'filter' => ['upper', 'trim']]),
            ],
            'order of the items of a tuple' => [
                self::property(['type' => 'array', 'items' => [['type' => 'string'], ['type' => 'integer']]]),
                self::property(['type' => 'array', 'items' => [['type' => 'integer'], ['type' => 'string']]]),
            ],
            'order of prefixItems' => [
                self::property(['type' => 'array', 'prefixItems' => [['type' => 'string'], ['type' => 'integer']]]),
                self::property(['type' => 'array', 'prefixItems' => [['type' => 'integer'], ['type' => 'string']]]),
            ],
            // The branch order is part of the error messages and decides the precedence of merged properties.
            'order of composition branches' => [
                ['type' => 'object', 'allOf' => [['required' => ['a']], ['required' => ['b']]]],
                ['type' => 'object', 'allOf' => [['required' => ['b']], ['required' => ['a']]]],
            ],
            'order of a const list' => [
                self::property(['const' => [1, 2]]),
                self::property(['const' => [2, 1]]),
            ],
            'order of a default list' => [
                self::property(['default' => [1, 2]]),
                self::property(['default' => [2, 1]]),
            ],
            // The enum is a set of values, but a value which is a list keeps its order.
            'order inside an enum value' => [
                self::property(['enum' => [[1, 2]]]),
                self::property(['enum' => [[2, 1]]]),
            ],
            // A property named like a keyword is no keyword.
            'order inside a default object with a key named like a keyword' => [
                self::property(['default' => ['required' => [1, 2]]]),
                self::property(['default' => ['required' => [2, 1]]]),
            ],
        ];
    }

    #[DataProvider('differentSignatureProvider')]
    public function testListsWhoseOrderMattersChangeTheSignature(array $first, array $second): void
    {
        $this->assertNotSame(
            (new JsonSchema('', $first))->getSignature(),
            (new JsonSchema('', $second))->getSignature(),
        );
    }
}
