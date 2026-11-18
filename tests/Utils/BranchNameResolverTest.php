<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Utils;

use PHPModelGenerator\Utils\BranchNameResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BranchNameResolverTest extends TestCase
{
    #[DataProvider('pointerProvider')]
    public function testResolve(string $pointer, string $expectedName): void
    {
        $this->assertSame($expectedName, BranchNameResolver::resolve($pointer));
    }

    public static function pointerProvider(): array
    {
        return [
            'root of a file' => ['', 'Branch'],
            'root level composition branch' => ['/allOf/0/anyOf/1', 'Branch'],
            'property' => ['/properties/order', 'order'],
            'branch of a property' => ['/properties/order/allOf/0', 'order'],
            'nested branches of a property' => ['/properties/order/allOf/0/oneOf/1/anyOf/0', 'order'],
            'property nested in a branch' => ['/properties/order/allOf/0/properties/item/anyOf/1', 'item'],
            'definition' => ['/definitions/address', 'address'],
            'defs' => ['/$defs/address/allOf/0', 'address'],
            'property called like a slot keyword' => ['/properties/properties/allOf/0', 'properties'],
            'property called like a composition keyword' => ['/properties/allOf/allOf/0', 'allOf'],
            'property with a numeric name' => ['/properties/0/allOf/0', '0'],
            'array items' => ['/properties/tags/items/allOf/0', 'tags'],
            'conditional branch' => ['/properties/order/then', 'order'],
            'slot keyword without a key' => ['/properties', 'Branch'],
        ];
    }
}
