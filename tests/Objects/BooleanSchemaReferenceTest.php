<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\ComposedValue\NotException;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\Generic\DeniedPropertyException;
use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Fixtures\RecordingLogger;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A `$ref` to a boolean-valued definition (`"definitions": {"yes": true}`) is a schema like the inline boolean at the
 * same position: `true` accepts every value, `false` accepts no value.
 */
#[ApplicableDrafts]
class BooleanSchemaReferenceTest extends AbstractPHPModelGeneratorTestCase
{
    protected const EXTERNAL_JSON_DIRECTORIES = ['../BooleanSchemaReferenceTest_external'];

    /**
     * Every position a schema can appear at is covered by one class: a named property, the branches of the
     * compositions, the condition and the branches of if/then/else, array items and additional properties. The
     * references used to fail with a TypeError during the generation, as the boolean can't be held as the decoded JSON
     * object of the referenced schema.
     */
    public function testReferencesToBooleanSchemasBehaveLikeTheInlineBooleanSchemas(): void
    {
        $logger = new RecordingLogger();
        $className = $this->generateClassFromFile(
            'PropertySites.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setLogger($logger),
        );

        // A named property: true accepts every value, false none. The property of false is optional, and an absent
        // value is no value.
        foreach (['text', 5, [1, 2], ['key' => 'value']] as $value) {
            $this->assertSame($value, (new $className(['anything' => $value]))->getAnything());
        }

        $this->assertNull((new $className([]))->getNothing());
        $this->assertNull((new $className(['nothing' => null]))->getNothing());
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['nothing' => 'text'],
            DeniedPropertyException::class,
            "Value for 'nothing' is not allowed",
        );

