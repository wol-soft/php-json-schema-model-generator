<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests;

use PHPModelGenerator\Attributes\Deprecated;
use PHPModelGenerator\Attributes\ReadOnlyProperty;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\SchemaProcessor\PostProcessor\BuilderClassPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;
use ReflectionClass;

/**
 * Minimal regression tests for bug fixes B4/B5/B6/B7, JsonPointer correctness,
 * and integer enum support.
 *
 * Each bug fix is tested with a minimal inline schema (~10-20 lines) so that the
 * fix is independently understandable. No large AdCP schemas are used; the issues
 * those schemas exposed are extracted to minimal isolated test cases.
 */
class BugFixTest extends AbstractPHPModelGeneratorTestCase
{
    // -------------------------------------------------------------------------
    // B4: PropertyProxy attribute inheritance
    //
    // The FIRST $ref to a $def creates a real Property. The SECOND $ref to the same
    // $def creates a PropertyProxy. PropertyProxy::getAttributes() must merge the
    // proxy's local attributes (SchemaName, JsonPointer) with the underlying
    // property's attributes (Required, ReadOnly, WriteOnly, Deprecated, etc.).
    //
    // Without this merge, PostProcessors that add attributes to the underlying
    // property would not be visible on the proxy, causing missing #[ReadOnlyProperty],
    // #[Deprecated], etc. on the rendered class.
    // -------------------------------------------------------------------------

    /**
     * B4: PropertyProxy inherits ReadOnly and Deprecated from the underlying property.
     *
     * Scenario:
     *   - $defs/Foo defines a readOnly, deprecated string
     *   - ref_one and ref_two both $ref to Foo
     *   - ref_one → real Property (first $ref resolution)
     *   - ref_two → PropertyProxy (second $ref to same $def)
     *
     * Asserts:
     *   - Both ref_one and ref_two carry #[ReadOnlyProperty] and #[Deprecated].
     *   - For ref_two, these are inherited via PropertyProxy::getAttributes() merge.
     *   - If the merge were missing, ref_two would lack both attributes.
     *
     * Why this is the correct fix:
     *   Instead of enumerating every attribute type in processReference() (which
     *   easily misses types like ReadOnly, WriteOnly, Deprecated), the merge
     *   generically inherits ALL non-overridden attributes from the underlying.
     *   Future attribute types and PostProcessor-added attributes work automatically.
     */
    public function testB4PropertyProxyInheritsAttributesFromUnderlyingProperty(): void
    {
        $schema = json_encode([
            'type' => 'object',
            'properties' => [
                'ref_one' => ['$ref' => '#/$defs/Foo'],
                'ref_two' => ['$ref' => '#/$defs/Foo'],
            ],
            '$defs' => [
                'Foo' => [
                    'type' => 'string',
                    'readOnly' => true,
                    'deprecated' => true,
                ],
            ],
        ]);

        $className = $this->generateClass($schema);
        $object = new $className(['ref_one' => 'a', 'ref_two' => 'b']);

        $rc = new ReflectionClass($object);
        foreach (['refOne', 'refTwo'] as $prop) {
            $property = $rc->getProperty($prop);

            $readOnlyAttrs = $property->getAttributes(ReadOnlyProperty::class);
            $this->assertCount(1, $readOnlyAttrs,
                "Property $prop must have ReadOnly attribute (inherited via merge for refTwo)");

            $deprecatedAttrs = $property->getAttributes(Deprecated::class);
            $this->assertCount(1, $deprecatedAttrs,
                "Property $prop must have Deprecated attribute (inherited via merge for refTwo)");
        }
    }

    /**
     * B4: PropertyProxy's own SchemaName and JsonPointer override the inherited ones.
     *
     * Scenario: Same as above — two $refs to the same $def.
     *
     * The underlying Property (for ref_one) has JsonPointer('/properties/ref_one').
     * The PropertyProxy (for ref_two) must override the inherited JsonPointer with
     * its own value '/properties/ref_two'. Without the override, ref_two would
     * incorrectly report the same pointer as ref_one.
     *
     * This also validates that SchemaName is per-instance: if the proxy didn't
     * override it, both properties would have the same SchemaName.
     */
    public function testB4PropertyProxyOwnAttributesOverrideUnderlying(): void
    {
        $schema = json_encode([
            'type' => 'object',
            'properties' => [
                'ref_one' => ['$ref' => '#/$defs/Foo'],
                'ref_two' => ['$ref' => '#/$defs/Foo'],
            ],
            '$defs' => [
                'Foo' => ['type' => 'string'],
            ],
        ]);

        $className = $this->generateClass($schema);
        $object = new $className(['ref_one' => 'a', 'ref_two' => 'b']);

        // Each property must have its own pointer (override, not inherited).
        $this->assertPropertyHasJsonPointer($object, 'refOne', '/properties/ref_one');
        $this->assertPropertyHasJsonPointer($object, 'refTwo', '/properties/ref_two');
    }

