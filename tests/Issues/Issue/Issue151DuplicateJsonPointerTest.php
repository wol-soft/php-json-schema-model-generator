<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Attributes\JsonPointer;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use ReflectionClass;

/**
 * PropertyAttributeSynthesizer::synthesiseJsonPointerAttributes() calls filterAttributes()
 * followed by addAttribute() on the outer transferred property once per composition validator
 * that defines it (once for allOf, once for anyOf, ...). When that property is a PropertyProxy,
 * addAttribute() stores the new attribute on the proxy's own local attribute array, but
 * filterAttributes() only clears the shared underlying property's array and never touches the
 * proxy's local one. So the second synthesis pass appends another #[JsonPointer] instead of
 * replacing the one added by the first pass, leaving two on the final property.
 *
 * warmup consumes the first (non-proxy) resolution of the shared $ref, so both allOf's and
 * anyOf's "foo" resolve as PropertyProxy instances referencing it, and the merged outer property
 * registered on the schema is itself a PropertyProxy.
 */
class Issue151DuplicateJsonPointerTest extends AbstractPHPModelGeneratorTestCase
{
    public function testRefPropertyInMultipleCompositionsDoesNotGetDuplicateJsonPointer(): void
    {
        $schema = json_encode([
            'type' => 'object',
            '$defs' => [
                'Foo' => [
                    'type' => 'string',
                ],
            ],
            'properties' => [
                'warmup' => [
                    '$ref' => '#/$defs/Foo',
                ],
            ],
            'allOf' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'foo' => [
                            '$ref' => '#/$defs/Foo',
                        ],
                    ],
                ],
            ],
            'anyOf' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'foo' => [
                            '$ref' => '#/$defs/Foo',
                        ],
                    ],
                    'required' => ['foo'],
                ],
            ],
        ]);

        $className = $this->generateClass($schema);
        $reflection = new ReflectionClass($className);

        $this->assertCount(
            1,
            $reflection->getProperty('foo')->getAttributes(JsonPointer::class),
            'foo must carry exactly one JsonPointer attribute, not one per composition validator ' .
                'that defines it',
        );
    }
}