        // anyOf
        $this->assertSame(5, (new $className(['stringOrAnything' => 5]))->getStringOrAnything());
        $this->assertSame('x', (new $className(['stringOrNothing' => 'x']))->getStringOrNothing());
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['stringOrNothing' => 5],
            AnyOfException::class,
            <<<MSG
            Invalid value for 'stringOrNothing' declined by composition constraint
              Requires to match at least one composition element
              - Composition element #1: Failed
                * Invalid type for 'stringOrNothing': requires 'string', got 'integer'
              - Composition element #2: Failed
                * Value for 'stringOrNothing' is not allowed
            MSG,
        );

        // allOf
        $this->assertSame('ab', (new $className(['stringAndAnything' => 'ab']))->getStringAndAnything());
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['stringAndAnything' => 'a'],
            AllOfException::class,
            <<<MSG
            Invalid value for 'stringAndAnything' declined by composition constraint
              Requires to match all composition elements but matched 1 element
              - Composition element #1: Failed
                * Value for 'stringAndAnything' must not be shorter than 2
              - Composition element #2: Valid
            MSG,
        );
        $this->assertNull((new $className([]))->getStringAndNothing());
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['stringAndNothing' => 'x'],
            AllOfException::class,
            <<<MSG
            Invalid value for 'stringAndNothing' declined by composition constraint
              Requires to match all composition elements but matched 1 element
              - Composition element #1: Valid
              - Composition element #2: Failed
                * Value for 'stringAndNothing' is not allowed
            MSG,
        );

        // oneOf
        $this->assertSame(
            5,
            (new $className(['exactlyOneOfStringAndAnything' => 5]))->getExactlyOneOfStringAndAnything(),
        );
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['exactlyOneOfStringAndAnything' => 'x'],
            OneOfException::class,
            <<<MSG
            Invalid value for 'exactlyOneOfStringAndAnything' declined by composition constraint
              Requires to match one composition element but matched 2 elements
              - Composition element #1: Valid
              - Composition element #2: Valid
            MSG,
        );
        $this->assertSame(
            'x',
            (new $className(['exactlyOneOfStringAndNothing' => 'x']))->getExactlyOneOfStringAndNothing(),
        );
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['exactlyOneOfStringAndNothing' => 5],
            OneOfException::class,
            <<<MSG
            Invalid value for 'exactlyOneOfStringAndNothing' declined by composition constraint
              Requires to match one composition element but matched 0 elements
              - Composition element #1: Failed
                * Invalid type for 'exactlyOneOfStringAndNothing': requires 'string', got 'integer'
              - Composition element #2: Failed
                * Value for 'exactlyOneOfStringAndNothing' is not allowed
            MSG,
        );

        // not: the negation of false accepts every value. The negation of true is covered by
        // testNegatedReferenceToTrueIsNeverValid.
        $this->assertSame('x', (new $className(['notNothing' => 'x']))->getNotNothing());

        // if/then/else
        $this->assertSame('abc', (new $className(['conditionOnAnything' => 'abc']))->getConditionOnAnything());
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['conditionOnAnything' => 'ab'],
            ConditionalException::class,
            <<<MSG
            Invalid value for 'conditionOnAnything' declined by conditional composition constraint
              - Condition: Valid
              - Conditional branch failed:
                * Value for 'conditionOnAnything' must not be shorter than 3
            MSG,
        );
        $this->assertSame('abc', (new $className(['conditionOnNothing' => 'abc']))->getConditionOnNothing());
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['conditionOnNothing' => 'ab'],
            ConditionalException::class,
            <<<MSG
            Invalid value for 'conditionOnNothing' declined by conditional composition constraint
              - Condition: Failed
                * Value for 'conditionOnNothing' is not allowed
              - Conditional branch failed:
                * Value for 'conditionOnNothing' must not be shorter than 3
            MSG,
        );

        // Array items
        $this->assertSame([1, 'a', [2]], (new $className(['listOfAnything' => [1, 'a', [2]]]))->getListOfAnything());
        $this->assertSame([], (new $className(['listOfNothing' => []]))->getListOfNothing());
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['listOfNothing' => [1]],
            InvalidItemException::class,
            <<<MSG
            Invalid items in array 'listOfNothing':
              - invalid item #0
                * Value for 'listOfNothing' is not allowed
            MSG,
        );

        // Additional properties
        $this->assertSame(
            ['key' => 'value'],
            (new $className(['mapOfAnything' => ['key' => 'value']]))->getMapOfAnything()->meta()->rawInput(),
        );
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['mapOfNothing' => ['key' => 'value']],
            NestedObjectException::class,
            <<<MSG
            Invalid nested object for property 'mapOfNothing':
              - Provided JSON for '%class%' contains invalid additional properties
                  - invalid additional property 'key'
                    * Value for 'additional property' is not allowed
            MSG,
        );

        // A reference to true is as vacuous as the inline true: the branch matches any value, which is reported like it
        // is for the inline spelling.
        $this->assertSame(
            [
                ['index' => 2, 'property' => 'stringOrAnything'],
                ['index' => 2, 'property' => 'stringAndAnything'],
                ['index' => 2, 'property' => 'exactlyOneOfStringAndAnything'],
            ],
            $this->collectVacuousBranchWarnings($logger),
        );
    }

    /**
     * Without implicit null an explicit null is a value like any other, so the property of false rejects it. With
     * implicit null (the default of the test above) it is treated like an absent value, as for every other optional
     * property.
     */
    public function testReferenceToFalseRejectsAnExplicitNullWithoutImplicitNull(): void
    {
        $className = $this->generateClassFromFile('PropertySites.json', null, false, false);

        $this->assertNull((new $className([]))->getNothing());
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['nothing' => null],
            DeniedPropertyException::class,
            "Value for 'nothing' is not allowed",
        );
    }

    /**
     * A not composition evaluates an absent property like null (see ComposedNotTest::
     * testNotProvidedOptionalNotNullPropertyThrowsAnException), which the always-valid schema matches: its negation
     * is violated whether the property is provided or not.
     */
    public function testNegatedReferenceToTrueIsNeverValid(): void
    {
        $className = $this->generateClassFromFile('NotReferenceToTrue.json');

        foreach ([[], ['notAnything' => 'x']] as $input) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                $input,
                NotException::class,
                <<<MSG
                Invalid value for 'notAnything' declined by composition constraint
                  Requires to match none composition element but matched 1 element
                  - Composition element #1: Valid
                MSG,
            );
        }
    }

    public static function rootLevelAllOfWithTrueBranchDataProvider(): array
    {
        return [
            'reference to true' => ['{"$ref": "#/definitions/anything"}'],
            'inline true' => ['true'],
        ];
    }

    /**
     * `true` imposes nothing, so the allOf of the root is still a definite object and generates, exactly as the inline
     * `true` branch does. A reference to `true` used to be classified as a branch which blocks the object, and was
     * rejected as not representable. The reference to `false` still blocks the object, see
     * ComposedObjectShapeValidationTest::testNonRepresentableRootCompositionIsRejected().
     *
     * The schema is the same for both rows but for the second branch of the allOf, which is either the inline `true`
     * or a reference to the definition which holds `true`.
     */
    #[DataProvider('rootLevelAllOfWithTrueBranchDataProvider')]
    public function testRootLevelAllOfWithTrueBranchIsAccepted(string $trueBranch): void
    {
        $logger = new RecordingLogger();
        $className = $this->generateClassFromFileTemplate(
            'RootLevelAllOfWithTrueBranch.json',
            [$trueBranch],
            (new GeneratorConfiguration())->setCollectErrors(false)->setLogger($logger),
            escape: false,
        );

        $this->assertSame('Hannes', (new $className(['name' => 'Hannes']))->getName());
        $this->assertInstantiationFailsWithMessage(
            $className,
            [],
            AllOfException::class,
            <<<MSG
            Invalid value for '$className' declined by composition constraint
              Requires to match all composition elements but matched 1 element
              - Composition element #1: Failed
                * Missing required value for 'name'
              - Composition element #2: Valid
            MSG,
        );

        $this->assertSame(
            [['index' => 2, 'property' => $className]],
            $this->collectVacuousBranchWarnings($logger),
        );
    }

    /**
     * The references into another file are resolved like the references into the same file.
     */
    public function testReferencesToBooleanSchemasInAnotherFile(): void
    {
        $className = $this->generateClassFromFile('ExternalReference.json');

        $this->assertSame('text', (new $className(['anything' => 'text']))->getAnything());
        $this->assertNull((new $className([]))->getNothing());
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['nothing' => 'text'],
            DeniedPropertyException::class,
            "Value for 'nothing' is not allowed",
        );
    }

    /**
     * A boolean value of `dependencies` is a boolean schema as well. It used to fail with the same TypeError. A `true`
     * dependency imposes nothing, a `false` dependency forbids the property: the key must not be contained at all.
     */
    public function testBooleanDependencyValues(): void
    {
        $className = $this->generateClassFromFile('BooleanDependencies.json');

        $this->assertSame('x', (new $className(['free' => 'x']))->getFree());
        $this->assertNull((new $className([]))->getForbidden());

        foreach (['x', 5, null] as $value) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                ['forbidden' => $value],
                DeniedPropertyException::class,
                "Value for 'forbidden' is not allowed",
            );
        }
    }

    public function testRequiredPropertyWithBooleanDependencyFalseIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/^' . preg_quote("Property 'forbidden' has the dependency false and must not be provided, but is also"
                . " listed as required in file '", '/') . ".+\.json'" . ' at line \d+, column \d+$/',
        );

        $this->generateClassFromFile('BooleanDependencyOfRequiredProperty.json');
    }

    /**
     * A schema dependency is validated by a class of its own which has to represent an object, so the reference of the
     * dependency has to lead to an object definition. A boolean is rejected like any other non-object definition of a
     * reference on base level.
     */
    public function testSchemaDependencyReferencingABooleanSchemaIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/^A referenced schema on base level must provide an object definition for property '
                . '[A-Za-z0-9_]+ in file .+\.json$/',
        );

        $this->generateClassFromFile('SchemaDependencyReferencingBooleanSchema.json');
    }

    /**
     * The target of a reference which is neither an object nor a boolean (here a string) is no schema. The generation
     * names the reference and, as the reason, the pointer which doesn't lead to a schema.
     */
    public function testReferenceToAValueWhichIsNoSchemaIsRejected(): void
    {
        try {
            $this->generateClassFromFile('ReferenceToAScalar.json');
            $this->fail('Expected the reference to a string to be rejected');
        } catch (SchemaException $exception) {
            $this->assertMatchesRegularExpression(
                '/^Unresolved Reference #\/definitions\/title in file .+\.json$/',
                $exception->getMessage(),
            );
            $this->assertMatchesRegularExpression(
                "/^The value at '\/definitions\/title' in file '.+\.json' is not a schema, got string"
                    . ' at line \d+, column \d+$/',
                $exception->getPrevious()?->getMessage() ?? '',
            );
        }
    }

    /**
     * @return array<int, array{index: int, property: string}> In the order the warnings were emitted.
     */
    private function collectVacuousBranchWarnings(RecordingLogger $logger): array
    {
        return array_values(array_map(
            static fn(array $entry): array => [
                'index' => $entry['context']['index'],
                'property' => $entry['context']['property'],
            ],
            array_filter(
                $logger->getEntries(),
                static fn(array $entry): bool => $entry['level'] === 'warning'
                    && $entry['message'] === "Composition branch #{index} for '{property}' carries no validation"
                        . ' keyword and matches any value',
            ),
        ));
    }
}
