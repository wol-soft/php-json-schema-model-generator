<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPModelGenerator\Utils\Json\JsonPointerLocator;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A property which is required although the object denies it can't be satisfied by any instance; a declared property
 * which the object denies can never be provided, its generated accessors are dead. Both are rejected at generation
 * time.
 *
 * A name is denied by every schema which applies unconditionally to the object: the root schema, every allOf branch
 * (nested ones included) and the target of a base-level $ref - by an explicit "additionalProperties": false (unless the
 * schema declares the name in its own properties or matches it by its own patternProperties), by the
 * denyAdditionalProperties option (which also applies to composition branches), by an additionalProperties schema
 * whose type conflicts with the declared type of the property, or by a propertyNames schema rejecting the name. An
 * anyOf/oneOf composition denies a name only if every branch denies it; if/then/else and schema dependencies apply
 * conditionally and never deny a name for every instance.
 */
#[ApplicableDrafts]
class ContradictoryPropertyConstraintsTest extends AbstractPHPModelGeneratorTestCase
{
    public static function contradictoryPropertyConstraintsProvider(): array
    {
        return [
            // required properties which are denied: no instance satisfies the schema

            'required name denied by additionalProperties false' => [
                [
                    'type' => 'object',
                    'required' => ['foo'],
                    'additionalProperties' => false,
                ],
                false,
                '/required/0',
                "Property 'foo' is required but denied as an additional property by the schema at '#'",
            ],
            'required name denied by the denyAdditionalProperties option' => [
                [
                    'type' => 'object',
                    'required' => ['foo'],
                ],
                true,
                '/required/0',
                "Property 'foo' is required but denied as an additional property by the schema at '#'"
                    . ' (denyAdditionalProperties option)',
            ],
            'name declared and required by an allOf branch, denied by the root' => [
                [
                    'type' => 'object',
                    'allOf' => [
                        [
                            'properties' => ['foo' => ['type' => 'string']],
                            'required' => ['foo'],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                false,
                '/allOf/0/required/0',
                "Property 'foo' is required but denied as an additional property by the schema at '#'",
            ],
            'required root property denied by an allOf branch' => [
                [
                    'type' => 'object',
                    'properties' => ['foo' => ['type' => 'string']],
                    'required' => ['foo'],
                    'allOf' => [
                        [
                            'properties' => ['bar' => ['type' => 'string']],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                false,
                '/required/0',
                "Property 'foo' is required but denied as an additional property by the schema at '#/allOf/0'",
            ],
            'name required by an allOf branch which denies it by the denyAdditionalProperties option' => [
                [
                    'type' => 'object',
                    'properties' => ['foo' => ['type' => 'string']],
                    'allOf' => [
                        [
                            'required' => ['foo'],
                        ],
                    ],
                ],
                true,
                '/allOf/0/required/0',
                "Property 'foo' is required but denied as an additional property by the schema at '#/allOf/0'"
                    . ' (denyAdditionalProperties option)',
            ],
            'required root property denied by a nested allOf branch' => [
                [
                    'type' => 'object',
                    'properties' => ['foo' => ['type' => 'string']],
                    'required' => ['foo'],
                    'allOf' => [
                        [
                            'allOf' => [
                                [
                                    'properties' => ['bar' => ['type' => 'string']],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                    ],
                ],
                false,
                '/required/0',
                "Property 'foo' is required but denied as an additional property by the schema at"
                    . " '#/allOf/0/allOf/0'",
            ],
            // a name required by every anyOf branch is required for every instance
            'name required by every anyOf branch, denied by the root' => [
                [
                    'type' => 'object',
                    'anyOf' => [
                        [
                            'properties' => ['foo' => ['type' => 'string']],
                            'required' => ['foo'],
                        ],
                        [
                            'required' => ['foo'],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                false,
                '/anyOf/0/required/0',
                "Property 'foo' is required but denied as an additional property by the schema at '#'",
            ],
            'required root property denied by every anyOf branch' => [
                [
                    'type' => 'object',
                    'properties' => ['foo' => ['type' => 'string']],
                    'required' => ['foo'],
                    'anyOf' => [
                        [
                            'properties' => ['bar' => ['type' => 'string']],
                            'additionalProperties' => false,
                        ],
                        [
                            'properties' => ['baz' => ['type' => 'string']],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                false,
                '/required/0',
                "Property 'foo' is required but denied as an additional property by every branch of the composition"
                    . " at '#/anyOf'",
            ],
            'required name whose declared type conflicts with the additionalProperties type' => [
                [
                    'type' => 'object',
                    'required' => ['foo'],
                    'allOf' => [
                        [
                            'properties' => ['foo' => ['type' => 'string']],
                        ],
                    ],
                    'additionalProperties' => ['type' => 'integer'],
                ],
                false,
                '/required/0',
                "Property 'foo' is required but its type 'string' conflicts with the additionalProperties type 'int'"
                    . " of the schema at '#'",
            ],
            'required property whose property dependency is denied' => [
                [
                    'type' => 'object',
                    'properties' => ['a' => ['type' => 'string']],
                    'required' => ['a'],
                    'dependencies' => ['a' => ['foo']],
                    'additionalProperties' => false,
                ],
                false,
                '/required/0',
                "Property 'a' is required but its dependency 'foo' is denied as an additional property by the schema"
                    . " at '#'",
            ],

            // declared properties which are denied: the property can never be provided

            'name declared by an allOf branch, denied by the root' => [
                [
                    'type' => 'object',
                    'allOf' => [
                        [
                            'properties' => ['foo' => ['type' => 'string']],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                false,
                '/allOf/0/properties/foo',
                "Property 'foo' declared at '#/allOf/0/properties/foo' can never be provided: it is denied as an"
                    . " additional property by the schema at '#'",
            ],
            'name declared by an anyOf branch, denied by the root' => [
                [
                    'type' => 'object',
                    'anyOf' => [
                        [
                            'properties' => ['foo' => ['type' => 'string']],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                false,
                '/anyOf/0/properties/foo',
                "Property 'foo' declared at '#/anyOf/0/properties/foo' can never be provided: it is denied as an"
                    . " additional property by the schema at '#'",
            ],
            'name declared by a oneOf branch, denied by the root' => [
                [
                    'type' => 'object',
                    'oneOf' => [
                        [
                            'properties' => ['foo' => ['type' => 'string']],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                false,
                '/oneOf/0/properties/foo',
                "Property 'foo' declared at '#/oneOf/0/properties/foo' can never be provided: it is denied as an"
                    . " additional property by the schema at '#'",
            ],
            'name declared by a then branch, denied by the root' => [
                [
                    'type' => 'object',
                    'properties' => ['kind' => ['type' => 'string']],
                    'if' => ['required' => ['kind']],
                    'then' => [
                        'properties' => ['foo' => ['type' => 'string']],
                    ],
                    'additionalProperties' => false,
                ],
                false,
                '/then/properties/foo',
                "Property 'foo' declared at '#/then/properties/foo' can never be provided: it is denied as an"
                    . " additional property by the schema at '#'",
            ],
            'name declared by an else branch, denied by the root' => [
                [
                    'type' => 'object',
                    'properties' => ['kind' => ['type' => 'string']],
                    'if' => ['required' => ['kind']],
                    'else' => [
                        'properties' => ['foo' => ['type' => 'string']],
                    ],
                    'additionalProperties' => false,
                ],
                false,
                '/else/properties/foo',
                "Property 'foo' declared at '#/else/properties/foo' can never be provided: it is denied as an"
                    . " additional property by the schema at '#'",
            ],
            'name declared by a schema dependency, denied by the root' => [
                [
                    'type' => 'object',
                    'properties' => ['a' => ['type' => 'string']],
                    'dependencies' => [
                        'a' => [
                            'properties' => ['foo' => ['type' => 'string']],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                false,
                '/dependencies/a/properties/foo',
                "Property 'foo' declared at '#/dependencies/a/properties/foo' can never be provided: it is denied as"
                    . " an additional property by the schema at '#'",
            ],
            'name declared by an allOf branch, denied by the denyAdditionalProperties option of the root' => [
                [
                    'type' => 'object',
                    'allOf' => [
                        [
                            'properties' => ['foo' => ['type' => 'string']],
                        ],
                    ],
                ],
                true,
                '/allOf/0/properties/foo',
                "Property 'foo' declared at '#/allOf/0/properties/foo' can never be provided: it is denied as an"
                    . " additional property by the schema at '#' (denyAdditionalProperties option)",
            ],
            'root property denied by an allOf branch' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'a' => ['type' => 'string'],
                        'b' => ['type' => 'string'],
                    ],
                    'allOf' => [
                        [
                            'properties' => ['a' => ['type' => 'string']],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                false,
                '/properties/b',
                "Property 'b' declared at '#/properties/b' can never be provided: it is denied as an additional"
                    . " property by the schema at '#/allOf/0'",
            ],
            'root property denied by the denyAdditionalProperties option of an allOf branch' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'a' => ['type' => 'string'],
                        'b' => ['type' => 'string'],
                    ],
                    'allOf' => [
                        [
                            'properties' => ['a' => ['type' => 'string']],
                        ],
                    ],
                ],
                true,
                '/properties/b',
                "Property 'b' declared at '#/properties/b' can never be provided: it is denied as an additional"
                    . " property by the schema at '#/allOf/0' (denyAdditionalProperties option)",
            ],
            'root property denied by a nested allOf branch' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'a' => ['type' => 'string'],
                        'b' => ['type' => 'string'],
                    ],
                    'allOf' => [
                        [
                            'allOf' => [
                                [
                                    'properties' => ['a' => ['type' => 'string']],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                    ],
                ],
                false,
                '/properties/b',
                "Property 'b' declared at '#/properties/b' can never be provided: it is denied as an additional"
                    . " property by the schema at '#/allOf/0/allOf/0'",
            ],
            'root property denied by every anyOf branch' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'a' => ['type' => 'string'],
                        'b' => ['type' => 'string'],
                    ],
                    'anyOf' => [
                        [
                            'properties' => ['a' => ['type' => 'string']],
                            'additionalProperties' => false,
                        ],
                        [
                            'properties' => [
                                'a' => ['type' => 'string'],
                                'c' => ['type' => 'string'],
                            ],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                false,
                '/properties/b',
                "Property 'b' declared at '#/properties/b' can never be provided: it is denied as an additional"
                    . " property by every branch of the composition at '#/anyOf'",
            ],
            'root property denied by every oneOf branch' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'a' => ['type' => 'string'],
                        'b' => ['type' => 'string'],
                        'c' => ['type' => 'string'],
                    ],
                    'oneOf' => [
                        [
                            'properties' => ['a' => ['type' => 'string']],
                            'required' => ['a'],
                            'additionalProperties' => false,
                        ],
                        [
                            'properties' => ['c' => ['type' => 'string']],
                            'required' => ['c'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                false,
                '/properties/b',
                "Property 'b' declared at '#/properties/b' can never be provided: it is denied as an additional"
                    . " property by every branch of the composition at '#/oneOf'",
            ],
            'name declared by an allOf branch with a type conflicting with the additionalProperties type' => [
                [
                    'type' => 'object',
                    'allOf' => [
                        [
                            'properties' => ['foo' => ['type' => 'string']],
                        ],
                    ],
                    'additionalProperties' => ['type' => 'integer'],
                ],
                false,
                '/allOf/0/properties/foo',
                "Property 'foo' declared at '#/allOf/0/properties/foo' can never be provided: its type 'string'"
                    . " conflicts with the additionalProperties type 'int' of the schema at '#'",
            ],
            'property whose property dependency is denied' => [
                [
                    'type' => 'object',
                    'properties' => ['a' => ['type' => 'string']],
                    'dependencies' => ['a' => ['foo']],
                    'additionalProperties' => false,
                ],
                false,
                '/properties/a',
                "Property 'a' declared at '#/properties/a' can never be provided: its dependency 'foo' is denied as"
                    . " an additional property by the schema at '#'",
            ],

            // property names rejected by propertyNames; the complete propertyNames schema is evaluated

            'required name rejected by propertyNames false' => [
                [
                    'type' => 'object',
                    'required' => ['foo'],
                    'propertyNames' => false,
                ],
                false,
                '/required/0',
                "Property 'foo' is required but its name is rejected by the propertyNames of the schema at '#'",
            ],
            'required name rejected by a propertyNames pattern' => [
                [
                    'type' => 'object',
                    'required' => ['foo'],
                    'propertyNames' => ['pattern' => '^x'],
                ],
                false,
                '/required/0',
                "Property 'foo' is required but its name is rejected by the propertyNames of the schema at '#'",
            ],
            'name required by an allOf branch, rejected by the propertyNames of another branch' => [
                [
                    'type' => 'object',
                    'allOf' => [
                        [
                            'required' => ['foo'],
                        ],
                        [
                            'propertyNames' => ['maxLength' => 2],
                        ],
                    ],
                ],
                false,
                '/allOf/0/required/0',
                "Property 'foo' is required but its name is rejected by the propertyNames of the schema at"
                    . " '#/allOf/1'",
            ],
            'declared name rejected by propertyNames false' => [
                [
                    'type' => 'object',
                    'properties' => ['foo' => ['type' => 'string']],
                    'propertyNames' => false,
                ],
                false,
                '/properties/foo',
                "Property 'foo' declared at '#/properties/foo' can never be provided: its name is rejected by the"
                    . " propertyNames of the schema at '#'",
            ],
            'declared name rejected by a propertyNames const' => [
                [
                    'type' => 'object',
                    'properties' => ['foo' => ['type' => 'string']],
                    'propertyNames' => ['const' => 'bar'],
                ],
                false,
                '/properties/foo',
                "Property 'foo' declared at '#/properties/foo' can never be provided: its name is rejected by the"
                    . " propertyNames of the schema at '#'",
            ],
            'declared name rejected by a propertyNames enum' => [
                [
                    'type' => 'object',
                    'properties' => ['foo' => ['type' => 'string']],
                    'propertyNames' => ['enum' => ['bar', 'baz']],
                ],
                false,
                '/properties/foo',
                "Property 'foo' declared at '#/properties/foo' can never be provided: its name is rejected by the"
                    . " propertyNames of the schema at '#'",
            ],
            'declared name rejected by a propertyNames maxLength' => [
                [
                    'type' => 'object',
                    'properties' => ['foo' => ['type' => 'string']],
                    'propertyNames' => ['maxLength' => 2],
                ],
                false,
                '/properties/foo',
                "Property 'foo' declared at '#/properties/foo' can never be provided: its name is rejected by the"
                    . " propertyNames of the schema at '#'",
            ],
            'declared name rejected by a propertyNames minLength' => [
                [
                    'type' => 'object',
                    'properties' => ['foo' => ['type' => 'string']],
                    'propertyNames' => ['minLength' => 4],
                ],
                false,
                '/properties/foo',
                "Property 'foo' declared at '#/properties/foo' can never be provided: its name is rejected by the"
                    . " propertyNames of the schema at '#'",
            ],
            'declared name rejected by a propertyNames not' => [
                [
                    'type' => 'object',
                    'properties' => ['foo' => ['type' => 'string']],
                    'propertyNames' => [
                        'not' => ['const' => 'foo'],
                    ],
                ],
                false,
                '/properties/foo',
                "Property 'foo' declared at '#/properties/foo' can never be provided: its name is rejected by the"
                    . " propertyNames of the schema at '#'",
            ],
            'declared name rejected by every branch of a propertyNames anyOf' => [
                [
                    'type' => 'object',
                    'properties' => ['foo' => ['type' => 'string']],
                    'propertyNames' => [
                        'anyOf' => [
                            ['pattern' => '^x'],
                            ['pattern' => '^y'],
                        ],
                    ],
                ],
                false,
                '/properties/foo',
                "Property 'foo' declared at '#/properties/foo' can never be provided: its name is rejected by the"
                    . " propertyNames of the schema at '#'",
            ],
            'declared name rejected by a referenced propertyNames schema' => [
                [
                    'type' => 'object',
                    'definitions' => [
                        'name' => ['pattern' => '^x'],
                    ],
                    'properties' => ['foo' => ['type' => 'string']],
                    'propertyNames' => ['$ref' => '#/definitions/name'],
                ],
                false,
                '/properties/foo',
                "Property 'foo' declared at '#/properties/foo' can never be provided: its name is rejected by the"
                    . " propertyNames of the schema at '#'",
            ],
            'name declared by an allOf branch, rejected by the propertyNames of the root' => [
                [
                    'type' => 'object',
                    'allOf' => [
                        [
                            'properties' => ['foo' => ['type' => 'string']],
                        ],
                    ],
                    'propertyNames' => ['pattern' => '^x'],
                ],
                false,
                '/allOf/0/properties/foo',
                "Property 'foo' declared at '#/allOf/0/properties/foo' can never be provided: its name is rejected"
                    . " by the propertyNames of the schema at '#'",
            ],
            'root property rejected by the propertyNames of an allOf branch' => [
                [
                    'type' => 'object',
                    'properties' => ['foo' => ['type' => 'string']],
                    'allOf' => [
                        [
                            'propertyNames' => ['pattern' => '^x'],
                        ],
                    ],
                ],
                false,
                '/properties/foo',
                "Property 'foo' declared at '#/properties/foo' can never be provided: its name is rejected by the"
                    . " propertyNames of the schema at '#/allOf/0'",
            ],
            'root property rejected by the propertyNames of every anyOf branch' => [
                [
                    'type' => 'object',
                    'properties' => ['foo' => ['type' => 'string']],
                    'anyOf' => [
                        [
                            'propertyNames' => ['pattern' => '^x'],
                        ],
                        [
                            'propertyNames' => ['pattern' => '^y'],
                        ],
                    ],
                ],
                false,
                '/properties/foo',
                "Property 'foo' declared at '#/properties/foo' can never be provided: its name is rejected by the"
                    . " propertyNames of every branch of the composition at '#/anyOf'",
            ],

            // more required properties than maxProperties allows

            'more required names than maxProperties' => [
                [
                    'type' => 'object',
                    'required' => ['foo', 'bar'],
                    'maxProperties' => 1,
                ],
                false,
                '/maxProperties',
                "Properties ['foo', 'bar'] are required but exceed the maxProperties 1 of the schema at '#'",
            ],
            'required names of the root and an allOf branch exceeding maxProperties' => [
                [
                    'type' => 'object',
                    'required' => ['foo'],
                    'allOf' => [
                        [
                            'required' => ['bar'],
                        ],
                    ],
                    'maxProperties' => 1,
                ],
                false,
                '/maxProperties',
                "Properties ['foo', 'bar'] are required but exceed the maxProperties 1 of the schema at '#'",
            ],
            'required names exceeding the maxProperties of an allOf branch' => [
                [
                    'type' => 'object',
                    'required' => ['foo', 'bar'],
                    'allOf' => [
                        [
                            'maxProperties' => 1,
                        ],
                    ],
                ],
                false,
                '/allOf/0/maxProperties',
                "Properties ['foo', 'bar'] are required but exceed the maxProperties 1 of the schema at '#/allOf/0'",
            ],
            'required name with maxProperties 0' => [
                [
                    'type' => 'object',
                    'required' => ['foo'],
                    'maxProperties' => 0,
                ],
                false,
                '/maxProperties',
                "Properties ['foo'] are required but exceed the maxProperties 0 of the schema at '#'",
            ],
        ];
    }

    #[DataProvider('contradictoryPropertyConstraintsProvider')]
    public function testContradictoryPropertyConstraintsAreRejected(
        array $schema,
        bool $denyAdditionalProperties,
        string $expectedPointer,
        string $expectedMessage,
    ): void {
        $this->assertSchemaIsRejected($schema, $denyAdditionalProperties, $expectedPointer, $expectedMessage);
    }

    public static function contradictoryBaseLevelReferenceConstraintsProvider(): array
    {
        return [
            'name declared by a base-level reference, denied by the root' => [
                [
                    'type' => 'object',
                    '$defs' => [
                        'base' => [
                            'type' => 'object',
                            'properties' => ['foo' => ['type' => 'string']],
                        ],
                    ],
                    '$ref' => '#/$defs/base',
                    'additionalProperties' => false,
                ],
                false,
                '/$defs/base/properties/foo',
                "Property 'foo' declared at '#/\$defs/base/properties/foo' can never be provided: it is denied as an"
                    . " additional property by the schema at '#'",
            ],
            'name declared by a base-level reference, denied by the denyAdditionalProperties option of the root' => [
                [
                    'type' => 'object',
                    '$defs' => [
                        'base' => [
                            'type' => 'object',
                            'properties' => ['foo' => ['type' => 'string']],
                        ],
                    ],
                    '$ref' => '#/$defs/base',
                ],
                true,
                '/$defs/base/properties/foo',
                "Property 'foo' declared at '#/\$defs/base/properties/foo' can never be provided: it is denied as an"
                    . " additional property by the schema at '#' (denyAdditionalProperties option)",
            ],
            'name declared and required by a base-level reference, denied by the root' => [
                [
                    'type' => 'object',
                    '$defs' => [
                        'base' => [
                            'type' => 'object',
                            'properties' => ['foo' => ['type' => 'string']],
                            'required' => ['foo'],
                        ],
                    ],
                    '$ref' => '#/$defs/base',
                    'additionalProperties' => false,
                ],
                false,
                '/$defs/base/required/0',
                "Property 'foo' is required but denied as an additional property by the schema at '#'",
            ],
            'required root property denied by a base-level reference' => [
                [
                    'type' => 'object',
                    '$defs' => [
                        'base' => [
                            'type' => 'object',
                            'properties' => ['bar' => ['type' => 'string']],
                            'additionalProperties' => false,
                        ],
                    ],
                    '$ref' => '#/$defs/base',
                    'properties' => ['foo' => ['type' => 'string']],
                    'required' => ['foo'],
                ],
                false,
                '/required/0',
                "Property 'foo' is required but denied as an additional property by the schema at '#/\$defs/base'",
            ],
            'root property denied by a base-level reference' => [
                [
                    'type' => 'object',
                    '$defs' => [
                        'base' => [
                            'type' => 'object',
                            'properties' => ['bar' => ['type' => 'string']],
                            'additionalProperties' => false,
                        ],
                    ],
                    '$ref' => '#/$defs/base',
                    'properties' => ['foo' => ['type' => 'string']],
                ],
                false,
                '/properties/foo',
                "Property 'foo' declared at '#/properties/foo' can never be provided: it is denied as an additional"
                    . " property by the schema at '#/\$defs/base'",
            ],
        ];
    }

    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('contradictoryBaseLevelReferenceConstraintsProvider')]
    public function testContradictoryPropertyConstraintsOfABaseLevelReferenceAreRejected(
        array $schema,
        bool $denyAdditionalProperties,
        string $expectedPointer,
        string $expectedMessage,
    ): void {
        $this->assertSchemaIsRejected($schema, $denyAdditionalProperties, $expectedPointer, $expectedMessage);
    }

    public static function satisfiablePropertyConstraintsProvider(): array
    {
        return [
            'name declared by an allOf branch, additionalProperties true' => [
                [
                    'type' => 'object',
                    'allOf' => [
                        [
                            'properties' => ['foo' => ['type' => 'string']],
                        ],
                    ],
                    'additionalProperties' => true,
                ],
                false,
                ['foo' => 'x'],
            ],
            'name declared by an allOf branch, compatible additionalProperties type' => [
                [
                    'type' => 'object',
                    'allOf' => [
                        [
                            'properties' => ['foo' => ['type' => 'string']],
                        ],
                    ],
                    'additionalProperties' => ['type' => 'string'],
                ],
                false,
                ['foo' => 'x'],
            ],
            // a name matched by a patternProperties pattern of the same schema is not additional
            'required name matching a pattern property' => [
                [
                    'type' => 'object',
                    'required' => ['x1'],
                    'patternProperties' => [
                        '^x' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
                false,
                ['x1' => 'x'],
            ],
            'name declared by an allOf branch matching a pattern property' => [
                [
                    'type' => 'object',
                    'allOf' => [
                        [
                            'properties' => ['x1' => ['type' => 'string']],
                        ],
                    ],
                    'patternProperties' => [
                        '^x' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
                false,
                ['x1' => 'x'],
            ],
            // the second branch accepts the name, so an instance providing it still matches the anyOf
            'root property denied by only one anyOf branch' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'a' => ['type' => 'string'],
                        'b' => ['type' => 'string'],
                    ],
                    'anyOf' => [
                        [
                            'properties' => ['a' => ['type' => 'string']],
                            'additionalProperties' => false,
                        ],
                        [
                            'properties' => ['b' => ['type' => 'string']],
                        ],
                    ],
                ],
                false,
                ['a' => 'x', 'b' => 'y'],
            ],
            // the then branch applies only while the if schema matches
            'root property denied by a then branch' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'a' => ['type' => 'string'],
                        'b' => ['type' => 'string'],
                    ],
                    'if' => ['required' => ['a']],
                    'then' => [
                        'properties' => ['a' => ['type' => 'string']],
                        'additionalProperties' => false,
                    ],
                ],
                false,
                ['b' => 'y'],
            ],
            // the if schema never rejects an instance, it only selects the branch
            'root property denied by an if schema' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'a' => ['type' => 'string'],
                        'b' => ['type' => 'string'],
                    ],
                    'if' => [
                        'properties' => ['a' => ['type' => 'string']],
                        'additionalProperties' => false,
                    ],
                    'then' => ['required' => ['a']],
                ],
                false,
                ['a' => 'x', 'b' => 'y'],
            ],
            // a schema dependency applies only while the dependant property is provided
            'root property denied by a schema dependency' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'a' => ['type' => 'string'],
                        'b' => ['type' => 'string'],
                    ],
                    'dependencies' => [
                        'a' => [
                            'properties' => ['a' => ['type' => 'string']],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                false,
                ['b' => 'y'],
            ],
            'property dependency on a declared name' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'a' => ['type' => 'string'],
                        'foo' => ['type' => 'string'],
                    ],
                    'dependencies' => ['a' => ['foo']],
                    'additionalProperties' => false,
                ],
                false,
                ['a' => 'x', 'foo' => 'y'],
            ],
            'denyAdditionalProperties option, branch allowing additional properties explicitly' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'a' => ['type' => 'string'],
                        'b' => ['type' => 'string'],
                    ],
                    'allOf' => [
                        [
                            'properties' => ['b' => ['type' => 'string']],
                            'additionalProperties' => true,
                        ],
                    ],
                ],
                true,
                ['a' => 'x', 'b' => 'y'],
            ],
            'denyAdditionalProperties option, branch declaring the required name' => [
                [
                    'type' => 'object',
                    'properties' => ['a' => ['type' => 'string']],
                    'allOf' => [
                        [
                            'properties' => ['a' => ['type' => 'string']],
                            'required' => ['a'],
                        ],
                    ],
                ],
                true,
                ['a' => 'x'],
            ],
            'as many required names as maxProperties' => [
                [
                    'type' => 'object',
                    'required' => ['foo', 'bar'],
                    'maxProperties' => 2,
                ],
                false,
                ['foo' => 'x', 'bar' => 'y'],
            ],
            // a name required by several schemas is counted once
            'name required by the root and an allOf branch with maxProperties 1' => [
                [
                    'type' => 'object',
                    'required' => ['foo'],
                    'allOf' => [
                        [
                            'required' => ['foo'],
                        ],
                    ],
                    'maxProperties' => 1,
                ],
                false,
                ['foo' => 'x'],
            ],
            'required name accepted by propertyNames' => [
                [
                    'type' => 'object',
                    'properties' => ['foo' => ['type' => 'string']],
                    'required' => ['foo'],
                    'propertyNames' => [
                        'pattern' => '^f',
                        'maxLength' => 3,
                    ],
                ],
                false,
                ['foo' => 'x'],
            ],
            'declared name rejected by the propertyNames of only one anyOf branch' => [
                [
                    'type' => 'object',
                    'properties' => ['foo' => ['type' => 'string']],
                    'anyOf' => [
                        [
                            'propertyNames' => ['pattern' => '^x'],
                        ],
                        [
                            'propertyNames' => ['pattern' => '^f'],
                        ],
                    ],
                ],
                false,
                ['foo' => 'x'],
            ],
        ];
    }

    #[DataProvider('satisfiablePropertyConstraintsProvider')]
    public function testSatisfiablePropertyConstraintsAreAccepted(
        array $schema,
        bool $denyAdditionalProperties,
        array $data,
    ): void {
        $this->assertSchemaIsAccepted($schema, $denyAdditionalProperties, $data);
    }

    public static function satisfiableBaseLevelReferenceConstraintsProvider(): array
    {
        return [
            // unevaluatedProperties credits the names declared by the subschemas, unlike additionalProperties
            'name declared by a base-level reference, unevaluatedProperties false' => [
                [
                    'type' => 'object',
                    '$defs' => [
                        'base' => [
                            'type' => 'object',
                            'properties' => ['foo' => ['type' => 'string']],
                        ],
                    ],
                    '$ref' => '#/$defs/base',
                    'unevaluatedProperties' => false,
                ],
                false,
                ['foo' => 'x'],
            ],
            'name declared by an allOf branch, unevaluatedProperties false' => [
                [
                    'type' => 'object',
                    'allOf' => [
                        [
                            'properties' => ['foo' => ['type' => 'string']],
                        ],
                    ],
                    'unevaluatedProperties' => false,
                ],
                false,
                ['foo' => 'x'],
            ],
            'root property declared by a closed base-level reference' => [
                [
                    'type' => 'object',
                    '$defs' => [
                        'base' => [
                            'type' => 'object',
                            'properties' => [
                                'foo' => ['type' => 'string'],
                                'bar' => ['type' => 'string'],
                            ],
                            'additionalProperties' => false,
                        ],
                    ],
                    '$ref' => '#/$defs/base',
                    'properties' => ['bar' => ['type' => 'string']],
                ],
                false,
                ['foo' => 'x', 'bar' => 'y'],
            ],
        ];
    }

    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('satisfiableBaseLevelReferenceConstraintsProvider')]
    public function testSatisfiablePropertyConstraintsOfABaseLevelReferenceAreAccepted(
        array $schema,
        bool $denyAdditionalProperties,
        array $data,
    ): void {
        $this->assertSchemaIsAccepted($schema, $denyAdditionalProperties, $data);
    }

    /**
     * The location suffix of the message is derived from the expected JSON pointer, located in the schema file the
     * generator processed.
     */
    private function assertSchemaIsRejected(
        array $schema,
        bool $denyAdditionalProperties,
        string $expectedPointer,
        string $expectedMessage,
    ): void {
        $exception = $this->catchException(fn() => $this->generateClass(
            json_encode($schema),
            (new GeneratorConfiguration())
                ->setDenyAdditionalProperties($denyAdditionalProperties)
                ->setCollectErrors(false),
        ));

        $this->assertInstanceOf(SchemaException::class, $exception);
        $this->assertNotNull($exception->getSchemaFile());

        $position = JsonPointerLocator::locate(file_get_contents($exception->getSchemaFile()), $expectedPointer);
        $this->assertNotNull($position, "The pointer '$expectedPointer' must exist in the schema");

        $this->assertSame(
            "$expectedMessage in file {$exception->getSchemaFile()} at line {$position->line}, column"
                . " {$position->column}",
            $exception->getMessage(),
        );
    }

    private function assertSchemaIsAccepted(array $schema, bool $denyAdditionalProperties, array $data): void
    {
        $className = $this->generateClass(
            json_encode($schema),
            (new GeneratorConfiguration())
                ->setDenyAdditionalProperties($denyAdditionalProperties)
                ->setCollectErrors(false),
        );

        $object = new $className($data);

        foreach ($data as $propertyName => $value) {
            $this->assertSame($value, $object->{'get' . ucfirst($propertyName)}());
        }
    }
}
