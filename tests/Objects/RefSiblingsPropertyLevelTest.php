<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\Arrays\MaxItemsException;
use PHPModelGenerator\Exception\Arrays\MinItemsException;
use PHPModelGenerator\Exception\Arrays\UnevaluatedItemsException;
use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Generic\EnumException;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\String\MaxLengthException;
use PHPModelGenerator\Exception\String\MinLengthException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for property-level $ref + sibling merge behavior.
 *
 * For Draft 2019-09+, structural sibling keywords alongside $ref produce a merged nested
 * class that combines the referenced schema's properties with the sibling's properties.
 * For Draft 07, siblings are silently ignored (ExclusiveProducer).
 */
class RefSiblingsPropertyLevelTest extends AbstractPHPModelGeneratorTestCase
{
    // -------------------------------------------------------------------------
    // Type inherited by a composition branch which is a $ref (Draft 2019-09+)
    // -------------------------------------------------------------------------

    /**
     * A composition branch inherits the type of its property, which makes the type a sibling of the $ref of the
     * branch. The keywords of the referenced definition are validated nevertheless: the contains of the definition
     * was dropped, a value without a string was accepted by the first branch.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testContainsOfAReferencedDefinitionIsValidatedInACompositionBranchOfATypedProperty(): void
    {
        $className = $this->generateClassFromFile(
            'RefBranchWithContains.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        // valid by the contains of the definition, and valid by the maxItems of the second branch
        $this->assertSame(['a', 1, 2], (new $className(['tags' => ['a', 1, 2]]))->getTags());
        $this->assertSame([1], (new $className(['tags' => [1]]))->getTags());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tags' => [1, 2]],
            AnyOfException::class,
            <<<'MSG'
            Invalid value for 'tags' declined by composition constraint
              Requires to match at least one composition element
              - Composition element #1: Failed
                * No item in array 'tags' matches the 'contains' constraint
              - Composition element #2: Failed
                * Array 'tags' must not contain more than 1 items
            MSG,
        );
    }

    // -------------------------------------------------------------------------
    // Property-level object×object merge (Draft 2019-09+)
    // -------------------------------------------------------------------------

    /**
     * Draft 2019-09+: a property-level {$ref→object, properties: {city}} merges into a single
     * nested class containing all properties from the ref (street, zip) and the sibling (city).
     * Both 'street' (from ref) and 'city' (from sibling required) must be present.
     */
    /**
     * A definition without a type has no type to intersect with the keywords next to the reference: they apply like
     * the keywords of a property without the reference (typed by their own type, a type list like a multi type
     * property, self-gating per type without a type), the keywords of the definition apply alongside. Dropping the
     * siblings was rejected: `{"type": "array", "$ref": <untyped>, "maxItems": 2}` accepted every value.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('validationMethodDataProvider')]
    public function testSiblingsOfAReferenceToADefinitionWithoutTypeAreApplied(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('SiblingsOfAReferenceToAnUntypedDefinition.json', $configuration);

        $object = new $className([
            'siblingWithoutType' => ['a'],
            'siblingWithType' => ['a', 'b'],
            'siblingWithTypeList' => 'ab',
            'siblingEnum' => 'ab',
        ]);
        $this->assertSame(['a'], $object->getSiblingWithoutType());
        $this->assertSame(['a', 'b'], $object->getSiblingWithType());
        $this->assertSame('ab', $object->getSiblingWithTypeList());
        $this->assertSame('ab', $object->getSiblingEnum());

        // without a type the array keywords don't apply to a string
        $this->assertSame('x', (new $className(['siblingWithoutType' => 'x']))->getSiblingWithoutType());
        $this->assertSame(['a'], (new $className(['siblingWithTypeList' => ['a']]))->getSiblingWithTypeList());

        // the object type next to the reference holds the model of a nested class
        $this->assertSame(
            ['a' => 1],
            (new $className(['siblingWithObjectType' => ['a' => 1]]))->getSiblingWithObjectType()->meta()->rawInput(),
        );

        $rejections = [
            // the keyword of the definition
            [
                ['siblingWithoutType' => []],
                MinItemsException::class,
                "Array 'siblingWithoutType' must not contain less than 1 items",
            ],
            // the keyword next to the reference
            [
                ['siblingWithoutType' => ['a', 'b', 'c']],
                MaxItemsException::class,
                "Array 'siblingWithoutType' must not contain more than 2 items",
            ],
            [
                ['siblingWithType' => 'x'],
                InvalidTypeException::class,
                "Invalid type for 'siblingWithType': requires 'array', got 'string'",
            ],
            [
                ['siblingWithType' => ['a', 'b', 'c']],
                MaxItemsException::class,
                "Array 'siblingWithType' must not contain more than 2 items",
            ],
            [
                ['siblingWithTypeList' => 5],
                InvalidTypeException::class,
                "Invalid type for 'siblingWithTypeList': requires ['array', 'string'], got 'integer'",
            ],
            [
                ['siblingWithTypeList' => 'abcd'],
                MaxLengthException::class,
                "Value for 'siblingWithTypeList' must not be longer than 3",
            ],
            [
                ['siblingWithTypeList' => ['a', 'b', 'c']],
                MaxItemsException::class,
                "Array 'siblingWithTypeList' must not contain more than 2 items",
            ],
            [
                ['siblingWithTypeList' => []],
                MinItemsException::class,
                "Array 'siblingWithTypeList' must not contain less than 1 items",
            ],
            [
                ['siblingWithObjectType' => 'x'],
                InvalidTypeException::class,
                "Invalid type for 'siblingWithObjectType': requires 'object', got 'string'",
            ],
            [
                ['siblingEnum' => 'a'],
                MinLengthException::class,
                "Value for 'siblingEnum' must not be shorter than 2",
            ],
            [
                ['siblingEnum' => 'xyz'],
                EnumException::class,
                'Value for \'siblingEnum\' must be one of ["a","ab","abc",null], got "xyz"',
            ],
        ];

        foreach ($rejections as [$input, $exceptionClass, $message]) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                $input,
                $this->getExpectedExceptionClass($configuration, $exceptionClass),
                $message,
            );
        }
    }

    /**
     * The items a referenced definition evaluates count for an unevaluatedItems next to the reference, per schema
     * object: the tuple of the definition, its additionalItems and `items: true` (no validator, read from the JSON of
     * the definition). Reading the schema next to the reference only was rejected: it rejected every item of the
     * tuple of the definition. Tuples need the tuple form of `items` (up to Draft 2019-09).
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09, until: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('validationMethodDataProvider')]
    public function testUnevaluatedItemsNextToAReferenceSeesTheItemsOfTheReferencedTuple(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('SiblingUnevaluatedItemsOfAReferencedTuple.json', $configuration);

        $object = new $className([
            'typedReference' => ['a', 'b'],
            'untypedReferenceWithSiblingTuple' => ['a', 'b'],
            'referencedAdditionalItems' => ['a', 1, true],
            'referencedItemsTrue' => [1, 'a'],
        ]);
        $this->assertSame(['a', 'b'], $object->getTypedReference());
        $this->assertSame(['a', 'b'], $object->getUntypedReferenceWithSiblingTuple());
        $this->assertSame(['a', 1, true], $object->getReferencedAdditionalItems());
        $this->assertSame([1, 'a'], $object->getReferencedItemsTrue());

        foreach (['typedReference', 'untypedReferenceWithSiblingTuple'] as $property) {
            $this->assertInstantiationFailsWithMessage(
                $className,
                [$property => ['a', 'b', 'c']],
                $this->getExpectedExceptionClass($configuration, UnevaluatedItemsException::class),
                "Provided JSON for '$property' contains not allowed unevaluated items [#2]",
            );
        }
    }

    /**
     * The items matched by the contains of a referenced definition count for an unevaluatedItems next to the
     * reference (Draft 2020-12 on, Draft 2019-09 gives contains no annotation).
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2020_12)]
    #[DataProvider('validationMethodDataProvider')]
    public function testUnevaluatedItemsNextToAReferenceSeesTheItemsMatchedByTheReferencedContains(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('SiblingUnevaluatedItemsOfAReferencedContains.json', $configuration);

        $this->assertSame(['a', 'b'], (new $className(['tags' => ['a', 'b']]))->getTags());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tags' => ['a', 1]],
            $this->getExpectedExceptionClass($configuration, UnevaluatedItemsException::class),
            "Provided JSON for 'tags' contains not allowed unevaluated items [#1]",
        );
    }

    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testPropertyLevelObjectRefAndSiblingPropertiesMerged(): void
    {
        $className = $this->generateClassFromFile('PropertyLevelObjectMerge.json');

        $object = new $className(['address' => ['street' => 'Main St', 'city' => 'Berlin']]);

        $address = $object->getAddress();
        $this->assertSame('Main St', $address->getStreet());
        $this->assertNull($address->getZip());
        $this->assertSame('Berlin', $address->getCity());

        // Properties from the ref keep their definition pointers; properties from the sibling
        // keep the authored property-path pointer. None should contain /allOf/N.
        $this->assertPropertyHasJsonPointer(
            $address,
            'street',
            '/definitions/location/properties/street',
        );
        $this->assertPropertyHasJsonPointer(
            $address,
            'zip',
            '/definitions/location/properties/zip',
        );
        $this->assertPropertyHasJsonPointer(
            $address,
            'city',
            '/properties/address/properties/city',
        );
    }

    /**
     * Draft 2019-09+: supplying zip fills that property from the ref side of the merged class.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testPropertyLevelObjectMergedClassHoldsAllProperties(): void
    {
        $className = $this->generateClassFromFile('PropertyLevelObjectMerge.json');

        $object = new $className(['address' => ['street' => 'Oak Ave', 'zip' => 10115, 'city' => 'Berlin']]);

        $address = $object->getAddress();
        $this->assertSame('Oak Ave', $address->getStreet());
        $this->assertSame(10115, $address->getZip());
        $this->assertSame('Berlin', $address->getCity());
    }

    /**
     * Draft 2019-09+: validation from the ref side (required 'street') is still enforced in the
     * merged class.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('propertyLevelMergeInvalidInputProvider')]
    public function testPropertyLevelObjectMergedClassEnforcesValidation(array $addressData): void
    {
        $className = $this->generateClassFromFile(
            'PropertyLevelObjectMerge.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        $this->expectException(ErrorRegistryException::class);

        new $className(['address' => $addressData]);
    }

    public static function propertyLevelMergeInvalidInputProvider(): array
    {
        return [
            'missing required street from ref'    => [['city' => 'Berlin']],
            'missing required city from sibling'  => [['street' => 'Main St']],
            'missing both required properties'    => [[]],
            'invalid zip type from ref'           => [['street' => 'Main St', 'city' => 'Berlin', 'zip' => 'ABC']],
        ];
    }

    // -------------------------------------------------------------------------
    // Property-level object×object merge with colliding property name (Draft 2019-09+)
    // -------------------------------------------------------------------------

    /**
     * Draft 2019-09+: when a property-level $ref→object and sibling 'properties' both declare
     * a property with the same name ('street'), they are merged with allOf semantics. The
     * result is a class that enforces both the ref's type constraint (string) and the sibling's
     * additional constraints (minLength: 3).
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testPropertyLevelObjectMergeWithCollidingPropertyName(): void
    {
        $className = $this->generateClassFromFile('PropertyLevelObjectCollision.json');

        $object = new $className(['address' => ['street' => 'Oak', 'city' => 'Berlin']]);

        $address = $object->getAddress();
        $this->assertSame('Oak', $address->getStreet());
        $this->assertNull($address->getZip());
        $this->assertSame('Berlin', $address->getCity());
    }

    /**
     * Draft 2019-09+: the allOf intersection for the colliding 'street' property enforces
     * the sibling's minLength: 3 constraint.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testPropertyLevelObjectCollisionEnforcesMergedConstraints(): void
    {
        $className = $this->generateClassFromFile(
            'PropertyLevelObjectCollision.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        $this->expectException(ErrorRegistryException::class);

        // 'AB' is 2 chars — violates sibling minLength: 3
        new $className(['address' => ['street' => 'AB', 'city' => 'Berlin']]);
    }

    /**
     * Draft 2019-09+: when the colliding property's type narrows (ref: number, sibling:
     * integer), both the narrowed type itself and the ref's own range constraint (minimum: 0)
     * must still be enforced — narrowing must not silently drop constraints the ref side
     * declared. The narrowed type must also be reflected in the generated getter's type hint.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testPropertyLevelObjectCollisionTypeNarrowingPreservesRefConstraints(): void
    {
        $className = $this->generateClassFromFile('PropertyLevelObjectCollisionTypeNarrowing.json');

        $object = new $className(['address' => ['zip' => 5]]);
        $address = $object->getAddress();
        $this->assertSame(5, $address->getZip());

        // the merged getter is typed as the narrowed integer, not the ref's original number
        $this->assertSame(['int', 'null'], $this->getReturnTypeNames($address, 'getZip'));

        // float not accepted after narrowing to integer — checked here (not test-ending) so the
        // ref's minimum constraint can still be asserted below on the same generated class
        try {
            new $className(['address' => ['zip' => 1.5]]);
            $this->fail('Expected a ValidationException for a float value after integer narrowing');
        } catch (ValidationException $exception) {
            $this->assertSame(
                <<<'MESSAGE'
                Invalid nested object for property 'address':
                  - Invalid type for 'zip': requires 'int', got 'double'
                MESSAGE,
                $exception->getMessage(),
            );
        }

        // the ref's minimum: 0 constraint is preserved after the integer narrowing above
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(
            <<<'MESSAGE'
            Invalid nested object for property 'address':
              - Value for 'zip' must not be smaller than 0
            MESSAGE,
        );

        new $className(['address' => ['zip' => -1]]);
    }

    // -------------------------------------------------------------------------
    // Structural siblings require $ref to resolve to object — SchemaException
    // -------------------------------------------------------------------------

    /**
     * Draft 2019-09+: when a property-level schema has structural sibling keywords
     * ('properties') alongside a $ref that resolves to a non-object type (string),
     * schema generation must fail with a SchemaException: these are contradictory constraints.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testPropertyLevelStructuralSiblingWithNonObjectRefThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/Property 'person' in file '.*': sibling structural object keywords"
            . ".*require the \\\$ref to resolve to an object/s",
        );

        $this->generateClassFromFile('PropertyLevelStructuralSiblingNonObjectRef.json');
    }

    // -------------------------------------------------------------------------
    // Property-level object ref with structural siblings — Draft 07 ignores siblings
    // -------------------------------------------------------------------------

    /**
     * Draft 07: sibling 'properties' and 'required' are silently ignored. The generated property
     * uses only the $ref's schema — so 'city' does not exist on the address object, and only
     * 'street' (from the ref) is required.
     */
    #[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
    public function testPropertyLevelObjectDraft07SiblingPropertiesIgnored(): void
    {
        $className = $this->generateClassFromFile('PropertyLevelObjectMerge.json');

        // Only the ref's properties exist: street (required), zip (optional). No city.
        $object = new $className(['address' => ['street' => 'Main St']]);

        $address = $object->getAddress();
        $this->assertSame('Main St', $address->getStreet());
        $this->assertNull($address->getZip());
        $this->assertFalse(method_exists($address, 'getCity'));
    }

    #[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
    public function testPropertyLevelObjectDraft07MissingRequiredRefFieldThrows(): void
    {
        $className = $this->generateClassFromFile(
            'PropertyLevelObjectMerge.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        $this->expectException(ErrorRegistryException::class);

        // 'street' is still required (from the ref) even when siblings are ignored.
        new $className(['address' => ['city' => 'Berlin']]);
    }
}
