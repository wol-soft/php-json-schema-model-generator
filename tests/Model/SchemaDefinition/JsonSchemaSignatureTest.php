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

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function classChangingKeywordProvider(): array
    {
        $object = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]];

        return [
            // The class validates it itself.
            'unevaluatedProperties: false' => [$object, $object + ['unevaluatedProperties' => false]],
            'unevaluatedProperties: schema against false' => [
                $object + ['unevaluatedProperties' => ['type' => 'string']],
                $object + ['unevaluatedProperties' => false],
            ],
            'the schema of unevaluatedProperties' => [
                $object + ['unevaluatedProperties' => ['type' => 'string']],
                $object + ['unevaluatedProperties' => ['type' => 'integer']],
            ],
            // The post processor attaches the evaluation trait to the class when it finds the keyword.
            'unevaluatedItems: false' => [$object, $object + ['unevaluatedItems' => false]],
        ];
    }

    /**
     * A keyword which makes the generated class differ must make the signature differ: schemas which are mapped to one
     * class by their signature but differ in it give the second schema the first's class, so it silently loses or
     * gains the keyword.
     *
     * @param array<string, mixed> $first
     * @param array<string, mixed> $second
     */
    #[DataProvider('classChangingKeywordProvider')]
    public function testKeywordsWhichChangeTheGeneratedClassChangeTheSignature(array $first, array $second): void
    {
        $this->assertNotSame(
            (new JsonSchema('', $first))->getSignature(),
            (new JsonSchema('', $second))->getSignature(),
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function classNeutralKeywordProvider(): array
    {
        $object = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]];

        return [
            // Documentation only; `title` names the class of the first schema, which is the purpose of mapping them.
            'title' => [$object, $object + ['title' => 'Some title']],
            'description' => [$object, $object + ['description' => 'Some description']],
            // The validators sit on the property in the enclosing class, not in the class of the nested schema.
            'enum' => [$object, $object + ['enum' => [['a' => 'x']]]],
            'const' => [$object, $object + ['const' => ['a' => 'x']]],
            'default' => [$object, $object + ['default' => ['a' => 'x']]],
            // Array keywords have no effect on a class whose instances are always objects.
            'items' => [$object, $object + ['items' => ['type' => 'string']]],
            'prefixItems' => [$object, $object + ['prefixItems' => [['type' => 'string']]]],
            'additionalItems' => [$object, $object + ['additionalItems' => false]],
            'contains' => [$object, $object + ['contains' => ['type' => 'string']]],
            // Not implemented yet: whoever implements them adds them to the signature fields.
            'dependentRequired' => [$object, $object + ['dependentRequired' => ['a' => ['b']]]],
            'dependentSchemas' => [$object, $object + ['dependentSchemas' => ['a' => ['required' => ['b']]]]],
        ];
    }

    /**
     * Pins the result of the keyword audit documented on the signature fields: generating the nested class of a schema
     * with and without each of these keywords produced identical sources. Deduplicating them keeps one class where
     * adding them to the fields would produce several identical ones. A keyword which starts changing the class has
     * to move from here to classChangingKeywordProvider together with its implementation.
     *
     * @param array<string, mixed> $first
     * @param array<string, mixed> $second
     */
    #[DataProvider('classNeutralKeywordProvider')]
    public function testKeywordsWhichLeaveTheGeneratedClassUnchangedDoNotChangeTheSignature(
        array $first,
        array $second,
    ): void {
        $this->assertSame(
            (new JsonSchema('', $first))->getSignature(),
            (new JsonSchema('', $second))->getSignature(),
        );
    }
}
