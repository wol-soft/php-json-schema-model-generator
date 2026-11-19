<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Object\InvalidAdditionalPropertiesException;
use PHPModelGenerator\Exception\Object\InvalidPatternPropertiesException;
use PHPModelGenerator\Exception\Object\InvalidPropertyNamesException;
use PHPModelGenerator\Exception\Object\MinPropertiesException;
use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

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

    // -------------------------------------------------------------------------
    // The referenced schema's object-level validators survive the merge (Draft 2019-09+)
    // -------------------------------------------------------------------------

    /**
     * A chain of references in which the middle link adds siblings of its own: the root references `middle`, which
     * references `inner`. `middle` is built through the sibling merge, and the root's base-level reference only passes
     * on what `middle` carries. Every object-level constraint of `inner` (here its `patternProperties` and its
     * `minProperties`) has to survive that, as it does for a single-hop reference; both were silently dropped, so an
     * instance violating them was accepted.
     *
     * The class name inside the messages carries a run-specific suffix.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testChainedRefRetainsTheObjectConstraintsOfTheInnerSchema(): void
    {
        $className = $this->generateClassFromFile(
            'ChainedRefObjectConstraints.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $accepted = ['p_1' => 'ok', 'a' => 'x'];
        $this->assertSame($accepted, (new $className($accepted))->meta()->rawInput());

        try {
            new $className(['p_1' => 123, 'a' => 'x']);
            $this->fail('A value violating the pattern of the inner schema must be rejected');
        } catch (InvalidPatternPropertiesException $exception) {
            $this->assertMatchesRegularExpression(
                <<<'MSG'
                /^Provided JSON for '\S+' contains invalid pattern properties
                  - invalid property 'p_1' matching pattern '\^p_'
                    \* Invalid type for 'pattern property': requires 'string', got 'integer'$/
                MSG,
                $exception->getMessage(),
            );
        }

        $this->expectException(MinPropertiesException::class);
        $this->expectExceptionMessageMatches(
            "/^Provided object for '\S+' must not contain less than 2 properties$/",
        );

        new $className(['a' => 'x']);
    }

    /**
     * The composition of the referenced schema is one of its base validators too. Reached through a property-level
     * reference with a sibling `properties`, the merged class lost it: without the `allOf` branch requiring `x` an
     * object lacking `x` was accepted.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testPropertyLevelRefToCompositionRetainsTheCompositionAfterTheMerge(): void
    {
        $className = $this->generateClassFromFile(
            'RefToCompositionWithSiblings.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $accepted = ['nested' => ['x' => 'a', 'y' => 's']];
        $this->assertSame($accepted, (new $className($accepted))->meta()->rawInput());

        $this->expectException(NestedObjectException::class);
        $this->expectExceptionMessageMatches(<<<'MSG'
        /^Invalid nested object for property 'nested':
          - Invalid value for '\S+' declined by composition constraint
              Requires to match all composition elements but matched 0 elements
              - Composition element #1: Failed
                \* Missing required value for 'x'$/
        MSG);

        new $className(['nested' => ['y' => 's']]);
    }

    /**
     * One definition used as a class of its own (`plain`) and, next to that, merged with sibling properties
     * (`nested`). The merge transfers the composition validator of the definition onto the merged class, so one
     * validator instance is rendered into two classes. The helper method it needs was registered on whichever class
     * was post-processed last, and the class of the plain usage called a method it did not define: its first valid
     * value ended in an Error.
     *
     * The class name inside the messages carries a run-specific suffix.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testReferencedCompositionStaysUsableAsAClassWhenItIsAlsoMergedWithSiblings(): void
    {
        $className = $this->generateClassFromFile(
            'RefToCompositionPlainAndMerged.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $plain = ['plain' => ['x' => 'a']];
        $this->assertSame($plain, (new $className($plain))->meta()->rawInput());

        $merged = ['nested' => ['x' => 'a', 'y' => 'b']];
        $this->assertSame($merged, (new $className($merged))->meta()->rawInput());

        try {
            new $className(['nested' => ['y' => 'b']]);
            $this->fail('The merged class has to enforce the composition of the referenced schema');
        } catch (NestedObjectException $exception) {
            $this->assertMatchesRegularExpression(
                <<<'MSG'
                /^Invalid nested object for property 'nested':
                  - Invalid value for '\S+' declined by composition constraint
                      Requires to match all composition elements but matched 0 elements
                      - Composition element #1: Failed
                        \* Missing required value for 'x'$/
                MSG,
                $exception->getMessage(),
            );
        }

        $this->expectException(NestedObjectException::class);
        $this->expectExceptionMessageMatches(<<<'MSG'
        /^Invalid nested object for property 'plain':
          - Invalid value for '\S+' declined by composition constraint
              Requires to match all composition elements but matched 0 elements
              - Composition element #1: Failed
                \* Missing required value for 'x'$/
        MSG);

        new $className(['plain' => ['y' => 'b']]);
    }

    /**
     * The base-level reference transfers the composition validator onto the referencing class in the same way, so
     * the same definition used as a class of its own next to it hit the same missing helper method.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testReferencedCompositionStaysUsableAsAClassWhenTheRootReferencesItToo(): void
    {
        $className = $this->generateClassFromFile(
            'RefToCompositionPlainAndBaseLevel.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $accepted = ['x' => 'a', 'plain' => ['x' => 'b']];
        $this->assertSame($accepted, (new $className($accepted))->meta()->rawInput());

        $this->expectException(NestedObjectException::class);
        $this->expectExceptionMessageMatches(<<<'MSG'
        /^Invalid nested object for property 'plain':
          - Invalid value for '\S+' declined by composition constraint
              Requires to match all composition elements but matched 0 elements
              - Composition element #1: Failed
                \* Missing required value for 'x'$/
        MSG);

        new $className(['x' => 'a', 'plain' => ['y' => 'b']]);
    }

    /**
     * Inputs for the two reference paths, with the exception each must raise (null: accepted). The referenced schema
     * constrains its `patternProperties`, `additionalProperties`, `minProperties` and `propertyNames`.
     *
     * @return array<string, array{array<string, mixed>, class-string<ValidationException>|null}>
     */
    public static function objectConstraintInputProvider(): array
    {
        return [
            // satisfies every constraint of the referenced schema
            'valid declared property and pattern match' => [['a' => 'x', 'p_1' => 'ok'], null],
            // the referenced schema requires two properties
            'too few properties' => [['a' => 'x'], MinPropertiesException::class],
            // `p_1` matches `^p_`, whose schema requires a string
            'pattern value violates its schema' => [
                ['a' => 'x', 'p_1' => 123],
                InvalidPatternPropertiesException::class,
            ],
            // an additional property has to be an integer
            'additional property violates its schema' => [
                ['a' => 'x', 'zz' => 'notint'],
                InvalidAdditionalPropertiesException::class,
            ],
            'additional property satisfies its schema' => [['a' => 'x', 'zz' => 5], null],
            // property names have to match `^[a-z_0-9]+$`
            'property name violates the pattern' => [
                ['a' => 'x', 'BAD-NAME' => 5],
                InvalidPropertyNamesException::class,
            ],
            // `c` is declared by the referencing schema only. The `additionalProperties` of the referenced schema
            // does not know it, so by the spec it is an additional property there and has to be an integer.
            'property declared by the sibling is additional for the referenced schema' => [
                ['c' => 'y', 'a' => 'x'],
                InvalidAdditionalPropertiesException::class,
            ],
        ];
    }

    /**
     * A base-level `$ref` with a sibling `properties` and the same combination on a property are two assembly paths
     * for the same keyword combination, so they must produce identical outcomes for the same instance. The property
     * level path transferred only the properties of the referenced schema and accepted every input below, whichever
     * constraint it violated.
     *
     * Each validator transferred from the referenced schema renders once into the merged class.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testBaseLevelAndPropertyLevelRefWithSiblingsApplyTheSameObjectConstraints(): void
    {
        $configuration = (new GeneratorConfiguration())->setCollectErrors(false);

        $baseLevelClass = $this->generateClassFromFile('RefObjectConstraintsBaseLevel.json', $configuration);
        $propertyLevelClass = $this->generateClassFromFile('RefObjectConstraintsPropertyLevel.json', $configuration);

        foreach (self::objectConstraintInputProvider() as $label => [$input, $expectedException]) {
            $baseLevelException = $this->catchException(static fn(): object => new $baseLevelClass($input));
            $propertyLevelException = $this->catchException(
                static fn(): object => new $propertyLevelClass(['nested' => $input]),
            );

            if ($expectedException === null) {
                $this->assertNull($baseLevelException, "Base level path must accept: $label");
                $this->assertNull($propertyLevelException, "Property level path must accept: $label");

                continue;
            }

            $this->assertInstanceOf($expectedException, $baseLevelException, "Base level path: $label");
            $this->assertInstanceOf(NestedObjectException::class, $propertyLevelException, "Property level: $label");
            $this->assertInstanceOf(
                $expectedException,
                $propertyLevelException->getNestedException(),
                "Property level path: $label",
            );
        }

        $mergedClass = (new $propertyLevelClass(['nested' => ['a' => 'x', 'p_1' => 'ok']]))->getNested()::class;
        $mergedSource = file_get_contents((new ReflectionClass($mergedClass))->getFileName());

        $this->assertSame(1, substr_count($mergedSource, '\'/$defs/inner/patternProperties/^p_/type\''));
        $this->assertSame(1, substr_count($mergedSource, '\'/$defs/inner/additionalProperties/type\''));
    }
}
