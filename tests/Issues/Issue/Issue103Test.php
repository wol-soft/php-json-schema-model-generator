<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

/**
 * Serialization must use original JSON Schema property names as output keys, not the
 * normalized PHP property names.
 *
 * @see https://github.com/wol-soft/php-json-schema-model-generator/issues/103
 */
class Issue103Test extends AbstractIssueTestCase
{
    private function serializationConfig(bool $implicitNull = false): GeneratorConfiguration
    {
        return (new GeneratorConfiguration())
            ->setSerialization(true)
            ->setImmutable(false)
            ->setImplicitNull($implicitNull);
    }

    /**
     * Schema-name serialization: toArray, toJSON, jsonSerialize all use the original JSON Schema
     * property names as output keys. The $except list takes schema names; passing a PHP camelCase
     * name must NOT suppress the property. Custom serializer methods must also land under the
     * schema-name key.
     */
    public function testSchemaNameSerializationOutputAndExcept(): void
    {
        $className = $this->generateClassFromFile(
            'NonCamelCaseProperties.json',
            $this->serializationConfig(),
            false,
            false,
        );

        $object = new $className(['product_id' => 'abc-123']);

        // toArray uses schema name key
        $result = $object->toArray();
        $this->assertArrayHasKey('product_id', $result);
        $this->assertSame('abc-123', $result['product_id']);
        $this->assertArrayNotHasKey('productId', $result);

        // toJSON uses schema name key
        $decoded = json_decode($object->toJSON(), true);
        $this->assertArrayHasKey('product_id', $decoded);
        $this->assertSame('abc-123', $decoded['product_id']);
        $this->assertArrayNotHasKey('productId', $decoded);

        // jsonSerialize uses schema name key
        $jsResult = $object->jsonSerialize();
        $this->assertArrayHasKey('product_id', $jsResult);
        $this->assertArrayNotHasKey('productId', $jsResult);

        // $except takes schema name; PHP camelCase does NOT suppress
        $exceptResult = $object->toArray(['productId']);
        $this->assertArrayHasKey('product_id', $exceptResult);

        // Custom serializer: method serializeProductId is called; result lands under 'product_id'
        $subclassName = 'CustomSerializer103_' . md5($className);
        if (!class_exists($subclassName)) {
            eval("class $subclassName extends $className {
                protected function serializeProductId() {
                    return strtoupper(\$this->productId);
                }
            }");
        }

        $custom = new $subclassName(['product_id' => 'abc']);
        $customResult = $custom->toArray();
        $this->assertArrayHasKey('product_id', $customResult);
        $this->assertSame('ABC', $customResult['product_id']);
        $this->assertArrayNotHasKey('productId', $customResult);
    }

    /**
     * Kebab/space schema names, round-trip, and $except-with-schema-name, tested together because
     * they all require implicitNull=true on the same schema file.
     */
    public function testKebabSpaceNamesRoundTripAndExcept(): void
    {
        $className = $this->generateClassFromFile(
            'NonCamelCaseProperties.json',
            $this->serializationConfig(true),
            false,
            true,
        );

        $input = [
            'product_id'  => 'sku-99',
            'my-thing'    => 'foo',
            'my property' => 'bar',
        ];
        $object = new $className($input);
        $result = $object->toArray();

        // Kebab and space schema names preserved in output
        $this->assertArrayHasKey('my-thing', $result);
        $this->assertSame('foo', $result['my-thing']);
        $this->assertArrayNotHasKey('myThing', $result);

        $this->assertArrayHasKey('my property', $result);
        $this->assertSame('bar', $result['my property']);
        $this->assertArrayNotHasKey('myProperty', $result);

        // Round-trip: serialized output feeds back into the constructor without errors
        $reconstructed = new $className($result);
        $this->assertSame($result, $reconstructed->toArray());

        // $except with schema name suppresses the property
        $exceptResult = $object->toArray(['product_id']);
        $this->assertArrayNotHasKey('product_id', $exceptResult);
        $this->assertArrayHasKey('my-thing', $exceptResult);
    }

    /**
     * skipNotProvidedPropertiesMap regression: an optional property that was not supplied must be
     * absent from the output; it must appear once set via the setter.
     *
     * Previously broken because skipNotProvidedPropertiesMap stored PHP attribute names while
     * rawModelDataInput is keyed by schema names, so the array_diff comparison never matched.
     */
    public function testOptionalPropertySkippedWhenAbsentAppearsAfterSetter(): void
    {
        $className = $this->generateClassFromFile(
            'OptionalNonCamelCaseProperty.json',
            $this->serializationConfig(false),
            false,
            false,
        );

        // Only the required field is provided; product_id must be absent
        $object = new $className(['required_field' => 'hello']);
        $result = $object->toArray();
        $this->assertArrayHasKey('required_field', $result);
        $this->assertArrayNotHasKey('product_id', $result);
        $this->assertArrayNotHasKey('productId', $result);

        // After setter, the property appears under its schema name
        $object->setProductId('world');
        $this->assertSame('world', $object->toArray()['product_id']);
    }

    /**
     * Nested objects serialize using schema names at every level. Depth budget propagates
     * correctly across nested models. The capability cache is not poisoned by an earlier
     * depth-exhausted call.
     */
    public function testNestedObjectsDepthBudgetAndCapabilityCache(): void
    {
        $className = $this->generateClassFromFile(
            'NestedNonCamelCaseObjects.json',
            $this->serializationConfig(true),
            false,
            true,
        );

        $object = new $className([
            'product_id'    => 'sku-1',
            'nested_object' => ['inner_value' => 'hello'],
        ]);

        // Full serialization: schema names at every level
        $full = $object->toArray();
        $this->assertArrayHasKey('product_id', $full);
        $this->assertArrayNotHasKey('productId', $full);
        $this->assertArrayHasKey('nested_object', $full);
        $this->assertArrayNotHasKey('nestedObject', $full);
        $this->assertArrayHasKey('inner_value', $full['nested_object']);
        $this->assertArrayNotHasKey('innerValue', $full['nested_object']);
        $this->assertSame('hello', $full['nested_object']['inner_value']);

        // depth=1: nested object budget exhausted → null
        $atDepth1 = $object->toArray([], 1);
        $this->assertSame('sku-1', $atDepth1['product_id']);
        $this->assertNull($atDepth1['nested_object']);

        // depth=2: one nesting level fully serialized
        $atDepth2 = $object->toArray([], 2);
        $this->assertSame('sku-1', $atDepth2['product_id']);
        $this->assertIsArray($atDepth2['nested_object']);
        $this->assertSame('hello', $atDepth2['nested_object']['inner_value']);

        // Capability cache must not be poisoned by the earlier depth-1 call: calling at
        // default depth after a depth-exhausted call must still serialize correctly.
        $afterPoison = $object->toArray();
        $this->assertIsArray($afterPoison['nested_object']);
        $this->assertSame('hello', $afterPoison['nested_object']['inner_value']);
    }
}
