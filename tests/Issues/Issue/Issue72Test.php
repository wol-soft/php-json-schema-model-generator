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
     * implied item (an allOf of explicit object branches).
     */
    public function testMultiLevelImpliedObjectArrayItemsInstantiateAndValidate(): void
    {
        $className = $this->generateClassFromFile('NestedAllOfInArrayItems.json');

        $object = new $className(['members' => [['name' => 'Hannes', 'salary' => 10000]]]);
        $members = $object->getMembers();
        $this->assertCount(1, $members);
        $this->assertIsObject($members[0]);
        $this->assertSame('Hannes', $members[0]->getName());
        $this->assertSame(10000, $members[0]->getSalary());
    }

    /**
     * The same array must reject items violating the implied definition's constraints.
     */
    public function testMultiLevelImpliedObjectArrayItemsRejectInvalidItem(): void
    {
        $className = $this->generateClassFromFile('NestedAllOfInArrayItems.json');

        try {
            new $className(['members' => [['salary' => 10000]]]);
            $this->fail('Expected an InvalidItemException for the item violating the implied definition');
        } catch (InvalidItemException $exception) {
            // The item references a multi-level composition-implied definition, so the failure is a
            // two-level nested composition error; direct-exception mode surfaces the leaf reason at
            // the bottom. Both generated class names are normalised to a stable token.
            $this->assertSame(
                <<<'ERROR'
                Invalid items in array 'members':
                  - invalid item #0
                    * Invalid value for '<class>' declined by composition constraint
                      Requires to match all composition elements but matched 1 element
                      - Composition element #1: Failed
                        * Invalid value for '<class>' declined by composition constraint
                          Requires to match all composition elements but matched 0 elements
                          - Composition element #1: Failed
                            * Missing required value for 'name'
                      - Composition element #2: Valid
                ERROR,
                $this->normalizeCompositionClassNames($exception->getMessage()),
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
     * object exposing getters for the matched properties.
     */
    #[DataProvider('impliedAnyOfSchemaDataProvider')]
    public function testAnyOfWithImpliedObjectBranchesInstantiatesMatchingValue(string $schemaFile): void
    {
        $className = $this->generateClassFromFile($schemaFile);

        $personMatch = new $className(['p' => ['name' => 'Hannes', 'salary' => 10000]]);
        $person = $personMatch->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());
        $this->assertSame(10000, $person->getSalary());

        $agedMatch = new $className(['p' => ['age' => 42]]);
        $aged = $agedMatch->getP();
        $this->assertIsObject($aged);
        $this->assertSame(42, $aged->getAge());
    }

    public static function impliedAnyOfSchemaDataProvider(): array
    {
        return [
            '$ref branches' => ['NestedAnyOf.json'],
            'inline branches' => ['NestedAnyOfInline.json'],
        ];
    }

    /**
     * The same `anyOf` must reject values matching no branch - like the explicit-object
     * equivalent does.
     */
    #[DataProvider('anyOfNonMatchingValueDataProvider')]
    public function testAnyOfWithImpliedObjectBranchesRejectsNonMatchingValue(
        string $schemaFile,
        array|int $nonMatchingValue,
    ): void {
        $this->expectException(AnyOfException::class);
        $this->expectExceptionMessage(
            <<<'ERROR'
            Invalid value for 'p' declined by composition constraint
              Requires to match at least one composition element
            ERROR,
        );

        $className = $this->generateClassFromFile($schemaFile);

        new $className(['p' => $nonMatchingValue]);
    }

    public static function anyOfNonMatchingValueDataProvider(): array
    {
        $nonMatchingValues = [
            'object matching no branch' => [],
            'scalar value' => 42,
        ];

        $cases = [];
        foreach (self::impliedAnyOfSchemaDataProvider() as $schemaLabel => [$schemaFile]) {
            foreach ($nonMatchingValues as $valueLabel => $nonMatchingValue) {
                $cases["$schemaLabel - $valueLabel"] = [$schemaFile, $nonMatchingValue];
            }
        }

        return $cases;
    }

    /**
     * A `oneOf` whose branches are composition-implied objects ($ref and inline variants) must
     * accept a value matching exactly one branch and instantiate it as that branch's object -
     * like the explicit-object equivalent, which returns the matched branch's class instance.
     */
    #[DataProvider('impliedOneOfSchemaDataProvider')]
    public function testOneOfWithImpliedObjectBranchesInstantiatesMatchingValue(string $schemaFile): void
    {
        $className = $this->generateClassFromFile($schemaFile);

        $object = new $className(['p' => ['name' => 'Hannes']]);
        $person = $object->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());
    }

    public static function impliedOneOfSchemaDataProvider(): array
    {
        return [
            '$ref branches' => ['NestedOneOf.json'],
            'inline branches' => ['NestedOneOfInline.json'],
        ];
    }

    /**
     * The same `oneOf` must reject values matching both branches or neither branch.
     */
    #[DataProvider('oneOfNonMatchingValueDataProvider')]
    public function testOneOfWithImpliedObjectBranchesRejectsNonMatchingValue(
        string $schemaFile,
        array $nonMatchingValue,
        int $expectedMatchedElements,
    ): void {
        $this->expectException(OneOfException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid value for 'p' declined by composition constraint
              Requires to match one composition element but matched $expectedMatchedElements elements
            ERROR,
        );

        $className = $this->generateClassFromFile($schemaFile);

        new $className(['p' => $nonMatchingValue]);
    }

    public static function oneOfNonMatchingValueDataProvider(): array
    {
        $nonMatchingValues = [
            'matches both branches' => [['name' => 'Hannes', 'companyName' => 'ACME'], 2],
            'matches neither branch' => [[], 0],
        ];

        $cases = [];
        foreach (self::impliedOneOfSchemaDataProvider() as $schemaLabel => [$schemaFile]) {
            foreach ($nonMatchingValues as $valueLabel => [$nonMatchingValue, $expectedMatchedElements]) {
                $cases["$schemaLabel - $valueLabel"] = [$schemaFile, $nonMatchingValue, $expectedMatchedElements];
            }
        }

        return $cases;
    }

    /**
     * An if/then/else whose then/else branches are composition-implied objects ($ref and inline
     * variants) must validate and instantiate the taken branch - like the explicit-object
     * equivalent, which returns the taken branch's class instance.
     */
    #[DataProvider('impliedIfThenElseSchemaDataProvider')]
    public function testIfThenElseWithImpliedObjectBranchesInstantiatesMatchingValue(string $schemaFile): void
    {
        $className = $this->generateClassFromFile($schemaFile);

        $thenMatch = new $className(['p' => ['isPerson' => true, 'name' => 'Hannes']]);
        $person = $thenMatch->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());

        $elseMatch = new $className(['p' => ['companyName' => 'ACME']]);
        $company = $elseMatch->getP();
        $this->assertIsObject($company);
        $this->assertSame('ACME', $company->getCompanyName());
    }

    public static function impliedIfThenElseSchemaDataProvider(): array
    {
        return [
            '$ref branches' => ['NestedIfThenElse.json'],
            'inline branches' => ['NestedIfThenElseInline.json'],
        ];
    }

    /**
     * The same if/then/else must reject a value that satisfies the condition but violates the
     * then branch's constraints.
     */
    #[DataProvider('impliedIfThenElseSchemaDataProvider')]
    public function testIfThenElseWithImpliedObjectBranchesRejectsValueViolatingTakenBranch(
        string $schemaFile,
    ): void {
        $className = $this->generateClassFromFile($schemaFile);

        try {
            new $className(['p' => ['isPerson' => true, 'companyName' => 'ACME']]);
            $this->fail('Expected a ConditionalException for the value violating the taken branch');
        } catch (ConditionalException $exception) {
            // The then-branch is a composition-implied object, so the taken-branch failure is
            // reported as a nested composition error; direct-exception mode surfaces the underlying
            // leaf reason ("Missing required value for name"). The nested class name carries a
            // uniqid suffix and is normalised to a stable token.
            $this->assertSame(
                <<<'ERROR'
                Invalid value for 'p' declined by conditional composition constraint
                  - Condition: Valid
                  - Conditional branch failed:
                    * Invalid value for '<class>' declined by composition constraint
                      Requires to match all composition elements but matched 0 elements
                      - Composition element #1: Failed
                        * Missing required value for 'name'
                ERROR,
                $this->normalizeCompositionClassNames($exception->getMessage()),
            );
        }
    }

    /**
     * Normalise generated nested-class names inside a composition error message to a stable token.
     * A re-routed composition-implied object branch is validated through a generated class whose
     * name carries a uniqid suffix, so the "Invalid value for <ClassName> declined by composition
     * constraint" fragment cannot be asserted verbatim. The outer conditional wrapper ("declined
     * by conditional composition constraint") is deliberately left untouched by the pattern.
     */
    private function normalizeCompositionClassNames(string $message): string
    {
        return preg_replace(
            "/Invalid value for '\w+' declined by composition constraint/",
            "Invalid value for '<class>' declined by composition constraint",
            $message,
        );
    }

    /**
     * A `not` with a composition-implied object schema ($ref and inline variants) must accept
     * values not matching the forbidden schema. Unlike the other composition keywords, the value
     * legitimately stays a raw array - `not` describes what the value must NOT be, so no class
     * represents it; verified against the explicit-object equivalent.
     */
    #[DataProvider('impliedNotSchemaDataProvider')]
    public function testNotWithImpliedObjectSchemaAcceptsNonMatchingValue(string $schemaFile): void
    {
        $className = $this->generateClassFromFile($schemaFile);

        $object = new $className(['p' => ['name' => 'Hannes']]);

        $this->assertSame(['name' => 'Hannes'], $object->getP());
    }

    public static function impliedNotSchemaDataProvider(): array
    {
        return [
            '$ref forbidden schema' => ['NestedNot.json'],
            'inline forbidden schema' => ['NestedNotInline.json'],
        ];
    }

    /**
     * The same `not` must reject values matching the forbidden schema.
     */
    #[DataProvider('impliedNotSchemaDataProvider')]
    public function testNotWithImpliedObjectSchemaRejectsMatchingValue(string $schemaFile): void
    {
        $this->expectException(NotException::class);
        $this->expectExceptionMessage(
            <<<'ERROR'
            Invalid value for 'p' declined by composition constraint
              Requires to match none composition element but matched 1 element
            ERROR,
        );

        $className = $this->generateClassFromFile($schemaFile);

        new $className(['p' => ['password' => 'secret']]);
    }

    /**
     * A mixed `anyOf` combining a composition-implied object branch with a scalar branch must
     * behave exactly like its explicit-object equivalent: an object matching the implied branch
     * is instantiated, a string takes the scalar branch unchanged.
     */
    public function testAnyOfMixingImpliedObjectAndScalarBranchBehavesLikeExplicitEquivalent(): void
    {
        $className = $this->generateClassFromFile('NestedAnyOfMixedScalar.json');

        $objectMatch = new $className(['p' => ['name' => 'Hannes']]);
        $person = $objectMatch->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());

        $stringMatch = new $className(['p' => 'hello']);
        $this->assertSame('hello', $stringMatch->getP());
    }

    /**
     * The same mixed `anyOf` must reject values matching neither the implied object branch nor
     * the scalar branch.
     */
    #[DataProvider('mixedAnyOfNonMatchingValueDataProvider')]
    public function testAnyOfMixingImpliedObjectAndScalarBranchRejectsNonMatchingValue(
        array|int $nonMatchingValue,
    ): void {
        $this->expectException(AnyOfException::class);
        $this->expectExceptionMessage(
            <<<'ERROR'
            Invalid value for 'p' declined by composition constraint
              Requires to match at least one composition element
            ERROR,
        );

        $className = $this->generateClassFromFile('NestedAnyOfMixedScalar.json');

        new $className(['p' => $nonMatchingValue]);
    }

    public static function mixedAnyOfNonMatchingValueDataProvider(): array
    {
        return [
            'integer matching no branch' => [42],
            'object matching no branch' => [[]],
        ];
    }

    /**
     * A mixed `oneOf` combining a composition-implied object branch with a scalar branch must
     * behave exactly like its explicit-object equivalent: an object matching the implied branch
     * is instantiated, a string matching only the scalar branch is accepted unchanged.
     */
    public function testOneOfMixingImpliedObjectAndScalarBranchBehavesLikeExplicitEquivalent(): void
    {
        $className = $this->generateClassFromFile('NestedOneOfMixedScalar.json');

        $objectMatch = new $className(['p' => ['name' => 'Hannes']]);
        $person = $objectMatch->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());

        $stringMatch = new $className(['p' => 'hello']);
        $this->assertSame('hello', $stringMatch->getP());
    }

    /**
     * The same mixed `oneOf` must reject values matching neither branch.
     */
    public function testOneOfMixingImpliedObjectAndScalarBranchRejectsNonMatchingValue(): void
    {
        $this->expectException(OneOfException::class);
        $this->expectExceptionMessage(
            <<<'ERROR'
            Invalid value for 'p' declined by composition constraint
              Requires to match one composition element but matched 0 elements
            ERROR,
        );

        $className = $this->generateClassFromFile('NestedOneOfMixedScalar.json');

        new $className(['p' => 42]);
    }

    /**
     * A mixed if/then/else with a composition-implied object then-branch and a scalar
     * else-branch must behave exactly like its explicit-object equivalent: objects are routed
     * into the then-branch and instantiated, non-objects into the scalar else-branch.
     */
    public function testIfThenElseMixingImpliedObjectThenAndScalarElseBehavesLikeExplicitEquivalent(): void
    {
        $className = $this->generateClassFromFile('NestedIfThenElseMixedScalar.json');

        $thenMatch = new $className(['p' => ['name' => 'Hannes']]);
        $person = $thenMatch->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());

        $elseMatch = new $className(['p' => 'hello']);
        $this->assertSame('hello', $elseMatch->getP());
    }

    /**
     * The same mixed if/then/else must reject an object violating the implied then-branch.
     */
    public function testIfThenElseMixingImpliedObjectThenAndScalarElseRejectsValueViolatingThenBranch(): void
    {
        $className = $this->generateClassFromFile('NestedIfThenElseMixedScalar.json');

        try {
            new $className(['p' => []]);
            $this->fail('Expected a ConditionalException for the object violating the then branch');
        } catch (ConditionalException $exception) {
            $this->assertSame(
                <<<'ERROR'
                Invalid value for 'p' declined by conditional composition constraint
                  - Condition: Valid
                  - Conditional branch failed:
                    * Invalid value for '<class>' declined by composition constraint
                      Requires to match all composition elements but matched 0 elements
                      - Composition element #1: Failed
                        * Missing required value for 'name'
                ERROR,
                $this->normalizeCompositionClassNames($exception->getMessage()),
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
     * semantics (a non-object matches no branch) - only the failure reason differs.
     */
    public function testOneOfWithBareObjectValidatorBranchesInstantiatesMatchingValue(): void
    {
        $className = $this->generateClassFromFile('NestedOneOfBareObjectValidators.json');

        $object = new $className(['p' => ['name' => 'Hannes']]);
        $person = $object->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());
    }

    /**
     * The same bare-validator `oneOf` must reject values whose outcome is identical under strict
     * spec and object-implied semantics: objects matching both branches, objects matching
     * neither, and non-objects. The expected matched-counts follow the strict-spec reading
     * (`required`/`properties` constrain only objects): a non-object matches both bare branches
     * vacuously and is rejected for matching 2 elements, not 0.
     */
    #[DataProvider('bareOneOfNonMatchingValueDataProvider')]
    public function testOneOfWithBareObjectValidatorBranchesRejectsNonMatchingValue(
        array|int $nonMatchingValue,
        int $expectedMatchedElements,
    ): void {
        $this->expectException(OneOfException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Invalid value for 'p' declined by composition constraint
              Requires to match one composition element but matched $expectedMatchedElements elements
            ERROR,
        );

        $className = $this->generateClassFromFile('NestedOneOfBareObjectValidators.json');

        new $className(['p' => $nonMatchingValue]);
    }

    public static function bareOneOfNonMatchingValueDataProvider(): array
    {
        return [
            'object matching both branches' => [['name' => 'Hannes', 'companyName' => 'ACME'], 2],
            'object matching neither branch' => [[], 0],
            'non-object matching both vacuously' => [42, 2],
        ];
    }

    /**
     * An `anyOf` whose branches carry only object validators must accept an object matching a
     * branch and reject an object matching no branch - outcomes on which strict spec and
     * object-implied semantics agree (`required` does constrain objects, so an empty object
     * fails both branches). The divergent case - non-object values, which strict spec accepts
     * via vacuous branch matches but object-implied semantics reject - is intentionally NOT
     * covered here; its intended behavior is an open design decision.
     */
    public function testAnyOfWithBareObjectValidatorBranchesValidatesObjectValues(): void
    {
        $className = $this->generateClassFromFile('NestedAnyOfBareObjectValidators.json');

        $object = new $className(['p' => ['name' => 'Hannes']]);
        $person = $object->getP();
        $this->assertIsObject($person);
        $this->assertSame('Hannes', $person->getName());
    }

    /**
     * The same bare-validator `anyOf` must reject an object matching no branch.
     */
    public function testAnyOfWithBareObjectValidatorBranchesRejectsObjectMatchingNoBranch(): void
    {
        $this->expectException(AnyOfException::class);
        $this->expectExceptionMessage(
            <<<'ERROR'
            Invalid value for 'p' declined by composition constraint
              Requires to match at least one composition element
            ERROR,
        );

        $className = $this->generateClassFromFile('NestedAnyOfBareObjectValidators.json');

        new $className(['p' => []]);
    }

    /**
     * A NON-object value in a bare-validator `anyOf` must be ACCEPTED, following strict JSON
     * Schema semantics: `properties`/`required` only constrain objects, so a non-object matches
     * every bare branch vacuously and satisfies the anyOf. Treating the bare branches as
     * object-implied (rejecting `42`) was considered and rejected - overriding spec semantics
     * must remain a narrowly whitelisted opt-in, and authors who mean objects can declare
     * `type: object`. This deliberately differs from the oneOf case, where a non-object's
     * vacuous match on BOTH branches violates "exactly one" and is rejected under the same
     * strict-spec reading.
     */
    public function testAnyOfWithBareObjectValidatorBranchesAcceptsNonObjectValuePerSpec(): void
    {
        $className = $this->generateClassFromFile('NestedAnyOfBareObjectValidators.json');

        $object = new $className(['p' => 42]);

        $this->assertSame(42, $object->getP());
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
     * non-nullable when it is required by the branch that contributes it (P3.5 consumer sweep:
     * required-promotion + property transfer for a re-routed root-level composition).
     */
    public function testRootLevelAllOfOfImpliedObjectDefinitionsTransfersPropertiesAndPromotesRequired(): void
    {
        $className = $this->generateClassFromFile(
            'RootLevelAllOfImpliedRequiredPromotion.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['name' => 'Hannes', 'age' => 42]);
        $this->assertSame('Hannes', $object->getName());
        $this->assertSame(42, $object->getAge());

        $this->assertSame(['int', 'null'], $this->getReturnTypeNames($className, 'getAge'));
        $this->assertSame(['string'], $this->getReturnTypeNames($className, 'getName'));
    }

    /**
     * The same root-level composition must still enforce the promoted requirement at runtime -
     * required-promotion only changes the getter's type hint (see the test above); the actual
     * rejection still comes from the normal composition validator on the underlying `$ref`
     * branch. Both generated class names carry a uniqid suffix and are normalised to a stable
     * token.
     */
    public function testRootLevelAllOfOfImpliedObjectDefinitionsRejectsMissingRequiredProperty(): void
    {
        $className = $this->generateClassFromFile(
            'RootLevelAllOfImpliedRequiredPromotion.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        try {
            new $className(['age' => 42]);
            $this->fail('Expected an exception for the missing required name');
        } catch (ErrorRegistryException $exception) {
            $this->assertSame(
                <<<'ERROR'
                Invalid value for '<class>' declined by composition constraint
                  Requires to match all composition elements but matched 1 element
                  - Composition element #1: Failed
                    * Invalid value for '<class>' declined by composition constraint
                      Requires to match all composition elements but matched 0 elements
                      - Composition element #1: Failed
                        * Missing required value for 'name'
                        * Invalid type for 'name': requires 'string', got 'NULL'
                  - Composition element #2: Valid
                ERROR,
                $this->normalizeCompositionClassNames($exception->getMessage()),
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
            $this->assertSame(
                <<<'ERROR'
                Invalid value for '<class>' declined by composition constraint
                  Requires to match all composition elements but matched 0 elements
                  - Composition element #1: Failed
                    * Invalid value for '<class>' declined by composition constraint
                      Requires to match all composition elements but matched 0 elements
                      - Composition element #1: Failed
                        * Missing required value for 'name'
                ERROR,
                $this->normalizeCompositionClassNames($exception->getMessage()),
            );
        }
    }

    /**
     * An `allOf` mixing an object-DESCRIBING branch (bare `properties`/`required`, no `type`) with
     * a scalar branch must NOT be rejected as conflicting at generation time, unlike the
     * object-ASSERTING case above: a describing branch is vacuously satisfied by non-object
     * values, so a string can satisfy both the describing branch (vacuously) and the scalar
     * branch (directly) simultaneously - the schema is satisfiable by strings, even though no
     * object ever satisfies the scalar branch's own type constraint.
     */
    public function testAllOfMixingImpliedDescribingBranchAndScalarBranchAcceptsSatisfyingValue(): void
    {
        $className = $this->generateClassFromFile('AllOfDescribingPlusScalar.json');

        $object = new $className(['p' => 'hello']);
        $this->assertSame('hello', $object->getP());
    }

    /**
     * The same mixed allOf must still reject a value that matches neither branch - an object
     * fails the scalar branch's type check even where it would vacuously satisfy the describing
     * branch, so it is rejected by the ordinary allOf composition validator at runtime, not by a
     * generation-time conflict diagnostic.
     */
    public function testAllOfMixingImpliedDescribingBranchAndScalarBranchRejectsNonMatchingValue(): void
    {
        $this->expectException(AllOfException::class);
        $this->expectExceptionMessage(
            <<<'ERROR'
            Invalid value for 'p' declined by composition constraint
              Requires to match all composition elements but matched 0 elements
              - Composition element #1: Failed
                * Missing required value for 'name'
              - Composition element #2: Failed
                * Invalid type for 'p': requires 'string', got 'array'
            ERROR,
        );

        $className = $this->generateClassFromFile('AllOfDescribingPlusScalar.json');

        new $className(['p' => []]);
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
}
