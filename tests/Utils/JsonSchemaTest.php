<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Utils;

use PHPModelGenerator\Attributes\JsonPointer;
use PHPModelGenerator\Model\Attributes\PhpAttribute;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Utils\JsonSchema as JsonSchemaUtil;
use PHPUnit\Framework\TestCase;

class JsonSchemaTest extends TestCase
{
    /**
     * A property declared in several locations carries one #[JsonPointer] attribute per location: the first one is
     * reported, not the pointer of the JsonSchema the property merger picked.
     */
    public function testPrimaryJsonPointerIsTheFirstJsonPointerAttribute(): void
    {
        $property = new Property('name', null, new JsonSchema('schema.json', [], '/allOf/1/properties/name'));
        $property->addAttribute(new PhpAttribute(JsonPointer::class, ['/properties/name']));
        $property->addAttribute(new PhpAttribute(JsonPointer::class, ['/allOf/1/properties/name']));

        $this->assertSame('/properties/name', JsonSchemaUtil::resolvePrimaryJsonPointer($property));
    }

    /**
     * The schema processor stamps the attribute on every property it creates, a property added by a custom post
     * processor has none: its pointer is the pointer of its JsonSchema.
     */
    public function testPrimaryJsonPointerFallsBackToThePointerOfThePropertySchema(): void
    {
        $property = new Property('injected', null, new JsonSchema('schema.json', [], '/properties/injected'));

        $this->assertSame('/properties/injected', JsonSchemaUtil::resolvePrimaryJsonPointer($property));
    }
}