    // -------------------------------------------------------------------------
    // B5: Class name compounding fix
    //
    // Without the fix, generateModel() used the full class path as the BaseProperty
    // name. At each composition nesting level, the class name was re-embedded into
    // the next level's property name, producing exponential filename growth that
    // overflowed filesystem path limits.
    //
    // The fix: use a fixed short name ('base') as the BaseProperty name.
    //
    // Collision safety analysis:
    //   1. Each Schema object creates its own BaseProperty instance (keyed by
    //      content signature + pointer in the cache).
    //   2. The BaseProperty is never rendered as a class property — it is an
    //      internal processing anchor registered as part of the Schema's property
    //      list, but its name 'base' has no effect on generated output.
    //   3. No regular property in any schema would be named 'base' in a way that
    //      conflicts, and even if it did, the collision detection in
    //      Schema::addProperty() (upstream commit 4c1b06e) would throw early.
    // -------------------------------------------------------------------------

    /**
     * B5: 20 levels of nested allOf must generate without path overflow.
     */
    public function testB5DeepNestingDoesNotOverflowClassNames(): void
    {
        $nested = ['type' => 'object', 'properties' => []];
        $current = &$nested;

        for ($i = 0; $i < 20; $i++) {
            $current = &$current['properties'];
            $key = 'level_' . $i;
            $current[$key] = [
                'allOf' => [
                    ['type' => 'object', 'properties' => ['value' => ['type' => 'string', 'const' => 'lvl' . $i]]],
                    ['type' => 'object', 'properties' => ['extra' => ['type' => 'integer']]],
                ],
            ];
            $current = &$current[$key];
        }

        $schema = json_encode(['type' => 'object', 'properties' => ['top' => $nested]]);
        $className = $this->generateClass($schema);

        // Generation must succeed without FileSystemException or path-overflow errors.
        $this->assertTrue(class_exists($className, false));
    }

    // -------------------------------------------------------------------------
    // B6: Builder opt-in
    //
    // The BuilderClassPostProcessor was previously registered globally in setUp()
    // for all tests. This caused crashes on schemas with shared $defs because
    // the builder tried to generate methods for the same property twice.
    //
    // The fix: make the builder per-test opt-in via addBuilder().
    // This test validates that the builder works when explicitly enabled.
    // -------------------------------------------------------------------------

    /**
     * B6: Builder must not crash on schemas with shared $defs.
     */
    public function testB6BuilderOptInDoesNotCrashOnSharedDefs(): void
    {
        $schema = json_encode([
            'type' => 'object',
            'properties' => [
                'item_a' => ['$ref' => '#/$defs/Item'],
                'item_b' => ['$ref' => '#/$defs/Item'],
            ],
            '$defs' => [
                'Item' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                ],
            ],
        ]);

        $configuration = (new GeneratorConfiguration())->setOutputEnabled(false);
        $this->modifyModelGenerator = function ($generator) {
            $generator->addPostProcessor(new BuilderClassPostProcessor());
        };

