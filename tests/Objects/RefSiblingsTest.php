<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Attributes\JsonSchema as JsonSchemaAttribute;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\Attributes\PhpAttribute;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * Tests for property-level $ref + non-structural sibling keywords, type parity, and pointer
 * correctness. Structural sibling tests (properties, required, object×object merge) live in
 * RefSiblingsPropertyLevelTest and Issue79Test.
 */
class RefSiblingsTest extends AbstractPHPModelGeneratorTestCase
{
    protected const EXTERNAL_JSON_DIRECTORIES = ['../RefSiblingsTest_external'];
    // -------------------------------------------------------------------------
    // $ref alone — unchanged behavior across all drafts
    // -------------------------------------------------------------------------

    /**
     * A bare $ref with no siblings behaves identically in all drafts: the referenced schema's
     * constraints are applied and the property type matches.
     */
    public function testRefAloneUnchangedAllDrafts(): void
    {
        $className = $this->generateClassFromFile(
            'RefAlone.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $object = new $className(['name' => 'Hi']);
        $this->assertSame('Hi', $object->getName());
        $this->assertNull($object->getName() === 'Hi' ? null : 'unexpected');
    }

    public function testRefAloneEnforcesRefConstraints(): void
    {
        $className = $this->generateClassFromFile(
            'RefAlone.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $this->expectException(ValidationException::class);

        new $className(['name' => 'x']); // minLength: 2 from ref
    }

    // -------------------------------------------------------------------------
    // Non-structural property siblings (Draft 2019-09+: applied; Draft 07: ignored)
    // -------------------------------------------------------------------------

    /**
     * Draft 2019-09+: sibling minLength alongside $ref→string narrows the minimum length.
     * The effective minimum is max(ref minLength, sibling minLength) because both constraints
     * are added to the property.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testNonStructuralMinLengthSiblingApplied(): void
    {
        $className = $this->generateClassFromFile('NonStructuralPropertySiblings.json');

        // Exactly 5 characters: satisfies both ref minLength:1 and sibling minLength:5
        $object = new $className(['withMinLength' => 'Hello', 'withEnum' => 'Alice', 'withConst' => 'fixed']);
        $this->assertSame('Hello', $object->getWithMinLength());

        // 4 characters: fails sibling minLength:5
        $className2 = $this->generateClassFromFile(
            'NonStructuralPropertySiblings.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        $this->expectException(ErrorRegistryException::class);

        new $className2(['withMinLength' => 'Hi!']);
    }

    /**
     * Draft 2019-09+: sibling enum alongside $ref→string constrains the allowed values.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testNonStructuralEnumSiblingApplied(): void
    {
        $className = $this->generateClassFromFile('NonStructuralPropertySiblings.json');

        // Valid enum value
        $object = new $className(['withMinLength' => 'Hello', 'withEnum' => 'Bob', 'withConst' => 'fixed']);
        $this->assertSame('Bob', $object->getWithEnum());

        // Not in enum: rejected
        $className2 = $this->generateClassFromFile(
            'NonStructuralPropertySiblings.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        $this->expectException(ErrorRegistryException::class);

        new $className2(['withMinLength' => 'Hello', 'withEnum' => 'Dave', 'withConst' => 'fixed']);
    }

    /**
     * Draft 2019-09+: sibling const alongside $ref→string constrains the property to one exact
     * value.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testNonStructuralConstSiblingApplied(): void
    {
        $className = $this->generateClassFromFile('NonStructuralPropertySiblings.json');

        // Correct const value
        $object = new $className(['withMinLength' => 'Hello', 'withEnum' => 'Alice', 'withConst' => 'fixed']);
        $this->assertSame('fixed', $object->getWithConst());

        // Wrong value: rejected
        $className2 = $this->generateClassFromFile(
            'NonStructuralPropertySiblings.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        $this->expectException(ErrorRegistryException::class);

        new $className2(['withMinLength' => 'Hello', 'withEnum' => 'Alice', 'withConst' => 'other']);
    }

    /**
     * Draft 07: non-structural sibling keywords (minLength, enum, const) alongside $ref are
     * silently ignored. Only the ref's constraints apply, so any string satisfying minLength:1
     * is valid regardless of the siblings.
     */
    #[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
    public function testNonStructuralSiblingsDraft07Ignored(): void
    {
        $className = $this->generateClassFromFile('NonStructuralPropertySiblings.json');

        // 'xx' satisfies ref minLength:1; it is not in the enum but siblings are ignored.
        $object = new $className([
            'withMinLength' => 'xx',  // only 2 chars: passes ref minLength:1, no sibling minLength:5
            'withEnum'      => 'Dave', // not in sibling enum, but enum is ignored
            'withConst'     => 'other', // not the const value, but const is ignored
        ]);

        $this->assertSame('xx', $object->getWithMinLength());
        $this->assertSame('Dave', $object->getWithEnum());
        $this->assertSame('other', $object->getWithConst());
    }

    /**
     * Draft 07: the ref's own constraints (minLength:1) are still enforced.
     */
    #[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
    public function testNonStructuralSiblingsDraft07RefConstraintsStillEnforced(): void
    {
        $className = $this->generateClassFromFile(
            'NonStructuralPropertySiblings.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        $this->expectException(ErrorRegistryException::class);

        // Empty string violates ref's minLength:1
        new $className(['withMinLength' => '']);
    }

    // -------------------------------------------------------------------------
    // Scalar type narrowing — Draft 2019-09+
    // -------------------------------------------------------------------------

    /**
     * Draft 2019-09+: when $ref resolves to number (float) and the sibling specifies
     * type: integer, the effective PHP type is int (integer is a subtype of number).
     * The ref's minimum: 0 constraint is also preserved.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testScalarTypeNarrowsToSubtype(): void
    {
        $className = $this->generateClassFromFile(
            'ScalarTypeMerge.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $object = new $className(['count' => 5]);
        $this->assertSame(5, $object->getCount());

        // float not accepted when narrowed to int
        $this->expectException(ValidationException::class);

        new $className(['count' => 1.5]);
    }

    /**
     * Draft 2019-09+: the ref's constraints (minimum: 0) are still enforced after narrowing.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testScalarTypeNarrowingPreservesRefConstraints(): void
    {
        $className = $this->generateClassFromFile(
            'ScalarTypeMerge.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $this->expectException(ValidationException::class);

        new $className(['count' => -1]); // violates ref's minimum: 0
    }

    /**
     * Draft 2019-09+: when $ref resolves to a nullable string (string|null) and the sibling
     * specifies type: string (non-null), null is rejected when implicitNull is disabled
     * (the sibling narrows away the ref's nullability). With implicitNull enabled (the
     * default), the generator still accepts null for absent optional properties — this
     * is the global implicit-null contract, not overridden by type narrowing.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testNullableRefNarrowedToNonNullBySibling(): void
    {
        // String value is always accepted.
        $className = $this->generateClassFromFile(
            'ScalarTypeMerge.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $object = new $className(['count' => 1, 'label' => 'hello']);
        $this->assertSame('hello', $object->getLabel());

        // With implicitNull disabled, null is no longer valid after the sibling narrows
        // away the ref's nullability: the TypeCheck for 'string' does not allow null.
        $strictClassName = $this->generateClassFromFile(
            'ScalarTypeMerge.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
            implicitNull: false,
        );

        $this->expectException(ValidationException::class);

        new $strictClassName(['count' => 1, 'label' => null]);
    }

    /**
     * Draft 07: sibling type is ignored. The property keeps the ref's original type (number /
     * float), so floating-point values are accepted and ref constraints still apply.
     */
    #[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
    public function testScalarTypeSiblingIgnoredDraft07(): void
    {
        $className = $this->generateClassFromFile(
            'ScalarTypeMerge.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        // Sibling type: integer is ignored; float (number) is still accepted
        $object = new $className(['count' => 1.5]);
        $this->assertSame(1.5, $object->getCount());
    }

    /**
     * Draft 07: ref's nullable string remains nullable (sibling type: string is ignored).
     */
    #[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
    public function testNullableRefRemainsNullableDraft07(): void
    {
        $className = $this->generateClassFromFile(
            'ScalarTypeMerge.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        // null accepted: sibling type: string is ignored, ref stays string|null
        $object = new $className(['count' => 0, 'label' => null]);
        $this->assertNull($object->getLabel());
    }

    // -------------------------------------------------------------------------
    // Scalar type contradiction (Draft 2019-09+ → SchemaException)
    // -------------------------------------------------------------------------

    /**
     * Draft 2019-09+: when the sibling type is incompatible with the $ref's resolved type,
     * schema generation must fail with a SchemaException citing the incompatibility.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testScalarTypeContradictionThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);

        $this->generateClassFromFile('ScalarTypeConflict.json');
    }

    /**
     * Draft 07: the contradictory sibling type is silently ignored, so no SchemaException is
     * thrown and the ref's type (string) applies.
     */
    #[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
    public function testScalarTypeContradictionIgnoredDraft07(): void
    {
        $className = $this->generateClassFromFile(
            'ScalarTypeConflict.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        // Sibling type: integer is ignored, so string is accepted
        $object = new $className(['value' => 'hello']);
        $this->assertSame('hello', $object->getValue());
    }

    // -------------------------------------------------------------------------
    // Object-level type intersection (Draft 2019-09+)
    // -------------------------------------------------------------------------

    /**
     * Draft 2019-09+: when a property is declared by both the $ref'd object and a sibling
     * 'properties' entry, the effective type is their allOf intersection. Here the ref
     * contributes 'count: number' and the sibling contributes 'count: integer'; the
     * intersection (integer ⊂ number) narrows the type to int. Float values must be rejected.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testObjectLevelTypeIntersectionNarrowsToInteger(): void
    {
        $className = $this->generateClassFromFile(
            'ObjectLevelTypeIntersection.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $object = new $className(['count' => 5]);
        $this->assertSame(5, $object->getCount());

        // float not accepted after narrowing to integer
        $this->expectException(ValidationException::class);

        new $className(['count' => 1.5]);
    }

    /**
     * Draft 2019-09+: the ref's minimum: 0 constraint is preserved after the integer
     * narrowing — the allOf intersection applies both type and value constraints.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testObjectLevelTypeIntersectionPreservesRefConstraints(): void
    {
        $className = $this->generateClassFromFile(
            'ObjectLevelTypeIntersection.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $this->expectException(ValidationException::class);

        new $className(['count' => -1]); // violates ref minimum: 0
    }

    /**
     * Draft 07: the sibling 'count: integer' entry is silently ignored. The ref's
     * 'count: number' type (float in PHP) is used as-is, so float values are accepted.
     */
    #[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
    public function testObjectLevelTypeIntersectionSiblingIgnoredDraft07(): void
    {
        $className = $this->generateClassFromFile(
            'ObjectLevelTypeIntersection.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        // float is accepted: sibling integer narrowing is ignored
        $object = new $className(['count' => 1.5]);
        $this->assertSame(1.5, $object->getCount());
    }

    // -------------------------------------------------------------------------
    // Default value parity for overlapping $ref + sibling properties (Draft 2019-09+)
    // -------------------------------------------------------------------------

    /**
     * Draft 2019-09+: when the $ref'd object and the sibling 'properties' entry declare
     * the same default for a shared property, generation succeeds and the default is applied.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testDefaultValuesAgreeNoError(): void
    {
        $className = $this->generateClassFromFile(
            'DefaultValuesAgree.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        // Default 'World' applied when property is absent from input
        $object = new $className([]);
        $this->assertSame('World', $object->getName());
    }

    /**
     * Draft 2019-09+: when the $ref'd object and the sibling 'properties' entry declare
     * conflicting defaults for the same property, generation must fail with a SchemaException
     * that cites the authored property pointers — never a synthetic allOf/N path.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testDefaultValuesConflictThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/Conflicting default values for property .name./');

        $this->generateClassFromFile('DefaultValuesConflict.json');
    }

    /**
     * Draft 07: sibling 'properties' are ignored, so the conflicting defaults are never
     * compared and no SchemaException is thrown. Only the ref's default ('Alice') applies.
     */
    #[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
    public function testDefaultConflictIgnoredDraft07(): void
    {
        $className = $this->generateClassFromFile(
            'DefaultValuesConflict.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        // Sibling default 'Bob' is ignored; only ref default 'Alice' is active.
        $object = new $className([]);
        $this->assertSame('Alice', $object->getName());
    }

    /**
     * Draft 2019-09+: when a property is declared by the $ref'd object with a default
     * value and the sibling 'properties' entry for the same property has no default,
     * the ref's default is propagated to the merged property.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testDefaultValuePropagatedFromRef(): void
    {
        $className = $this->generateClassFromFile(
            'DefaultValuePropagation.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        // Ref's default 'World' propagated even though sibling declared 'name' without a default.
        $object = new $className([]);
        $this->assertSame('World', $object->getName());
    }

    // -------------------------------------------------------------------------
    // Root-level pointer assertions (no /allOf/ segment)
    // -------------------------------------------------------------------------

    /**
     * Draft 2019-09+: properties contributed by a root-level $ref+siblings schema carry their
     * authored JSON pointers, not synthetic /allOf/N paths.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testRootLevelRefSiblingPropertyPointersAreAuthored(): void
    {
        $className = $this->generateClassFromFile(
            'NonStructuralPropertySiblings.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $object = new $className([
            'withMinLength' => 'Hello',
            'withEnum'      => 'Alice',
            'withConst'     => 'fixed',
        ]);

        // Each property's pointer must reflect its authored location in the schema, not a
        // synthetic /allOf/N path that the old constructor wrap used to generate.
        $this->assertPropertyHasJsonPointer($object, 'withMinLength', '/properties/withMinLength');
        $this->assertPropertyHasJsonPointer($object, 'withEnum', '/properties/withEnum');
        $this->assertPropertyHasJsonPointer($object, 'withConst', '/properties/withConst');
    }

    // -------------------------------------------------------------------------
    // Recursive $ref with siblings — no crash (Draft 2019-09+)
    // -------------------------------------------------------------------------

    /**
     * Draft 2019-09+: when the $ref'd object contains a recursive self-reference (next →
     * node), adding a sibling 'label' property at the root must not crash. All three
     * properties — from the ref (value, next) and from the sibling (label) — must be
     * accessible on the generated class.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testRecursiveRefWithSiblingsMergesCorrectly(): void
    {
        $className = $this->generateClassFromFile('RecursiveRefSiblings.json');

        // All properties — from ref (value, next) and from sibling (label) — must be present.
        $object = new $className(['value' => 'root', 'label' => 'tag']);
        $this->assertSame('root', $object->getValue());
        $this->assertSame('tag', $object->getLabel());
        $this->assertNull($object->getNext());
    }

    /**
     * Draft 07: sibling 'label' is silently ignored; only properties from the $ref'd
     * recursive object (value, next) appear on the generated class.
     */
    #[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
    public function testRecursiveRefDraft07SiblingIgnored(): void
    {
        $className = $this->generateClassFromFile('RecursiveRefSiblings.json');

        // Only ref properties (value, next) are present; sibling label is absent.
        $object = new $className(['value' => 'root']);
        $this->assertSame('root', $object->getValue());
        $this->assertFalse(method_exists($object, 'getLabel'));
    }

    // -------------------------------------------------------------------------
    // External-file $ref with siblings (Draft 2019-09+)
    // -------------------------------------------------------------------------

    /**
     * Draft 2019-09+: when the root $ref resolves to an external JSON file and the schema
     * has sibling 'properties' (city), the generated class must expose both properties from
     * the external file (street, required) and from the sibling (city, optional).
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testExternalFileRefWithSiblingsMerged(): void
    {
        $className = $this->generateClassFromFile('ExternalRefSiblings.json');

        // street (from external ref, required) and city (from sibling) both accessible.
        $object = new $className(['street' => 'Main St', 'city' => 'Berlin']);
        $this->assertSame('Main St', $object->getStreet());
        $this->assertSame('Berlin', $object->getCity());
    }

    /**
     * Draft 2019-09+: the external ref's required constraint (street) is enforced.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testExternalFileRefEnforcesRefConstraints(): void
    {
        $className = $this->generateClassFromFile(
            'ExternalRefSiblings.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        $this->expectException(ErrorRegistryException::class);

        new $className(['city' => 'Berlin']); // missing required 'street' from external ref
    }

    /**
     * Draft 07: sibling 'city' is silently ignored; only properties from the external
     * $ref file (street) appear on the generated class.
     */
    #[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
    public function testExternalFileRefDraft07SiblingIgnored(): void
    {
        $className = $this->generateClassFromFile('ExternalRefSiblings.json');

        // Only ref property 'street' is present; sibling 'city' is absent.
        $object = new $className(['street' => 'Main St']);
        $this->assertSame('Main St', $object->getStreet());
        $this->assertFalse(method_exists($object, 'getCity'));
    }

    // -------------------------------------------------------------------------
    // {$ref, siblings} nested inside a real allOf branch (Draft 2019-09+)
    // -------------------------------------------------------------------------

    /**
     * Draft 2019-09+: when an allOf branch contains {$ref → location, properties: {city}},
     * the branch is treated as an object×object merge — the resulting nested class for
     * 'address' exposes both properties from the ref (street) and from the sibling (city).
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testRefSiblingsInsideAllOfBranchMerged(): void
    {
        $className = $this->generateClassFromFile('RefSiblingsInsideAllOf.json');

        $object  = new $className(['address' => ['street' => 'Main St', 'city' => 'Berlin']]);
        $address = $object->getAddress();

        $this->assertSame('Main St', $address->getStreet());
        $this->assertSame('Berlin', $address->getCity());
    }

    /**
     * Draft 07: when an allOf branch contains {$ref → location, properties: {city}}, the
     * sibling 'city' is silently ignored (ExclusiveProducer). Only the ref's property
     * 'street' is present on the nested address object.
     */
    #[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
    public function testRefSiblingsInsideAllOfBranchDraft07SiblingIgnored(): void
    {
        $className = $this->generateClassFromFile('RefSiblingsInsideAllOf.json');

        $object  = new $className(['address' => ['street' => 'Main St']]);
        $address = $object->getAddress();

        $this->assertSame('Main St', $address->getStreet());
        $this->assertFalse(method_exists($address, 'getCity'));
    }

    // -------------------------------------------------------------------------
    // #[JsonSchema] attribute reflects authored schema, not synthetic allOf
    // -------------------------------------------------------------------------

    /**
     * Draft 2019-09+: the #[JsonSchema] PHP attribute attached to the generated class must
     * encode the authored schema (with $ref and properties at the top level), not a
     * synthetic allOf wrapper that the old JsonSchema constructor used to inject.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testJsonSchemaAttributeReflectsAuthoredSchema(): void
    {
        $configuration = (new GeneratorConfiguration())
            ->setCollectErrors(false)
            ->setEnabledAttributes(PhpAttribute::JSON_SCHEMA | PhpAttribute::JSON_POINTER);

        $className = $this->generateClassFromFile('ObjectLevelTypeIntersection.json', $configuration);

        $classAttributes = (new ReflectionClass($className))->getAttributes();

        $jsonSchemaAttr = null;
        foreach ($classAttributes as $attr) {
            if ($attr->getName() === JsonSchemaAttribute::class) {
                $jsonSchemaAttr = $attr;
                break;
            }
        }

        $this->assertNotNull($jsonSchemaAttr, '#[JsonSchema] attribute missing from generated class');

        $jsonSchemaArg = $jsonSchemaAttr->getArguments()[0];

        // The authored $ref must be present at the top level of the embedded schema.
        $this->assertStringContainsString('"$ref"', $jsonSchemaArg);
        // A synthetic allOf wrapper must not appear — the schema is authored directly.
        $this->assertStringNotContainsString('"allOf"', $jsonSchemaArg);
    }
}
