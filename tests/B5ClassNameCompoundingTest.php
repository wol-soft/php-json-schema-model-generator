<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests;

/**
 * Regression test for B5: class name compounding fix.
 *
 * Without the fix, generateModel() used the full class path as the BaseProperty
 * name. At each composition nesting level, the class name was re-embedded into
 * the next level's property name, producing exponential filename growth that
 * overflowed filesystem path limits. The fix uses 'base' as the fixed name.
 *
 * Collision safety:
 *   1. Each Schema creates its own BaseProperty (keyed by content+pointer in cache).
 *   2. The BaseProperty is never rendered as a class property.
 *   3. The cache key also includes $jsonSchema->getPointer() so inline schemas
 *      at different positions get distinct Schema objects with correct
 *      class-level #[JsonPointer].
 */
class B5ClassNameCompoundingTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * B5 + cache key: Deep nesting does not produce exponentially growing class names.
     */
    public function testDeepNestingDoesNotOverflowClassNames(): void
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

        $this->assertTrue(class_exists($className, false));
    }

    /**
     * Cache key fix: Two identical inline schemas at different positions produce
     * distinct Schema objects, each with correct class-level #[JsonPointer].
     */
    public function testIdenticalInlineContentAtDifferentPositionsGetDistinctPointers(): void
    {
        $schema = json_encode([
            'type' => 'object',
            'properties' => [
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

        $this->assertPropertyHasJsonPointer($object, 'version1', '/properties/version1');
        $this->assertPropertyHasJsonPointer($object, 'version2', '/properties/version2');
    }

    /**
     * Cache key fix: $ref targets still share the same class (pointer is definition
     * location, not reference site).
     */
    public function testRefTargetsStillShareSchema(): void
    {
        $schema = json_encode([
            'type' => 'object',
            'properties' => [
                'a' => ['$ref' => '#/$defs/Foo'],
                'b' => ['$ref' => '#/$defs/Foo'],
            ],
            '$defs' => [
                'Foo' => [
                    'type' => 'object',
                    'properties' => ['x' => ['type' => 'string']],
                ],
            ],
        ]);

        $className = $this->generateClass($schema);
        $object = new $className(['a' => ['x' => 'hello'], 'b' => ['x' => 'world']]);

        // Both properties should reference the SAME generated class for Foo
        $this->assertSame($object->getA()::class, $object->getB()::class);
    }
}
