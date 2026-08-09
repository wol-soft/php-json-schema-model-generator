<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\ErrorRegistryException;
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
    // Property-level object×object merge (Draft 2019-09+)
    // -------------------------------------------------------------------------

    /**
     * Draft 2019-09+: a property-level {$ref→object, properties: {city}} merges into a single
     * nested class containing all properties from the ref (street, zip) and the sibling (city).
     * Both 'street' (from ref) and 'city' (from sibling required) must be present.
     */
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
     * integer), the ref's own range constraint (minimum: 0) must still be enforced against the
     * narrowed type — narrowing must not silently drop constraints the ref side declared.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testPropertyLevelObjectCollisionTypeNarrowingPreservesRefConstraints(): void
    {
        $className = $this->generateClassFromFile('PropertyLevelObjectCollisionTypeNarrowing.json');

        $object = new $className(['address' => ['zip' => 5]]);
        $this->assertSame(5, $object->getAddress()->getZip());

        // float not accepted after narrowing to integer
        $this->expectException(ValidationException::class);

        new $className(['address' => ['zip' => 1.5]]);
    }

    /**
     * Draft 2019-09+: the ref's minimum: 0 constraint is preserved after the integer
     * narrowing above — a separate test since testPropertyLevelObjectCollisionTypeNarrowingPreservesRefConstraints()
     * already asserts on the type-check exception and can't also assert on the minimum one.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testPropertyLevelObjectCollisionTypeNarrowingEnforcesRefMinimum(): void
    {
        $className = $this->generateClassFromFile('PropertyLevelObjectCollisionTypeNarrowing.json');

        $this->expectException(ValidationException::class);

        new $className(['address' => ['zip' => -1]]); // violates ref minimum: 0
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
