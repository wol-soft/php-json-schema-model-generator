<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests;

use PHPModelGenerator\Attributes\JsonPointer;
use ReflectionClass;

/**
 * B5: Cache key includes pointer so inline schemas at different positions
 * produce distinct Schema objects with correct class-level #[JsonPointer].
 *
 * Without the fix (upstream/master): $schemaSignature = $jsonSchema->getSignature()
 * Two identical inline objects at /properties/a and /properties/b share ONE
 * Schema → the class-level #[JsonPointer] is wrong for the second one.
 *
 * With the fix: $schemaSignature = $jsonSchema->getSignature() . '|' . $jsonSchema->getPointer()
 * Each position gets its own Schema with correct #[JsonPointer].
 *
 * Additionally, the processedMergedProperties dedup also includes the pointer
 * to prevent merge class collisions for identical composition content.
 */
class B5ClassNameCompoundingTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * Two identical inline objects at different positions must get distinct
     * class-level #[JsonPointer] attributes. With originalClassNames=true,
     * no uniqid is added, so the collision would happen without the fix.
     */
    public function testIdenticalInlineObjectsAtDifferentPositionsGetDistinctPointers(): void
    {
        $className = $this->generateClassFromFile(
            'DuplicateInlineObjects.json',
            null,
            true,  // originalClassNames - no uniqid
        );

        $object = new $className(['a' => ['x' => 'hello'], 'b' => ['x' => 'world']]);

        $aRef = new ReflectionClass($object->getA());
        $bRef = new ReflectionClass($object->getB());

        // On upstream (without fix): a and b share the SAME class
        // On fix: a and b get DIFFERENT classes
        $this->assertNotSame(
            $aRef->getName(),
            $bRef->getName(),
            'Identical inline schemas at different positions must produce distinct classes',
        );

        // Each class must have its own JsonPointer
        $aPointer = $aRef->getAttributes(JsonPointer::class);
        $bPointer = $bRef->getAttributes(JsonPointer::class);
        $this->assertCount(1, $aPointer);
        $this->assertCount(1, $bPointer);
        $this->assertSame('/properties/a', $aPointer[0]->getArguments()[0]);
        $this->assertSame('/properties/b', $bPointer[0]->getArguments()[0]);
    }

    /**
     * $ref targets must still share the same class (the pointer is always
     * the definition location, so the cache key is the same).
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
                    'properties' => [
                        'value' => ['type' => 'string'],
                    ],
                ],
            ],
        ]);

        $className = $this->generateClass($schema);
        $object = new $className(['a' => ['value' => 'hello'], 'b' => ['value' => 'world']]);

        // $ref targets should share ONE class (same definition location)
        $this->assertSame(get_class($object->getA()), get_class($object->getB()));
    }
}
