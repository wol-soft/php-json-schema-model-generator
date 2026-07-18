<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\PropertyProcessor;

use PHPModelGenerator\PropertyProcessor\ObjectShape\ObjectShape;
use PHPModelGenerator\PropertyProcessor\ObjectShape\ObjectShapeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ObjectShapeResolverTest extends TestCase
{
    private const array PERSON_OBJECT = [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
        ],
        'required' => ['name'],
    ];

    private const array BARE_VALIDATORS = [
        'properties' => [
            'name' => ['type' => 'string'],
        ],
        'required' => ['name'],
    ];

    #[DataProvider('objectShapeDataProvider')]
    public function testResolve(array|bool $json, ObjectShape $expectedShape): void
    {
        $this->assertSame($expectedShape, (new ObjectShapeResolver())->resolve($json));
    }

    public static function objectShapeDataProvider(): array
    {
        return [
            // Explicit type declarations
            'explicit object type' => [['type' => 'object'], ObjectShape::ObjectAsserting],
            'explicit object with properties' => [self::PERSON_OBJECT, ObjectShape::ObjectAsserting],
            'scalar type' => [['type' => 'string'], ObjectShape::NotObject],
            'scalar type with object keywords' => [
                ['type' => 'string', 'properties' => ['name' => ['type' => 'string']]],
                ObjectShape::NotObject,
            ],
            'multi-type including object' => [['type' => ['object', 'string']], ObjectShape::NotObject],

            // Bare object validators (describing)
            'bare object validators' => [self::BARE_VALIDATORS, ObjectShape::ObjectDescribing],
            'minProperties only' => [['minProperties' => 1], ObjectShape::ObjectDescribing],
            'additionalProperties only' => [['additionalProperties' => false], ObjectShape::ObjectDescribing],

            // Vacuous / neutral schemas
            'empty schema' => [[], ObjectShape::NotObject],
            'boolean true schema' => [true, ObjectShape::NotObject],
            'boolean false schema' => [false, ObjectShape::NotObject],
            'annotations only' => [['title' => 'x', 'example' => ['name' => 'y']], ObjectShape::NotObject],
            'scalar validators without type' => [['minLength' => 5], ObjectShape::NotObject],
            'not only' => [['not' => self::PERSON_OBJECT], ObjectShape::NotObject],
            'if-then-else only' => [
                [
                    'if' => ['required' => ['a']],
                    'then' => self::PERSON_OBJECT,
                    'else' => self::PERSON_OBJECT,
                ],
                ObjectShape::NotObject,
            ],

            // Filter-bearing schemas stay on the filter machinery
            'filter without type' => [
                ['filter' => 'dateTime', 'allOf' => [['type' => 'object']]],
                ObjectShape::NotObject,
            ],

            // allOf (conjunctive) aggregation
            'allOf of explicit objects' => [
                ['allOf' => [self::PERSON_OBJECT, ['type' => 'object']]],
                ObjectShape::ObjectAsserting,
            ],
            'allOf asserting plus describing' => [
                ['allOf' => [self::PERSON_OBJECT, self::BARE_VALIDATORS]],
                ObjectShape::ObjectAsserting,
            ],
            'allOf asserting plus scalar' => [
                ['allOf' => [self::PERSON_OBJECT, ['type' => 'string']]],
                ObjectShape::NotObject,
            ],
            'allOf asserting plus scalar composition' => [
                ['allOf' => [self::PERSON_OBJECT, ['anyOf' => [['type' => 'string'], ['type' => 'integer']]]]],
                ObjectShape::NotObject,
            ],
            'allOf of only describing branches' => [
                ['allOf' => [self::BARE_VALIDATORS, ['required' => ['other']]]],
                ObjectShape::ObjectDescribing,
            ],
            'allOf asserting plus true branch' => [
                ['allOf' => [self::PERSON_OBJECT, true]],
                ObjectShape::ObjectAsserting,
            ],
            'allOf asserting plus false branch' => [
                ['allOf' => [self::PERSON_OBJECT, false]],
                ObjectShape::NotObject,
            ],
            'allOf asserting plus annotation-only branch' => [
                ['allOf' => [self::PERSON_OBJECT, ['example' => ['name' => 'x']]]],
                ObjectShape::ObjectAsserting,
            ],
            'nested allOf chain' => [
                ['allOf' => [['allOf' => [self::PERSON_OBJECT]], ['type' => 'object']]],
                ObjectShape::ObjectAsserting,
            ],

            // anyOf / oneOf (disjunctive) aggregation
            'anyOf of asserting branches' => [
                ['anyOf' => [self::PERSON_OBJECT, ['type' => 'object']]],
                ObjectShape::ObjectAsserting,
            ],
            'anyOf asserting plus describing' => [
                ['anyOf' => [self::PERSON_OBJECT, self::BARE_VALIDATORS]],
                ObjectShape::ObjectDescribing,
            ],
            'anyOf asserting plus scalar' => [
                ['anyOf' => [self::PERSON_OBJECT, ['type' => 'string']]],
                ObjectShape::NotObject,
            ],
            'anyOf asserting plus vacuous branch' => [
                ['anyOf' => [self::PERSON_OBJECT, []]],
                ObjectShape::NotObject,
            ],
            'oneOf of asserting branches' => [
                ['oneOf' => [self::PERSON_OBJECT, ['type' => 'object']]],
                ObjectShape::ObjectAsserting,
            ],
            'oneOf of describing branches' => [
                ['oneOf' => [self::BARE_VALIDATORS, ['required' => ['other']]]],
                ObjectShape::ObjectDescribing,
            ],

            // Combined components on one schema object
            'describing keywords next to asserting allOf' => [
                ['properties' => ['a' => ['type' => 'string']], 'allOf' => [self::PERSON_OBJECT]],
                ObjectShape::ObjectAsserting,
            ],
            'allOf asserting next to scalar anyOf' => [
                ['allOf' => [self::PERSON_OBJECT], 'anyOf' => [['type' => 'string']]],
                ObjectShape::NotObject,
            ],

            // $ref without a resolver
            'reference without resolver' => [['$ref' => '#/definitions/person'], ObjectShape::NotObject],
        ];
    }

    #[DataProvider('referenceShapeDataProvider')]
    public function testResolveWithReferences(
        array $definitions,
        array|bool $json,
        ObjectShape $expectedShape,
    ): void {
        $resolver = new ObjectShapeResolver(
            static fn(string $reference): array|bool|null => $definitions[$reference] ?? null,
        );

        $this->assertSame($expectedShape, $resolver->resolve($json));
    }

    public static function referenceShapeDataProvider(): array
    {
        return [
            'reference to explicit object' => [
                ['#/definitions/person' => self::PERSON_OBJECT],
                ['$ref' => '#/definitions/person'],
                ObjectShape::ObjectAsserting,
            ],
            'reference to bare validators' => [
                ['#/definitions/bare' => self::BARE_VALIDATORS],
                ['$ref' => '#/definitions/bare'],
                ObjectShape::ObjectDescribing,
            ],
            'reference chain through composition-only definitions' => [
                [
                    '#/definitions/identification' => ['allOf' => [self::PERSON_OBJECT]],
                    '#/definitions/basic' => [
                        'allOf' => [
                            ['$ref' => '#/definitions/identification'],
                            ['type' => 'object'],
                        ],
                    ],
                ],
                ['allOf' => [['$ref' => '#/definitions/basic']]],
                ObjectShape::ObjectAsserting,
            ],
            'unresolvable reference' => [
                [],
                ['$ref' => '#/definitions/missing'],
                ObjectShape::NotObject,
            ],
            'unresolvable reference blocks sibling assertion' => [
                [],
                ['allOf' => [self::PERSON_OBJECT, ['$ref' => '#/definitions/missing']]],
                ObjectShape::NotObject,
            ],
            'cyclic reference' => [
                [
                    '#/definitions/a' => ['allOf' => [['$ref' => '#/definitions/b']]],
                    '#/definitions/b' => ['allOf' => [['$ref' => '#/definitions/a']]],
                ],
                ['$ref' => '#/definitions/a'],
                ObjectShape::NotObject,
            ],
            'reference with asserting sibling keywords' => [
                ['#/definitions/bare' => self::BARE_VALIDATORS],
                ['$ref' => '#/definitions/bare', 'type' => 'object'],
                ObjectShape::ObjectAsserting,
            ],
            'reference with describing sibling keywords' => [
                ['#/definitions/person' => self::PERSON_OBJECT],
                ['$ref' => '#/definitions/person', 'required' => ['other']],
                ObjectShape::ObjectAsserting,
            ],
            'reference to scalar with describing siblings' => [
                ['#/definitions/name' => ['type' => 'string']],
                ['$ref' => '#/definitions/name', 'required' => ['other']],
                ObjectShape::NotObject,
            ],
        ];
    }
}
