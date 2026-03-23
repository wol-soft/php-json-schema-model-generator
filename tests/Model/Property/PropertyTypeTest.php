<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Model\Property;

use PHPModelGenerator\Model\Property\PropertyType;
use PHPUnit\Framework\TestCase;

class PropertyTypeTest extends TestCase
{
    public function testSingleNameConstructionStoresOneElement(): void
    {
        $t = new PropertyType('string');

        $this->assertSame(['string'], $t->getNames());
    }

    public function testSingleNameConstructionIsNotAUnion(): void
    {
        $t = new PropertyType('string');

        $this->assertFalse($t->isUnion());
    }

    public function testArrayConstructionStoresAllNames(): void
    {
        $t = new PropertyType(['int', 'string']);

        $this->assertSame(['int', 'string'], $t->getNames());
    }

    public function testArrayConstructionIsAUnion(): void
    {
        $t = new PropertyType(['int', 'string']);

        $this->assertTrue($t->isUnion());
    }

    public function testSingleElementArrayIsNotAUnion(): void
    {
        $t = new PropertyType(['int']);

        $this->assertFalse($t->isUnion());
    }

    public function testDuplicateNamesAreDeduplicatedToSingleElement(): void
    {
        $t = new PropertyType(['int', 'int']);

        $this->assertSame(['int'], $t->getNames());
        $this->assertFalse($t->isUnion());
    }

    public function testDuplicateNamesAreDeduplicatedInMultiTypeArray(): void
    {
        $t = new PropertyType(['int', 'string', 'int']);

        $this->assertSame(['int', 'string'], $t->getNames());
        $this->assertTrue($t->isUnion());
    }

    public function testNullableNullByDefault(): void
    {
        $t = new PropertyType('int');

        $this->assertNull($t->isNullable());
    }

    public function testNullableTrue(): void
    {
        $t = new PropertyType('int', true);

        $this->assertTrue($t->isNullable());
    }

    public function testNullableFalse(): void
    {
        $t = new PropertyType('int', false);

        $this->assertFalse($t->isNullable());
    }

    public function testNullablePassthroughWithUnionType(): void
    {
        $t = new PropertyType(['int', 'string'], null);

        $this->assertNull($t->isNullable());
    }

    public function testNullablePassthroughWithUnionTypeTrue(): void
    {
        $t = new PropertyType(['int', 'string'], true);

        $this->assertTrue($t->isNullable());
    }
}
