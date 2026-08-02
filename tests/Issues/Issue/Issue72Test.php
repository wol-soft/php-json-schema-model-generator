<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\ComposedValue\NotException;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\Dependency\InvalidSchemaDependencyException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\Fixtures\RecordingLogger;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #72 / PR #74: a schema whose object shape is only implied by composition - an allOf of
 * object branches with no `type` of its own, or a bare `properties`/`required` branch with no
 * `type` at all - lost its validation and instantiation once nested inside anyOf/oneOf/allOf/
 * if-then-else/not. Such branches must behave exactly like their explicit `type: object`
 * equivalents: matching values are instantiated and validated, non-matching values are rejected.
 */
class Issue72Test extends AbstractIssueTestCase
{
    /**
     * Deeply nested `allOf` compositions (a property whose `allOf` branches are themselves `$ref`s
     * to further `allOf` definitions) must instantiate the nested property as an object exposing
     * working getters, not a raw associative array.
     */
    public function testDeeplyNestedAllOfCompositionInstantiatesNestedObject(): void
    {
        $className = $this->generateClassFromFile('NestedAllOf.json');

        $company = new $className([
            'CEO' => [
                'yearsInCompany' => 10,
                'name' => 'Hannes',
                'salary' => 10000,
                'assistance' => [
                    'yearsInCompany' => 4,
                    'name' => 'Dieter',
                    'salary' => 8000,
                ],
            ],
        ]);

        $ceo = $company->getCEO();
        $this->assertIsObject($ceo);
        $this->assertSame(10, $ceo->getYearsInCompany());
        $this->assertSame('Hannes', $ceo->getName());
        $this->assertSame(10000, $ceo->getSalary());

        $assistance = $ceo->getAssistance();
        $this->assertIsObject($assistance);
        $this->assertSame(4, $assistance->getYearsInCompany());
        $this->assertSame('Dieter', $assistance->getName());
        $this->assertSame(8000, $assistance->getSalary());
    }

    /**
     * Array items referencing a multi-level composition-implied definition (an allOf whose
     * branches are themselves allOf-only $refs) must instantiate each item and enforce the
     * item constraints - like the explicit-object equivalent does, and like a single-level
     * implied item (an allOf of explicit object branches). The same array must reject items
     * violating the implied definition's constraints.
     */
    public function testMultiLevelImpliedObjectArrayItemsInstantiatesValidItemsAndRejectsInvalidItems(): void
    {
        $className = $this->generateClassFromFile('NestedAllOfInArrayItems.json');

        // a valid item matches the implied definition and is instantiated as an object
        $object = new $className(['members' => [['name' => 'Hannes', 'salary' => 10000]]]);
        $members = $object->getMembers();
        $this->assertCount(1, $members);
        $this->assertIsObject($members[0]);
        $this->assertSame('Hannes', $members[0]->getName());
        $this->assertSame(10000, $members[0]->getSalary());

        // an item violating the implied definition's constraints is rejected
        try {
            new $className(['members' => [['salary' => 10000]]]);
            $this->fail('Expected an InvalidItemException for the item violating the implied definition');
        } catch (InvalidItemException $exception) {
            // The item references a multi-level composition-implied definition, so the failure is a
            // two-level nested composition error; direct-exception mode surfaces the leaf reason at
            // the bottom. Both nested classes are named after their $ref definition ("person",
            // "identification") and their uniqid suffixes are pinned only by shape.
            $this->assertCompositionExceptionMessage(
                <<<'ERROR'
                Invalid items in array 'members':
                  - invalid item #0
                    * Invalid value for '%class__Person%' declined by composition constraint
                      Requires to match all composition elements but matched 1 element
                      - Composition element #1: Failed
                        * Invalid value for '%class__Identification%' declined by composition constraint
                          Requires to match all composition elements but matched 0 elements
                          - Composition element #1: Failed
                            * Missing required value for 'name'
                      - Composition element #2: Valid
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * A genuinely contradictory `allOf` at the schema root (one branch requires an object shape,
     * the other requires a plain string - no value can ever satisfy both) must be caught at
     * generation time with a clear diagnostic.
     */
    public function testRootLevelAllOfWithConflictingObjectAndScalarTypesThrowsConflictingTypesException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Property '\\w+' is defined with conflicting types in allOf composition branches"
                . ' \\(file (.*)\\.json\\)\\. allOf requires all constraints to hold simultaneously,'
                . ' making this schema unsatisfiable\\. at line 1, column 1$/',
        );

        $this->generateClassFromFile('AllOfConflictingObjectAndScalar.json');
    }

    /**
     * The same conflicting object/string `allOf` nested inside a property must also be caught at
     * generation time with the same clear diagnostic.
     */
    public function testPropertyLevelAllOfWithConflictingObjectAndScalarTypesThrowsConflictingTypesException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Property 'property' is defined with conflicting types in allOf composition branches"
                . ' \\(file (.*)\\.json\\)\\. allOf requires all constraints to hold simultaneously,'
                . ' making this schema unsatisfiable\\. at line 1, column \\d+$/',
        );