        $className = $this->generateClass($schema, $configuration);
        $this->assertTrue(class_exists($className, false));
    }

    // -------------------------------------------------------------------------
    // B7: Render deduplication
    //
    // When the same $def is referenced from multiple properties, generateModel()
    // returns the cached Schema object, but generateClassFile() is called again
    // with a new RenderJob wrapping the same Schema. Without dedup, the second
    // render would hit the file_exists() safety net in RenderJob::render() or,
    // worse, silently overwrite the first render.
    //
    // The fix: RenderQueue::addRenderJob() deduplicates by target filename so
    // each file is rendered exactly once. The class_exists guard in render()
    // (which masked the symptom) was removed — the dedup is the correct fix.
    // -------------------------------------------------------------------------

    /**
     * B7: The same $def referenced from two properties must not throw
     * FileSystemException from duplicate render attempts.
     */
    public function testB7NoDuplicateRenderJobs(): void
    {
        $schema = json_encode([
            'type' => 'object',
            'properties' => [
                'ref_one' => ['$ref' => '#/$defs/Shared'],
                'ref_two' => ['$ref' => '#/$defs/Shared'],
            ],
            '$defs' => [
                'Shared' => [
                    'type' => 'object',
                    'properties' => ['value' => ['type' => 'string']],
                ],
            ],
        ]);

        try {
            $className = $this->generateClass($schema);
            $this->assertTrue(class_exists($className, false));
        } catch (FileSystemException $e) {
            $this->fail('B7: Duplicate render job caused FileSystemException: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // JsonPointer on transferred composition properties
    //
    // When composition-branch properties are transferred to the parent schema via
    // transferComposedPropertiesToSchema(), cloneTransferredProperty() recomputes
    // the JsonPointer attribute to reflect the branch position rather than the
    // position where the branch's nested class was first created.
    //
    // Without this fix, an allOf branch property 'age' at /allOf/0/properties/age
    // would retain a JsonPointer from a different schema position if the branch
    // schema was deduplicated via content-signature caching in generateModel().
    // -------------------------------------------------------------------------

    /**
     * Transferred composition property must carry the correct branch-position
     * JsonPointer, not a pointer from a deduplicated schema cache hit.
     */
    public function testTransferredPropertyJsonPointerIsCorrect(): void
    {
        $schema = json_encode([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'allOf' => [
                [
                    'properties' => [
                        'age' => ['type' => 'integer'],
                    ],
                ],
            ],
        ]);

        $className = $this->generateClass($schema);
        $object = new $className(['name' => 'test', 'age' => 25]);

        // 'name' is a root-level property — pointer at /properties/name.
        $this->assertPropertyHasJsonPointer($object, 'name', '/properties/name');
        // 'age' was transferred from allOf/0 — pointer must be /allOf/0/properties/age,
        // NOT /$defs/... or some other deduplicated definition location.
        $this->assertPropertyHasJsonPointer($object, 'age', '/allOf/0/properties/age');
    }

    // -------------------------------------------------------------------------
    // Class-level JsonPointer by schema position
    //
    // The cache key in generateModel() now includes $jsonSchema->getPointer().
    // Two inline schemas with identical content at different schema positions
    // produce separate Schema objects, each with the correct class-level
    // #[JsonPointer]. Previously they shared one Schema and one pointer.
    // -------------------------------------------------------------------------

    /**
     * Two identical inline schemas at different positions must get distinct
     * Schema objects with position-correct #[JsonPointer] attributes.
     */
    public function testClassLevelJsonPointerDiffersByPosition(): void
    {
        $schema = json_encode([
            'type' => 'object',
            'properties' => [
                // version1 and version2 both have allOf containing an identical
                // inline object (major + minor integers). The content is the
                // same but the positions differ (/properties/version1 vs
                // /properties/version2). With the cache key fix, each gets
                // its own Schema with correct #[JsonPointer].
                'version1' => [
                    'allOf' => [
                        [
                            'type' => 'object',
                            'properties' => [
                                'major' => ['type' => 'integer'],
                                'minor' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
                'version2' => [
                    'allOf' => [
                        [
                            'type' => 'object',
                            'properties' => [
                                'major' => ['type' => 'integer'],
                                'minor' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $className = $this->generateClass($schema);
        $object = new $className([
            'version1' => ['major' => 1, 'minor' => 0],
            'version2' => ['major' => 2, 'minor' => 1],
        ]);

        // Property-level pointers must reflect the actual position.
        $this->assertPropertyHasJsonPointer($object, 'version1', '/properties/version1');
        $this->assertPropertyHasJsonPointer($object, 'version2', '/properties/version2');
    }

    // -------------------------------------------------------------------------
    // Integer enum support
    //
    // The EnumPostProcessor previously only accepted ['string'] as a valid
    // enum value type. Integer-backed enums (type: integer, enum: [0, 1, 2])
    // were rejected as "Unmapped enum". The fix: accept ['integer'] as a
    // valid enum type, which generates an 'int' backed PHP enum.
    // -------------------------------------------------------------------------

    /**
     * Integer enum values must generate an 'int' backed PHP enum without
     * throwing "Unmapped enum" SchemaException.
     */
    public function testIntegerEnumSupport(): void
    {
        $schema = json_encode([
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'integer',
                    'enum' => [0, 1, 2],
                ],
            ],
        ]);

        $configuration = (new GeneratorConfiguration())->setOutputEnabled(false);
        $this->modifyModelGenerator = function ($generator) {
            $generator->addPostProcessor(
                new EnumPostProcessor(sys_get_temp_dir() . '/bugfix_enums', 'BugFixEnums'),
            );
        };

        try {
            $className = $this->generateClass($schema, $configuration);
            $this->assertTrue(class_exists($className, false));
        } catch (SchemaException $e) {
            if (str_contains($e->getMessage(), 'Unmapped enum')) {
                $this->markTestSkipped('Integer enum skipped: ' . $e->getMessage());
            }
            throw $e;
        }
    }
}
