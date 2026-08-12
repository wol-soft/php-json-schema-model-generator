<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\PropertyProcessor;

use PHPModelGenerator\Draft\Draft;
use PHPModelGenerator\Draft\Draft_07;
use PHPModelGenerator\Draft\Draft_2019_09;
use PHPModelGenerator\Draft\DraftInterface;
use PHPModelGenerator\PropertyProcessor\ObjectShape\ObjectShape;
use PHPModelGenerator\PropertyProcessor\ObjectShape\ObjectShapeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ObjectShapeResolverTest extends TestCase
{
    private static ?Draft $draft = null;

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
        $this->assertSame($expectedShape, (new ObjectShapeResolver(self::draft()))->resolve($json));
    }

    private static function draft(): Draft
    {
        return self::$draft ??= (new Draft_07())->getDefinition()->build();
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
            'multi-type of object and null' => [['type' => ['object', 'null']], ObjectShape::NotObject],
            'multi-type array containing only object' => [['type' => ['object']], ObjectShape::ObjectAsserting],

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

            // `not` is excluded from COMPOSITION_KEYWORDS, so it must classify purely from its
            // sibling keywords, ignoring its own subschema entirely
            'not next to describing sibling keywords' => [
                ['not' => self::PERSON_OBJECT, 'properties' => ['name' => ['type' => 'string']]],
                ObjectShape::ObjectDescribing,
            ],
            'not next to asserting allOf' => [
                ['not' => self::PERSON_OBJECT, 'allOf' => [self::PERSON_OBJECT]],
                ObjectShape::ObjectAsserting,
            ],

            // if/then/else (conditional) aggregation
            'if-then-else with both branches asserting' => [
                [
                    'if' => ['required' => ['a']],
                    'then' => self::PERSON_OBJECT,
                    'else' => self::PERSON_OBJECT,
                ],
                ObjectShape::ObjectAsserting,
            ],
            'if without else' => [
                ['if' => ['required' => ['a']], 'then' => self::PERSON_OBJECT],
                ObjectShape::NotObject,
            ],
            'if without then' => [
                ['if' => ['required' => ['a']], 'else' => self::PERSON_OBJECT],
                ObjectShape::NotObject,
            ],
            'if without then and without else' => [
                ['if' => ['required' => ['a']]],
                ObjectShape::NotObject,
            ],
            'if-then-else with describing else' => [
                ['if' => ['required' => ['a']], 'then' => self::PERSON_OBJECT, 'else' => self::BARE_VALIDATORS],
                ObjectShape::ObjectDescribing,
            ],
            'if-then-else with unsatisfiable then' => [
                ['if' => ['required' => ['a']], 'then' => false, 'else' => self::PERSON_OBJECT],
                ObjectShape::NotObject,
            ],
            'if-then-else asserting next to describing sibling keywords' => [
                [
                    'if' => ['required' => ['a']],
                    'then' => self::PERSON_OBJECT,
                    'else' => self::PERSON_OBJECT,
                    'properties' => ['b' => ['type' => 'string']],
                ],
                ObjectShape::ObjectAsserting,
            ],

            // Filter-bearing schemas stay on the filter machinery. Undecidable, not NotObject:
            // the filter subsystem's own compatibility check reports a precise,
            // correctly-attributed error moments later; a confident NotObject verdict here would
            // preempt it with a generic representability message instead.
            'filter without type' => [
                ['filter' => 'dateTime', 'allOf' => [['type' => 'object']]],
                ObjectShape::Undecidable,
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
            'empty anyOf' => [['anyOf' => []], ObjectShape::NotObject],
            'empty oneOf' => [['oneOf' => []], ObjectShape::NotObject],
            'oneOf of asserting branches' => [
                ['oneOf' => [self::PERSON_OBJECT, ['type' => 'object']]],
                ObjectShape::ObjectAsserting,
            ],
            'oneOf of describing branches' => [
                ['oneOf' => [self::BARE_VALIDATORS, ['required' => ['other']]]],
                ObjectShape::ObjectDescribing,
            ],
            // A boolean `true` branch is Neutral, and combineDisjunctive() degrades the aggregate
            // to Neutral (hence NotObject) rather than letting the asserting sibling carry it:
            // `true` matches every value, so the union accepts non-objects too. The conjunctive
            // twin ('allOf asserting plus true branch' above) resolves the other way, which is
            // exactly why both directions need their own row.
            'anyOf asserting plus true branch' => [
                ['anyOf' => [self::PERSON_OBJECT, true]],
                ObjectShape::NotObject,
            ],
            'oneOf asserting plus true branch' => [
                ['oneOf' => [self::PERSON_OBJECT, true]],
                ObjectShape::NotObject,
            ],
            // A `false` branch is Blocking, which outranks Neutral in combineDisjunctive(), so an
            // anyOf that can still be satisfied through its asserting branch nevertheless does not
            // classify as object-asserting.
            'anyOf asserting plus false branch' => [
                ['anyOf' => [self::PERSON_OBJECT, false]],
                ObjectShape::NotObject,
            ],

            // 'dependencies' is the single entry in UNREGISTERED_OBJECT_DESCRIBING_KEYWORDS: it is
            // consumed through a side channel and never registered on the Draft's object Type, so
            // it cannot be derived from Type::getModifiers() the way every other describing
            // keyword is. Without its hardcoded entry these rows would classify NotObject.
            'dependencies only' => [
                ['dependencies' => ['creditCard' => ['billingAddress']]],
                ObjectShape::ObjectDescribing,
            ],
            'dependencies next to asserting allOf' => [
                [
                    'dependencies' => ['creditCard' => ['billingAddress']],
                    'allOf' => [self::PERSON_OBJECT],
                ],
                ObjectShape::ObjectAsserting,
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

            // $ref without a resolver - genuinely undecidable, not a confident NotObject verdict:
            // without a resolver the classifier cannot even attempt to look at the target.
            'reference without resolver' => [['$ref' => '#/definitions/person'], ObjectShape::Undecidable],

            // Undecidable takes precedence over Blocking in both combine methods: a definite
            // verdict cannot be derived from an aggregate that also contains a component whose
            // own shape is unknown, and the filter subsystem that owns the Undecidable branch
            // reports its own precise error moments later - see ObjectShapeResolver's
            // combineConjunctive()/combineDisjunctive() docblocks for the full rationale.
            'allOf: undecidable filter branch beats blocking scalar branch' => [
                [
                    'allOf' => [
                        ['filter' => 'dateTime', 'type' => 'string'],
                        ['type' => 'string'],
                    ],
                ],
                ObjectShape::Undecidable,
            ],
            'anyOf: undecidable filter branch beats blocking scalar branch' => [
                [
                    'anyOf' => [
                        ['filter' => 'dateTime', 'type' => 'string'],
                        ['type' => 'string'],
                    ],
                ],
                ObjectShape::Undecidable,
            ],
        ];
    }

    /**
     * Whether keywords sitting next to a `$ref` constrain the same value is decided by the draft,
     * not by this classifier: Draft 07 and earlier ignore them entirely (their `$ref` producer is
     * an ExclusiveProducer), Draft 2019-09 and later apply them alongside the reference. The
     * classification has to follow whichever rule the generator will actually apply, because a
     * disagreement rejects schemas the generator handles fine.
     *
     * The concrete regression this pins: under Draft 07 the `type` sibling below is ignored, so
     * `allOf: [{$ref: <object>, type: string}]` generates - a classifier that merged the sibling
     * would call the branch Blocking and reject it.
     */
    #[DataProvider('referenceSiblingPolicyDataProvider')]
    public function testReferenceSiblingPolicyFollowsTheDraft(
        DraftInterface $draft,
        array $json,
        ObjectShape $expectedShape,
    ): void {
        $definitions = [
            '#/definitions/person' => self::PERSON_OBJECT,
            '#/definitions/bare' => self::BARE_VALIDATORS,
        ];

        $resolver = new ObjectShapeResolver(
            $draft->getDefinition()->build(),
            static fn(string $reference): array|bool|null => $definitions[$reference] ?? null,
        );

        $this->assertSame($expectedShape, $resolver->resolve($json));
    }

    public static function referenceSiblingPolicyDataProvider(): array
    {
        // A contradictory `type` sibling: merging it would block, ignoring it keeps the target.
        $contradictingType = ['$ref' => '#/definitions/person', 'type' => 'string'];
        // A sibling that would UPGRADE a describing target to asserting if it were applied.
        $assertingType = ['$ref' => '#/definitions/bare', 'type' => 'object'];

        return [
            'draft 07 ignores a contradicting type sibling' => [
                new Draft_07(),
                $contradictingType,
                ObjectShape::ObjectAsserting,
            ],
            'draft 2019-09 applies a contradicting type sibling' => [
                new Draft_2019_09(),
                $contradictingType,
                ObjectShape::NotObject,
            ],
            'draft 07 ignores an asserting type sibling' => [
                new Draft_07(),
                $assertingType,
                ObjectShape::ObjectDescribing,
            ],
            'draft 2019-09 applies an asserting type sibling' => [
                new Draft_2019_09(),
                $assertingType,
                ObjectShape::ObjectAsserting,
            ],
        ];
    }

    #[DataProvider('referenceShapeDataProvider')]
    public function testResolveWithReferences(
        array $definitions,
        array|bool $json,
        ObjectShape $expectedShape,
    ): void {
        $resolver = new ObjectShapeResolver(
            self::draft(),
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
                ObjectShape::Undecidable,
            ],
            // Undecidable, not NotObject: an unresolvable reference does not decidably rule out
            // object-ness (the target simply could not be located), and takes precedence over the
            // sibling Asserting branch so the real $ref resolution's own "unresolved reference"
            // error surfaces instead of a generic representability rejection here.
            'unresolvable reference wins over sibling assertion' => [
                [],
                ['allOf' => [self::PERSON_OBJECT, ['$ref' => '#/definitions/missing']]],
                ObjectShape::Undecidable,
            ],
            'cyclic reference' => [
                [
                    '#/definitions/a' => ['allOf' => [['$ref' => '#/definitions/b']]],
                    '#/definitions/b' => ['allOf' => [['$ref' => '#/definitions/a']]],
                ],
                ['$ref' => '#/definitions/a'],
                ObjectShape::Undecidable,
            ],
            // Sibling keywords next to a $ref are draft-dependent and are covered by
            // testReferenceSiblingPolicyFollowsTheDraft() below. These two rows belong here
            // because they resolve the same way under either policy: the target alone already
            // decides them, so no sibling can change the verdict.
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

            // $ref targets that are boolean schemas - a resolver may legitimately return true or
            // false, which classify() treats as Neutral and Blocking respectively (see the
            // is_bool branch). Standalone, both degrade to NotObject the same way, so the
            // distinction is only visible once a sibling allOf branch is asserting. Note this is
            // a genuinely DECIDABLE Blocking, unlike the Undecidable cases above: the resolver
            // did resolve the reference and the target is definitely not object-shaped, so it
            // correctly still wins over a sibling Asserting branch (see the next case) - contrast
            // with 'unresolvable reference wins over sibling assertion' above, where the resolver
            // could not determine the target at all.
            'reference to true schema' => [
                ['#/definitions/anything' => true],
                ['$ref' => '#/definitions/anything'],
                ObjectShape::NotObject,
            ],
            'reference to false schema' => [
                ['#/definitions/unsatisfiable' => false],
                ['$ref' => '#/definitions/unsatisfiable'],
                ObjectShape::NotObject,
            ],
            'allOf asserting plus reference to true schema' => [
                ['#/definitions/anything' => true],
                ['allOf' => [self::PERSON_OBJECT, ['$ref' => '#/definitions/anything']]],
                ObjectShape::ObjectAsserting,
            ],
            // This case also stands in for ObjectShapeResolver::forDictionary()'s real $ref
            // resolver, which cannot let a boolean-valued *definition* (e.g.
            // `"definitions": {"x": false}`) round-trip through JsonSchema, whose $json property
            // is typed `array`: it translates that failure into this same literal `false` return
            // (see the TypeError catch in forDictionary()) rather than null, precisely so a
            // boolean-leaf definition stays a decidable Blocking instead of degrading to
            // Undecidable.
            'allOf asserting plus reference to false schema' => [
                ['#/definitions/unsatisfiable' => false],
                ['allOf' => [self::PERSON_OBJECT, ['$ref' => '#/definitions/unsatisfiable']]],
                ObjectShape::NotObject,
            ],

            // A non-string `$ref` value hits classifyReference()'s explicit is_string guard and
            // is undecidable before the resolver is ever consulted
            'non-string reference value (array)' => [
                [],
                ['$ref' => ['not', 'a', 'string']],
                ObjectShape::Undecidable,
            ],
            'non-string reference value (integer)' => [
                [],
                ['$ref' => 5],
                ObjectShape::Undecidable,
            ],

            // then/else given as $refs - classifyIfThenElse() recurses through classify(), which
            // resolves $ref branches exactly like any other branch
            'if-then-else with both branches referencing asserting target' => [
                ['#/definitions/person' => self::PERSON_OBJECT],
                [
                    'if' => ['required' => ['a']],
                    'then' => ['$ref' => '#/definitions/person'],
                    'else' => ['$ref' => '#/definitions/person'],
                ],
                ObjectShape::ObjectAsserting,
            ],
            'if-then-else with one branch referencing scalar target' => [
                [
                    '#/definitions/person' => self::PERSON_OBJECT,
                    '#/definitions/name' => ['type' => 'string'],
                ],
                [
                    'if' => ['required' => ['a']],
                    'then' => ['$ref' => '#/definitions/person'],
                    'else' => ['$ref' => '#/definitions/name'],
                ],
                ObjectShape::NotObject,
            ],
        ];
    }
}