        $this->generateClassFromFile('PropertyLevelAllOfConflictingObjectAndScalar.json');
    }

    /**
     * An `anyOf` whose branches are composition-implied objects - allOf-only subschemas reached
     * via $ref as well as written inline - must behave exactly like the same `anyOf` with
     * explicit object branches: a value matching a branch is accepted and instantiated as an
     * object exposing getters for the matched properties. The same `anyOf` must reject values
     * matching no branch - like the explicit-object equivalent does.
     */
    #[DataProvider('impliedAnyOfSchemaDataProvider')]
    public function testAnyOfWithImpliedObjectBranchesInstantiatesMatchingValueAndRejectsNonMatchingValue(
        string $schemaFile,
    ): void {
        $className = $this->generateClassFromFile($schemaFile);

        // values matching a branch are accepted and instantiated as an object exposing getters
        // for the matched properties
        $personMatch = new $className(['p' => ['name' => 'Hannes', 'salary' => 10000]]);
        $person = $personMatch->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());
        $this->assertSame(10000, $person->getSalary());

        $agedMatch = new $className(['p' => ['age' => 42]]);
        $aged = $agedMatch->getP();
        $this->assertIsObject($aged);
        $this->assertSame(42, $aged->getAge());

        // values matching no branch are rejected
        $nonMatchingValues = [
            'object matching no branch' => [],
            'scalar value' => 42,
        ];

        foreach ($nonMatchingValues as $valueLabel => $nonMatchingValue) {
            try {
                new $className(['p' => $nonMatchingValue]);
                $this->fail("Expected an AnyOfException for the $valueLabel");
            } catch (AnyOfException $exception) {
                $this->assertStringContainsString(
                    <<<'ERROR'
                    Invalid value for 'p' declined by composition constraint
                      Requires to match at least one composition element
                    ERROR,
                    $exception->getMessage(),
                );
            }
        }
    }

    public static function impliedAnyOfSchemaDataProvider(): array
    {
        return [
            '$ref branches' => ['NestedAnyOf.json'],
            'inline branches' => ['NestedAnyOfInline.json'],
        ];
    }

    /**
     * A `oneOf` whose branches are composition-implied objects ($ref and inline variants) must
     * accept a value matching exactly one branch and instantiate it as that branch's object -
     * like the explicit-object equivalent, which returns the matched branch's class instance.
     * The same `oneOf` must reject values matching both branches or neither branch.
     */
    #[DataProvider('impliedOneOfSchemaDataProvider')]
    public function testOneOfWithImpliedObjectBranchesInstantiatesMatchingValueAndRejectsNonMatchingValue(
        string $schemaFile,
    ): void {
        $className = $this->generateClassFromFile($schemaFile);

        // a value matching exactly one branch is accepted and instantiated as that branch's object
        $object = new $className(['p' => ['name' => 'Hannes']]);
        $person = $object->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());

        // values matching both branches or neither branch are rejected
        $nonMatchingValues = [
            'matches both branches' => [['name' => 'Hannes', 'companyName' => 'ACME'], 2],
            'matches neither branch' => [[], 0],
        ];

        foreach ($nonMatchingValues as $valueLabel => [$nonMatchingValue, $expectedMatchedElements]) {
            try {
                new $className(['p' => $nonMatchingValue]);
                $this->fail("Expected a OneOfException for the value that $valueLabel");
            } catch (OneOfException $exception) {
                $this->assertStringContainsString(
                    <<<ERROR
                    Invalid value for 'p' declined by composition constraint
                      Requires to match one composition element but matched $expectedMatchedElements elements
                    ERROR,
                    $exception->getMessage(),
                );
            }
        }
    }

    public static function impliedOneOfSchemaDataProvider(): array
    {
        return [
            '$ref branches' => ['NestedOneOf.json'],
            'inline branches' => ['NestedOneOfInline.json'],
        ];
    }

    /**
     * An if/then/else whose then/else branches are composition-implied objects ($ref and inline
     * variants) must validate and instantiate the taken branch - like the explicit-object
     * equivalent, which returns the taken branch's class instance. The same if/then/else must
     * reject a value that satisfies the condition but violates the then branch's constraints.
     * The `$ref` variant's then-branch class is named after its definition ("person"); the
     * inline variant has no definition to draw a name from, so ClassNameGenerator falls back to
     * the property name plus a content hash of the branch.
     */
    #[DataProvider('impliedIfThenElseSchemaWithClassTokenDataProvider')]
    public function testIfThenElseWithImpliedObjectBranchesInstantiatesMatchingValueAndRejectsInvalidTakenBranch(
        string $schemaFile,
        string $thenBranchClassToken,
    ): void {
        $className = $this->generateClassFromFile($schemaFile);

        // both branches, when satisfied and valid, are instantiated as that branch's object
        $thenMatch = new $className(['p' => ['isPerson' => true, 'name' => 'Hannes']]);
        $person = $thenMatch->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());

        $elseMatch = new $className(['p' => ['companyName' => 'ACME']]);
        $company = $elseMatch->getP();
        $this->assertIsObject($company);
        $this->assertSame('ACME', $company->getCompanyName());

        // a value that satisfies the condition but violates the then branch's constraints is rejected
        try {
            new $className(['p' => ['isPerson' => true, 'companyName' => 'ACME']]);
            $this->fail('Expected a ConditionalException for the value violating the taken branch');
        } catch (ConditionalException $exception) {
            // The then-branch is a composition-implied object, so the taken-branch failure is
            // reported as a nested composition error; direct-exception mode surfaces the underlying
            // leaf reason ("Missing required value for name").
            $this->assertCompositionExceptionMessage(
                <<<ERROR
                Invalid value for 'p' declined by conditional composition constraint
                  - Condition: Valid
                  - Conditional branch failed:
                    * Invalid value for '$thenBranchClassToken' declined by composition constraint
                      Requires to match all composition elements but matched 0 elements
                      - Composition element #1: Failed
                        * Missing required value for 'name'
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    public static function impliedIfThenElseSchemaWithClassTokenDataProvider(): array
    {
        return [
            '$ref branches' => ['NestedIfThenElse.json', '%class__Person%'],
            'inline branches' => ['NestedIfThenElseInline.json', '%inlineClass__p%'],
        ];
    }

    /**
     * Asserts the complete exception message against an expected message template. A re-routed
     * composition-implied object branch is validated through a generated class whose name carries
     * a per-run uniqid suffix, so it cannot be asserted verbatim - only its shape is pinned, via
     * tokens:
     *
     * - `%rootClass%` - the file's own root class (`Issue72Test_<uniqid>`), for when the reported
     *   composition IS the root schema itself.
     * - `%class__<Title>%` - a nested class named after a fixed, literal `$ref` definition name
     *   (`Issue72Test_<uniqid>_<Title><uniqid>`).
     * - `%inlineClass__<propertyName>%` - a nested class for an inline (non-$ref) composition
     *   branch, which has no definition name to draw on and falls through to
     *   ClassNameGenerator's default naming: the property name plus a content hash of the branch
     *   (`Issue72Test_<uniqid>_<PropertyName><md5><uniqid>`).
     */
    private function assertCompositionExceptionMessage(string $expectedMessageTemplate, string $actualMessage): void
    {
        $className = preg_quote($this->getStaticClassName(), '~');
        $uniqid = '[0-9A-Za-z]{13}';

        $pattern = preg_replace_callback(
            '/%(rootClass|class__[A-Za-z]+|inlineClass__[A-Za-z]+)%/',
            function (array $matches) use ($className, $uniqid): string {
                $token = $matches[1];

                if ($token === 'rootClass') {
                    return "{$className}_{$uniqid}";
                }

                if (str_starts_with($token, 'class__')) {
                    $title = preg_quote(substr($token, strlen('class__')), '~');

                    return "{$className}_{$uniqid}_{$title}{$uniqid}";
                }

                // inlineClass__<propertyName>
                $propertyName = preg_quote(ucfirst(substr($token, strlen('inlineClass__'))), '~');

                return "{$className}_{$uniqid}_{$propertyName}[0-9a-f]{32}{$uniqid}";
            },
            preg_quote($expectedMessageTemplate, '~'),
        );

        $this->assertMatchesRegularExpression("~^{$pattern}\$~", $actualMessage);
    }

    /**
     * A `not` with a composition-implied object schema ($ref and inline variants) must accept
     * values not matching the forbidden schema. Unlike the other composition keywords, the value
     * legitimately stays a raw array - `not` describes what the value must NOT be, so no class
     * represents it; verified against the explicit-object equivalent. The same `not` must reject
     * values matching the forbidden schema.
     */
    #[DataProvider('impliedNotSchemaDataProvider')]
    public function testNotWithImpliedObjectSchemaAcceptsNonMatchingValueAndRejectsMatchingValue(
        string $schemaFile,
    ): void {
        $className = $this->generateClassFromFile($schemaFile);

        // a value not matching the forbidden schema is accepted, staying a raw array
        $object = new $className(['p' => ['name' => 'Hannes']]);

        $this->assertSame(['name' => 'Hannes'], $object->getP());

        // a value matching the forbidden schema is rejected
        try {
            new $className(['p' => ['password' => 'secret']]);
            $this->fail('Expected a NotException for the value matching the forbidden schema');
        } catch (NotException $exception) {
            $this->assertStringContainsString(
                <<<'ERROR'
                Invalid value for 'p' declined by composition constraint
                  Requires to match none composition element but matched 1 element
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    public static function impliedNotSchemaDataProvider(): array
    {
        return [
            '$ref forbidden schema' => ['NestedNot.json'],
            'inline forbidden schema' => ['NestedNotInline.json'],
        ];
    }

    /**
     * A mixed `anyOf` combining a composition-implied object branch with a scalar branch must
     * behave exactly like its explicit-object equivalent: an object matching the implied branch
     * is instantiated, a string takes the scalar branch unchanged. The same mixed `anyOf` must
     * reject values matching neither the implied object branch nor the scalar branch.
     */
    public function testAnyOfMixingImpliedObjectAndScalarBranchBehavesLikeExplicitEquivalent(): void
    {
        $className = $this->generateClassFromFile('NestedAnyOfMixedScalar.json');

        // an object matching the implied branch is instantiated
        $objectMatch = new $className(['p' => ['name' => 'Hannes']]);
        $person = $objectMatch->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());

        // a string takes the scalar branch unchanged
        $stringMatch = new $className(['p' => 'hello']);
        $this->assertSame('hello', $stringMatch->getP());

        // values matching neither the implied object branch nor the scalar branch are rejected
        $nonMatchingValues = [
            'integer matching no branch' => 42,
            'object matching no branch' => [],
        ];

        foreach ($nonMatchingValues as $valueLabel => $nonMatchingValue) {
            try {
                new $className(['p' => $nonMatchingValue]);
                $this->fail("Expected an AnyOfException for the $valueLabel");
            } catch (AnyOfException $exception) {
                $this->assertStringContainsString(
                    <<<'ERROR'
                    Invalid value for 'p' declined by composition constraint
                      Requires to match at least one composition element
                    ERROR,
                    $exception->getMessage(),
                );
            }
        }
    }

    /**
     * A mixed `oneOf` combining a composition-implied object branch with a scalar branch must
     * behave exactly like its explicit-object equivalent: an object matching the implied branch
     * is instantiated, a string matching only the scalar branch is accepted unchanged. The same
     * mixed `oneOf` must reject values matching neither branch.
     */
    public function testOneOfMixingImpliedObjectAndScalarBranchBehavesLikeExplicitEquivalent(): void
    {
        $className = $this->generateClassFromFile('NestedOneOfMixedScalar.json');

        // an object matching the implied branch is instantiated
        $objectMatch = new $className(['p' => ['name' => 'Hannes']]);
        $person = $objectMatch->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());

        // a string matching only the scalar branch is accepted unchanged
        $stringMatch = new $className(['p' => 'hello']);
        $this->assertSame('hello', $stringMatch->getP());

        // a value matching neither branch is rejected
        try {
            new $className(['p' => 42]);
            $this->fail('Expected a OneOfException for the value matching neither branch');
        } catch (OneOfException $exception) {
            $this->assertStringContainsString(
                <<<'ERROR'
                Invalid value for 'p' declined by composition constraint
                  Requires to match one composition element but matched 0 elements
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * A mixed if/then/else with a composition-implied object then-branch and a scalar
     * else-branch must behave exactly like its explicit-object equivalent: objects are routed
     * into the then-branch and instantiated, non-objects into the scalar else-branch. The same
     * mixed if/then/else must reject an object violating the implied then-branch.
     */
    public function testIfThenElseMixingImpliedObjectThenAndScalarElseBehavesLikeExplicitEquivalent(): void
    {
        $className = $this->generateClassFromFile('NestedIfThenElseMixedScalar.json');

        // objects are routed into the then-branch and instantiated
        $thenMatch = new $className(['p' => ['name' => 'Hannes']]);
        $person = $thenMatch->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());

        // non-objects are routed into the scalar else-branch
        $elseMatch = new $className(['p' => 'hello']);
        $this->assertSame('hello', $elseMatch->getP());

        // an object violating the implied then-branch is rejected
        try {
            new $className(['p' => []]);
            $this->fail('Expected a ConditionalException for the object violating the then branch');
        } catch (ConditionalException $exception) {
            // The then-branch resolves to the "person" $ref definition, so its nested class is
            // named after that definition.
            $this->assertCompositionExceptionMessage(
                <<<'ERROR'
                Invalid value for 'p' declined by conditional composition constraint
                  - Condition: Valid
                  - Conditional branch failed:
                    * Invalid value for '%class__Person%' declined by composition constraint
                      Requires to match all composition elements but matched 0 elements
                      - Composition element #1: Failed
                        * Missing required value for 'name'
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * An `allOf` mixing a composition-implied object branch with a scalar branch is
     * unsatisfiable - no value can be an object and a string simultaneously - and must be
     * rejected at generation time with the same diagnostic as the explicit object-vs-scalar
     * conflict.
     */
    public function testAllOfMixingImpliedObjectAndScalarBranchThrowsConflictingTypesException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Property 'p' is defined with conflicting types in allOf composition branches"
                . ' \\(file (.*)\\.json\\)\\. allOf requires all constraints to hold simultaneously,'
                . ' making this schema unsatisfiable\\. at line 1, column \\d+$/',
        );

        $this->generateClassFromFile('NestedAllOfMixedScalarConflict.json');
    }

    /**
     * A `oneOf` whose branches carry only object validators (properties/required) without any
     * type keyword must accept an object matching exactly one branch and instantiate it. The
     * accept/reject outcomes are identical under strict spec semantics (a non-object matches
     * every bare branch vacuously, so it fails oneOf by matching both) and under object-implied
     * semantics (a non-object matches no branch) - only the failure reason differs. The same
     * bare-validator `oneOf` must reject values whose outcome is identical under strict spec and
     * object-implied semantics: objects matching both branches, objects matching neither, and
     * non-objects. The expected matched-counts follow the strict-spec reading (`required`/
     * `properties` constrain only objects): a non-object matches both bare branches vacuously
     * and is rejected for matching 2 elements, not 0.
     */
    public function testOneOfWithBareObjectValidatorBranchesInstantiatesMatchingValueAndRejectsNonMatchingValue(): void
    {
        $className = $this->generateClassFromFile('NestedOneOfBareObjectValidators.json');

        // an object matching exactly one branch is accepted and instantiated
        $object = new $className(['p' => ['name' => 'Hannes']]);
        $person = $object->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());

        // objects matching both branches, objects matching neither, and non-objects are rejected
        $nonMatchingValues = [
            'object matching both branches' => [['name' => 'Hannes', 'companyName' => 'ACME'], 2],
            'object matching neither branch' => [[], 0],
            'non-object matching both vacuously' => [42, 2],
        ];

        foreach ($nonMatchingValues as $valueLabel => [$nonMatchingValue, $expectedMatchedElements]) {
            try {
                new $className(['p' => $nonMatchingValue]);
                $this->fail("Expected a OneOfException for the $valueLabel");
            } catch (OneOfException $exception) {
                $this->assertStringContainsString(
                    <<<ERROR
                    Invalid value for 'p' declined by composition constraint
                      Requires to match one composition element but matched $expectedMatchedElements elements
                    ERROR,
                    $exception->getMessage(),
                );
            }
        }
    }

    /**
     * An `anyOf` whose branches carry only object validators must accept an object matching a
     * branch and reject an object matching no branch - outcomes on which strict spec and
     * object-implied semantics agree (`required` does constrain objects, so an empty object
     * fails both branches).
     *
     * A NON-object value in a bare-validator `anyOf` must be ACCEPTED, following strict JSON
     * Schema semantics: `properties`/`required` only constrain objects, so a non-object matches
     * every bare branch vacuously and satisfies the anyOf. Treating the bare branches as
     * object-implied (rejecting `42`) was considered and rejected - overriding spec semantics
     * must remain a narrowly whitelisted opt-in, and authors who mean objects can declare
     * `type: object`. This deliberately differs from the oneOf case, where a non-object's
     * vacuous match on BOTH branches violates "exactly one" and is rejected under the same
     * strict-spec reading.
     */
    public function testAnyOfWithBareObjectValidatorBranchesValidatesObjectValuesAndAcceptsNonObjectValuePerSpec(): void
    {
        $className = $this->generateClassFromFile('NestedAnyOfBareObjectValidators.json');

        // an object matching a branch is accepted and instantiated
        $object = new $className(['p' => ['name' => 'Hannes']]);
        $person = $object->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());

        // a non-object value is accepted per strict spec semantics (vacuous match on both branches)
        $nonObjectValue = new $className(['p' => 42]);
        $this->assertSame(42, $nonObjectValue->getP());

        // an object matching no branch is rejected
        try {
            new $className(['p' => []]);
            $this->fail('Expected an AnyOfException for the object matching no branch');
        } catch (AnyOfException $exception) {
            $this->assertStringContainsString(
                <<<'ERROR'
                Invalid value for 'p' declined by composition constraint
                  Requires to match at least one composition element
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * A standalone property carrying only object validators (properties/required) without a type -
     * reached directly rather than through a composition - is object-describing: it constrains
     * object values but is vacuously satisfied by non-object values per strict JSON Schema. It must
     * instantiate and validate an object value while passing a non-object value through unchanged
     * (the getter type stays open, not the representation class, so the non-object does not violate
     * an object return type).
     */
    public function testStandaloneObjectDescribingPropertyValidatesObjectsAndPassesNonObjects(): void
    {
        $className = $this->generateClassFromFile(
            'StandaloneObjectDescribingProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $objectValue = new $className(['p' => ['name' => 'Hannes']]);
        $person = $objectValue->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());

        $scalarValue = new $className(['p' => 42]);
        $this->assertSame(42, $scalarValue->getP());

        // A JSON array is not a JSON object either, even though json_decode(..., true) makes both
        // indistinguishable as a plain PHP array - the array_is_list() guard on the instantiation
        // decorator must still pass it through unchanged rather than instantiating it.
        $arrayValue = new $className(['p' => ['Hannes', 'Dieter']]);
        $this->assertSame(['Hannes', 'Dieter'], $arrayValue->getP());

        // The getter's and setter's type hints and annotations must stay open (mixed): a strict
        // representation type would be violated by the passed-through non-object value.
        $this->assertSame('mixed', $this->getReturnTypeAnnotation($className, 'getP'));
        $this->assertSame(['mixed', 'null'], $this->getReturnTypeNames($className, 'getP'));
        $this->assertSame('mixed', $this->getParameterTypeAnnotation($className, 'setP'));
        $this->assertSame(['mixed', 'null'], $this->getParameterTypeNames($className, 'setP'));
    }

    /**
     * The same standalone describing property must still reject an object that violates its
     * constraints - they are not vacuous for object values.
     */
    public function testStandaloneObjectDescribingPropertyRejectsInvalidObject(): void
    {
        $this->expectException(NestedObjectException::class);
        $this->expectExceptionMessage(
            <<<'ERROR'
            Invalid nested object for property 'p':
              - Missing required value for 'name'
            ERROR,
        );

        $className = $this->generateClassFromFile('StandaloneObjectDescribingProperty.json');

        new $className(['p' => []]);
    }

    /**
     * A property carrying object-constraining keywords without a `type` declaration must emit a
     * generation-time warning that its constraints do not apply to non-object values - the same
     * describing classification applies whether reached directly or through a composition branch,
     * so one warning site in PropertyFactory covers both.
     */
    public function testObjectDescribingPropertyEmitsAGenerationTimeWarning(): void
    {
        $recordingLogger = new RecordingLogger();

        $this->generateClassFromFile(
            'StandaloneObjectDescribingProperty.json',
            (new GeneratorConfiguration())->setLogger($recordingLogger),
        );

        $this->assertTrue(
            $this->hasLogEntry(
                $recordingLogger->getEntries(),
                'warning',
                "Property '{property}' carries object-constraining keywords (eg. 'properties',"
                    . " 'required') without a 'type' declaration and does not constrain non-object values",
                ['property' => 'p'],
            ),
            'Expected a describing-property warning for p.',
        );
    }

    /**
     * A root-level `allOf` of two composition-implied-object $ref definitions must transfer both
     * definitions' properties onto the generated root class, and promote a property to
     * non-nullable when it is required by the branch that contributes it. The same root-level
     * composition must still enforce the promoted requirement at runtime - required-promotion
     * only changes the getter's type hint; the actual rejection still comes from the normal
     * composition validator on the underlying `$ref` branch. Both generated class names carry a
     * uniqid suffix and are normalised to a stable token.
     */
    public function testRootLevelAllOfOfImpliedObjectDefinitionsTransfersPropertiesAndPromotesRequired(): void
    {
        $className = $this->generateClassFromFile(
            'RootLevelAllOfImpliedRequiredPromotion.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        // both definitions' properties are transferred onto the generated root class, and a
        // property required by the branch that contributes it is promoted to non-nullable
        $object = new $className(['name' => 'Hannes', 'age' => 42]);
        $this->assertSame('Hannes', $object->getName());
        $this->assertSame(42, $object->getAge());

        $this->assertSame(['int', 'null'], $this->getReturnTypeNames($className, 'getAge'));
        $this->assertSame(['string'], $this->getReturnTypeNames($className, 'getName'));

        // the promoted requirement is still enforced at runtime
        try {
            new $className(['age' => 42]);
            $this->fail('Expected an exception for the missing required name');
        } catch (ErrorRegistryException $exception) {
            // The failing composition IS the schema root (a root-level allOf), so its own class is
            // used bare; the "identification" branch that actually rejects the value is a nested
            // class named after its $ref definition.
            $this->assertCompositionExceptionMessage(
                <<<'ERROR'
                Invalid value for '%rootClass%' declined by composition constraint
                  Requires to match all composition elements but matched 1 element
                  - Composition element #1: Failed
                    * Invalid value for '%class__Identification%' declined by composition constraint
                      Requires to match all composition elements but matched 0 elements
                      - Composition element #1: Failed
                        * Missing required value for 'name'
                        * Invalid type for 'name': requires 'string', got 'NULL'
                  - Composition element #2: Valid
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * A schema dependency whose value is a single-level $ref to an explicit `type: object`
     * definition must enforce that definition's constraints (P3.5 consumer sweep,
     * SchemaDependencyValidator's own ObjectInstantiationDecorator wiring). Baseline for
     * testDependencyWithMultiLevelImpliedObjectEnforcesConstraints below, which pins a gap in the
     * same mechanism for a multi-level composition-implied-object definition.
     */
    public function testDependencyWithSingleLevelImpliedObjectEnforcesConstraints(): void
    {
        $className = $this->generateClassFromFile('DependenciesImpliedObjectSimple.json');

        $this->expectException(InvalidSchemaDependencyException::class);
        $this->expectExceptionMessage(
            <<<'ERROR'
            Invalid schema which is dependant on 'creditCard':
              - Missing required value for 'name'
            ERROR,
        );

        new $className(['creditCard' => '1234']);
    }

    /**
     * A schema dependency whose value is a MULTI-LEVEL composition-implied object ($ref to an
     * allOf-only definition whose own allOf branch is itself a $ref to another allOf-only
     * definition) must enforce that definition's `required`/type constraints, exactly like the
     * single-level case (see testDependencyWithSingleLevelImpliedObjectEnforcesConstraints above).
     *
     * PropertiesValidatorFactory::addDependencyValidator() builds the dependency's own class via
     * PropertyFactory::processBaseReference() (a bare `{"$ref": ...}` at base level). A
     * multi-level implied-object $ref target enforces its constraints via a composition validator
     * on its OWN schema, not via validators on its individual properties - those are merged/
     * redirected and carry no validation of their own, exactly like transferComposedPropertiesToSchema()
     * documents for the equivalent case where the composition sits directly on the class instead
     * of behind a $ref. processBaseReference() transferred the resolved definition's *properties*
     * onto the dependency's schema but not its *base validators*, so the composition validator
     * that actually enforces `required` never ran for the dependency's generated class.
     */
    public function testDependencyWithMultiLevelImpliedObjectEnforcesConstraints(): void
    {
        $className = $this->generateClassFromFile('DependenciesImpliedObject.json');

        $this->expectException(InvalidSchemaDependencyException::class);

        new $className(['creditCard' => '1234']);
    }

    /**
     * The same gap affects any base-level `$ref` to a multi-level composition-implied object, not
     * just schema dependencies - e.g. a schema file whose entire top level is `{"$ref": ...}`. This
     * mirrors the array-item and root-level-allOf coverage above but for the bare base-level $ref
     * path (PropertyFactory::processBaseReference()), which is a separate code path from both.
     */
    public function testRootLevelReferenceToMultiLevelImpliedObjectInstantiatesAndValidates(): void
    {
        $className = $this->generateClassFromFile('RootLevelRefToMultiLevelImpliedObject.json');

        $object = new $className(['name' => 'Hannes']);
        $this->assertSame('Hannes', $object->getName());

        try {
            new $className([]);
            $this->fail('Expected an exception for the missing required name');
        } catch (AllOfException $exception) {
            // The bare base-level $ref resolves to the "extraRequirements" definition, which
            // itself allOf-wraps a $ref to "identification" - both nested classes are named after
            // their respective $ref definitions.
            $this->assertCompositionExceptionMessage(
                <<<'ERROR'
                Invalid value for '%class__ExtraRequirements%' declined by composition constraint
                  Requires to match all composition elements but matched 0 elements
                  - Composition element #1: Failed
                    * Invalid value for '%class__Identification%' declined by composition constraint
                      Requires to match all composition elements but matched 0 elements
                      - Composition element #1: Failed
                        * Missing required value for 'name'
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * An `allOf` mixing an object-DESCRIBING branch (bare `properties`/`required`, no `type`) with
     * a scalar branch must NOT be rejected as conflicting at generation time, unlike the
     * object-ASSERTING case above: a describing branch is vacuously satisfied by non-object
     * values, so a string can satisfy both the describing branch (vacuously) and the scalar
     * branch (directly) simultaneously - the schema is satisfiable by strings, even though no
     * object ever satisfies the scalar branch's own type constraint. The same mixed allOf must
     * still reject a value that matches neither branch - an object fails the scalar branch's
     * type check even where it would vacuously satisfy the describing branch, so it is rejected
     * by the ordinary allOf composition validator at runtime, not by a generation-time conflict
     * diagnostic.
     */
    public function testAllOfMixingImpliedDescribingBranchAndScalarBranchAcceptsSatisfyingValueAndRejectsOthers(): void
    {
        $className = $this->generateClassFromFile('AllOfDescribingPlusScalar.json');

        // a string satisfies both the describing branch (vacuously) and the scalar branch (directly)
        $object = new $className(['p' => 'hello']);
        $this->assertSame('hello', $object->getP());

        // a value matching neither branch (an object fails the scalar branch's type check) is rejected
        try {
            new $className(['p' => []]);
            $this->fail('Expected an AllOfException for the value matching neither branch');
        } catch (AllOfException $exception) {
            $this->assertSame(
                <<<'ERROR'
                Invalid value for 'p' declined by composition constraint
                  Requires to match all composition elements but matched 0 elements
                  - Composition element #1: Failed
                    * Missing required value for 'name'
                  - Composition element #2: Failed
                    * Invalid type for 'p': requires 'string', got 'array'
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * The vacuous-branch warning must be driven by the Draft's own registered validator keywords,
     * not a hardcoded list of "known safe"
     * keywords - otherwise an unrecognized or misspelled key would wrongly be treated as a real
     * constraint merely because nobody anticipated it, silently defeating the warning for exactly
     * the schemas it is meant to catch. A branch containing only an unrecognized key must warn,
     * while branches containing only "const" or "type" - both registered on their Type via
     * addModifier() rather than addValidator() in Draft_07, so invisible to
     * Draft::getTypesForKeyword() - must not, since both are genuine constraints.
     */
    public function testVacuousBranchWarningIsDrivenByRegisteredValidatorsNotAHardcodedList(): void
    {
        $recordingLogger = new RecordingLogger();

        $this->generateClassFromFile(
            'VacuousBranchWarning.json',
            (new GeneratorConfiguration())->setLogger($recordingLogger),
        );

        $entries = $recordingLogger->getEntries();

        $this->assertTrue(
            $this->hasLogEntry(
                $entries,
                'warning',
                "Composition branch #{index} for '{property}' carries no validation keyword and"
                    . ' matches any value',
                ['index' => 1, 'property' => 'unknownKeyBranch'],
            ),
            'Expected a vacuous-branch warning for the unknownKeyBranch property.',
        );

        $this->assertFalse(
            $this->hasLogEntry(
                $entries,
                'warning',
                "Composition branch #{index} for '{property}' carries no validation keyword and"
                    . ' matches any value',
                ['property' => 'constOnlyBranch'],
            ),
            'A branch containing only "const" must not be treated as vacuous.',
        );

        $this->assertFalse(
            $this->hasLogEntry(
                $entries,
                'warning',
                "Composition branch #{index} for '{property}' carries no validation keyword and"
                    . ' matches any value',
                ['property' => 'typeOnlyBranch'],
            ),
            'A branch containing only "type" must not be treated as vacuous.',
        );
    }

    /**
     * A ROOT-LEVEL oneOf (the composition IS the file's own class-defining schema, not one
     * nested inside a named property) with a branch that has neither a type nor a nested schema -
     * the canonical "matches any value" shape, expressed here as a literal `true` schema element -
     * used to crash generation with "No nested schema for composed property", then (once that was
     * fixed) silently generated a class anyway, accepting the vacuous branch as an implicit
     * object. Neither is correct: a `true` branch matches non-object values too, so the class this
     * generator would produce could never be instantiated for every value the schema itself
     * accepts. Generation must reject this schema instead of producing a misleadingly narrow (or
     * outright wrong) class.
     */
    public function testRootLevelOneOfWithVacuousBranchIsRejectedAsNonRepresentable(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Composition for '.*' in file '.*\\.json' does not resolve to a definite object and cannot be"
                . ' represented as a generated class/',
        );

        $this->generateClassFromFile('RootLevelOneOfWithVacuousBranch.json');
    }

    /**
     * The vacuous branch above is rejected before generation ever reaches
     * SchemaProcessor::transferComposedPropertiesToSchema() - checkObjectRepresentability() sees
     * the ambiguous root composition first. An explicit `"type": "object"` on the root schema
     * short-circuits that check to ObjectAsserting regardless of what its branches declare (the
     * explicit type is the assertion), so a genuinely vacuous branch - a literal `true` composition
     * element, which inheritPropertyType() deliberately never injects a type into - still reaches
     * transferComposedPropertiesToSchema()'s "neither a nested schema nor an explicit type"
     * handling. It contributes no properties and does not throw, but (like the untyped-root case
     * above) is not deduplicated during validation: a value matching the object branch also
     * vacuously matches the `true` branch, so it is rejected for matching two composition
     * elements instead of one.
     */
    public function testRootLevelOneOfWithVacuousBranchAndExplicitTypeAcceptsOnlyValuesNotMatchingTheOtherBranch(): void
    {
        $className = $this->generateClassFromFile('RootLevelOneOfWithVacuousBranchAndExplicitType.json');

        // Does not satisfy the object branch (missing required 'name'), so it matches only the
        // vacuous branch - exactly one match, valid.
        $object = new $className([]);
        $this->assertNull($object->getName());

        // Matches the object branch AND the vacuous branch (which matches unconditionally) -
        // two matches violates oneOf's "exactly one" requirement.
        try {
            new $className(['name' => 'Hannes']);
            $this->fail('Expected a OneOfException for the value matching both branches');
        } catch (OneOfException $exception) {
            // The failing composition IS the schema root (a root-level oneOf with an explicit
            // `type: object`), so its own class is used bare.
            $this->assertCompositionExceptionMessage(
                <<<'ERROR'
                Invalid value for '%rootClass%' declined by composition constraint
                  Requires to match one composition element but matched 2 elements
                  - Composition element #1: Valid
                  - Composition element #2: Valid
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * PropertyFactory::create()'s allOf-without-its-own-type reroute peeks through $ref chains
     * (via ObjectShapeResolver) to decide whether it re-routes through the object path. The
     * peek's $ref resolver must not let an unresolvable reference escape as an uncaught
     * Throwable - the peek is a speculative, side-effect-free classification, not the real
     * reference resolution, so it must fail conservatively (blocking the re-route) and leave the
     * genuinely broken reference to be reported by the real $ref resolution that runs afterward
     * once the schema falls through to ordinary composition processing.
     */
    public function testAllOfWithUnresolvableReferenceDuringObjectShapeClassificationStillThrowsCleanly(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '~^Unresolved Reference #/definitions/DoesNotExist in file (.*)\.json$~',
        );

        $this->generateClassFromFile('AllOfWithUnresolvableReference.json');
    }
}
