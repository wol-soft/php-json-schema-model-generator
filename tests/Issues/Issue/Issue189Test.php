<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

/**
 * Issue #189: `{"type": ["object"]}` is exactly equivalent to `{"type": "object"}` per JSON Schema,
 * and ObjectShapeResolver classified it as a definite object, but the rest of the pipeline did not:
 * PropertyFactory::create() sent every array-valued type to the multi-type path (leaving the outer
 * property without a nested schema and without a type), and SchemaProcessor::processSchema()'s gate
 * compared `type` against the string 'object'. A schema was therefore approved as representable and
 * then either failed with a misattributed "No nested schema for composed property" error or was
 * skipped without any diagnostic at all.
 *
 * Fixed by normalizing a `type` array in JsonSchema itself - dropping duplicates and collapsing a
 * single remaining entry to its scalar form - so routing, gate and classifier agree by construction.
 */
class Issue189Test extends AbstractIssueTestCase
{
    /**
     * The second allOf branch declares its object-ness as a single-element array. Both branches are
     * object schemas, the composition is satisfiable, and writing that branch as `"type": "object"`
     * has always generated - so the array spelling must generate the same class and enforce the
     * same constraints from both branches.
     */
    public function testSingleElementObjectTypeArrayInACompositionBranchIsRoutedAsAnObject(): void
    {
        $className = $this->generateClassFromFile('CompositionBranchObjectTypeArray.json');

        $object = new $className(['person' => ['name' => 'Hannes']]);
        $this->assertSame('Hannes', $object->getPerson()->getName());

        // the required constraint contributed by the single-element-array branch
        try {
            new $className(['person' => []]);
            $this->fail('Expected a ValidationException for the missing required value');
        } catch (ValidationException $exception) {
            $this->assertNestedCompositionMessage(
                <<<'ERROR'
                Invalid nested object for property 'person':
                  - Invalid value for '%nestedClass%' declined by composition constraint
                      Requires to match all composition elements but matched 1 element
                      - Composition element #1: Valid
                      - Composition element #2: Failed
                        * Missing required value for 'name'
                ERROR,
                $exception->getMessage(),
            );
        }

        // the type constraint contributed by the sibling branch
        try {
            new $className(['person' => ['name' => 42]]);
            $this->fail('Expected a ValidationException for the invalid property type');
        } catch (ValidationException $exception) {
            $this->assertNestedCompositionMessage(
                <<<'ERROR'
                Invalid nested object for property 'person':
                  - Invalid value for '%nestedClass%' declined by composition constraint
                      Requires to match all composition elements but matched 1 element
                      - Composition element #1: Failed
                        * Invalid type for 'name': requires 'string', got 'integer'
                      - Composition element #2: Valid
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * The root composition previously passed SchemaProcessor::checkObjectRepresentability() - which
     * classifies `["object"]` as object-asserting - moments before the pipeline refused to build it.
     */
    public function testSingleElementObjectTypeArrayInARootCompositionGeneratesAClass(): void
    {
        $className = $this->generateClassFromFile('RootCompositionObjectTypeArray.json');

        $object = new $className(['name' => 'Hannes']);
        $this->assertSame('Hannes', $object->getName());

        $this->expectException(ValidationException::class);
        new $className([]);
    }

    /**
     * A bare root produced no class, no warning and no error at all, because the gate compared the
     * raw `type` against the string 'object'.
     */
    public function testBareRootWithSingleElementObjectTypeArrayGeneratesAClass(): void
    {
        $className = $this->generateClassFromFile('BareRootObjectTypeArray.json');

        $object = new $className(['name' => 'Hannes']);
        $this->assertSame('Hannes', $object->getName());

        $this->expectException(ValidationException::class);
        new $className(['name' => 42]);
    }

    /**
     * Normalization applies to every type, not just object: a single-element scalar array (and one
     * that only repeats a single type) is typed, hinted and validated exactly like the scalar form,
     * including the single-type wording of the type error - it no longer travels the multi-type path
     * that would report `requires ['string']`.
     */
    public function testSingleElementScalarTypeArrayBehavesLikeTheScalarForm(): void
    {
        $className = $this->generateClassFromFile('ScalarTypeArrayProperty.json');

        $object = new $className(['name' => 'Hannes', 'nickname' => 'Hans']);
        $this->assertSame('Hannes', $object->getName());
        $this->assertSame('Hans', $object->getNickname());

        $this->assertSame(['string', 'null'], $this->getReturnTypeNames($object, 'getName'));
        $this->assertSame(['string', 'null'], $this->getReturnTypeNames($object, 'getNickname'));

        // the sibling type-specific keyword still applies
        try {
            new $className(['name' => 'a']);
            $this->fail('Expected a ValidationException for the too short value');
        } catch (ValidationException $exception) {
            $this->assertSame("Value for 'name' must not be shorter than 2", $exception->getMessage());
        }

        try {
            new $className(['name' => 42]);
            $this->fail('Expected a ValidationException for the invalid type');
        } catch (ValidationException $exception) {
            $this->assertSame(
                "Invalid type for 'name': requires 'string', got 'integer'",
                $exception->getMessage(),
            );
        }

        // the duplicate-entry declaration is the scalar declaration, not a two-type union
        try {
            new $className(['nickname' => 42]);
            $this->fail('Expected a ValidationException for the invalid type');
        } catch (ValidationException $exception) {
            $this->assertSame(
                "Invalid type for 'nickname': requires 'string', got 'integer'",
                $exception->getMessage(),
            );
        }
    }

    /**
     * PropertyNamesValidator compares the declared type of the propertyNames schema against the
     * string 'string', so the array spelling used to be rejected as an invalid schema although it
     * declares exactly the type the keyword requires.
     */
    public function testPropertyNamesDeclaredAsASingleElementTypeArrayIsAccepted(): void
    {
        $className = $this->generateClassFromFile('PropertyNamesTypeArray.json');

        $object = new $className(['abc' => 1]);
        $this->assertSame(1, $object->meta()->rawInput()['abc']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(
            <<<ERROR
            Provided JSON for '$className' contains properties with invalid names
              - invalid property 'abcdef'
                * Value for 'property name' must not be longer than 4
            ERROR,
        );

        new $className(['abcdef' => 1]);
    }

    /**
     * A multi-type declaration which is not reduced by normalization must keep working in a
     * composition: both branches permit an object or a string, so the composition on a property
     * accepts a string untouched (the object keywords don't apply to it), enforces both branches'
     * object keywords on an object, and rejects every other type with the multi-type wording.
     */
    public function testObjectOrStringTypeArrayInBothCompositionBranchesIsEnforcedPerType(): void
    {
        $className = $this->generateClassFromFile('CompositionBranchObjectOrStringTypeArray.json');

        $object = new $className(['person' => 'Hannes']);
        $this->assertSame('Hannes', $object->getPerson());

        $object = new $className(['person' => ['name' => 'Hannes']]);
        $this->assertSame('Hannes', $object->getPerson()->getName());

        // the required constraint contributed by the second branch only applies to an object
        try {
            new $className(['person' => []]);
            $this->fail('Expected a ValidationException for the missing required value');
        } catch (ValidationException $exception) {
            $this->assertSame(
                <<<'ERROR'
                Invalid value for 'person' declined by composition constraint
                  Requires to match all composition elements but matched 1 element
                  - Composition element #1: Valid
                  - Composition element #2: Failed
                    * Missing required value for 'name'
                ERROR,
                $exception->getMessage(),
            );
        }

        // the property type declared by the first branch only applies to an object
        try {
            new $className(['person' => ['name' => 42]]);
            $this->fail('Expected a ValidationException for the invalid property type');
        } catch (ValidationException $exception) {
            $this->assertSame(
                <<<'ERROR'
                Invalid value for 'person' declined by composition constraint
                  Requires to match all composition elements but matched 1 element
                  - Composition element #1: Failed
                    * Invalid type for 'name': requires 'string', got 'integer'
                  - Composition element #2: Valid
                ERROR,
                $exception->getMessage(),
            );
        }

        // a type neither branch permits
        try {
            new $className(['person' => 42]);
            $this->fail('Expected a ValidationException for the invalid type');
        } catch (ValidationException $exception) {
            $this->assertSame(
                <<<'ERROR'
                Invalid value for 'person' declined by composition constraint
                  Requires to match all composition elements but matched 0 elements
                  - Composition element #1: Failed
                    * Invalid type for 'person': requires ['object', 'string'], got 'integer'
                  - Composition element #2: Failed
                    * Invalid type for 'person': requires ['object', 'string'], got 'integer'
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * A root composition whose branches also permit a string can't be represented as a class: the
     * document itself may be a string. Unlike the single-element spelling this is the correct
     * rejection, so normalization must not turn a multi-type list into an object.
     */
    public function testObjectOrStringTypeArrayInARootCompositionIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Composition for '[A-Za-z0-9_]+' in file '.*\\.json' does not resolve to a definite object and "
            . "cannot be represented as a generated class: if every value it accepts is meant to be an object, "
            . "declare '\"type\": \"object\"' on the schema itself at line 1, column 1$/",
        );

        $this->generateClassFromFile('RootCompositionObjectOrStringTypeArray.json');
    }

    /**
     * An empty type array permits no value at all. Generating an untyped, unvalidated property for
     * it would silently accept everything the schema forbids, so it is rejected at generation time.
     */
    public function testEmptyTypeArrayIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Empty 'type' array in file '(.*)\\.json' at line \\d+, column \\d+$/",
        );

        $this->generateClassFromFile('EmptyTypeArray.json');
    }

    /**
     * Asserts a composition message whose only unpredictable part is the nested class name: the
     * test case generator suffixes both the root class and every nested class with a uniqid, so
     * '%nestedClass%' stands for the generated name of the class representing the "person"
     * property.
     */
    private function assertNestedCompositionMessage(string $expectedMessageTemplate, string $actualMessage): void
    {
        $uniqid = '[0-9A-Za-z]{13}';
        $pattern = str_replace(
            preg_quote('%nestedClass%', '~'),
            preg_quote($this->getStaticClassName(), '~') . "_{$uniqid}_Person{$uniqid}",
            preg_quote($expectedMessageTemplate, '~'),
        );

        $this->assertMatchesRegularExpression("~^{$pattern}$~", $actualMessage);
    }
}
