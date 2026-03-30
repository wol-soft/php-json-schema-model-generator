<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use JsonSerializable;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Interfaces\SerializationInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;

/**
 * Class WriteOnlyTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class WriteOnlyTest extends AbstractPHPModelGeneratorTestCase
{
    public function testWriteOnlyPropertyDoesntGenerateGetter(): void
    {
        $className = $this->generateClassFromFile(
            'WriteOnly.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className([]);

        // writeOnly: true — setter present, getter absent
        $this->assertFalse(is_callable([$object, 'getWriteOnlyTrue']));
        $this->assertTrue(is_callable([$object, 'setWriteOnlyTrue']));

        // writeOnly: false — both getter and setter present
        $this->assertTrue(is_callable([$object, 'getWriteOnlyFalse']));
        $this->assertTrue(is_callable([$object, 'setWriteOnlyFalse']));

        // no writeOnly — both getter and setter present
        $this->assertTrue(is_callable([$object, 'getNoWriteOnly']));
        $this->assertTrue(is_callable([$object, 'setNoWriteOnly']));
    }

    public function testWriteOnlyPropertyWithGlobalImmutabilityHasNoGetterAndNoSetter(): void
    {
        // global immutability suppresses setters for all properties;
        // writeOnly suppresses getters — result: neither getter nor setter
        $className = $this->generateClassFromFile('WriteOnly.json');

        $object = new $className([]);

        $this->assertFalse(is_callable([$object, 'getWriteOnlyTrue']));
        $this->assertFalse(is_callable([$object, 'setWriteOnlyTrue']));
    }

    public function testWriteOnlyAndReadOnlyOnSamePropertyThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/cannot be both readOnly and writeOnly/');

        $this->generateClassFromFile(
            'WriteOnlyAndReadOnly.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );
    }

    public function testWriteOnlyRequiredPropertyIsInitialisedViaConstructor(): void
    {
        $className = $this->generateClassFromFile(
            'WriteOnlyRequired.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['secret' => 'topsecret']);

        $this->assertFalse(is_callable([$object, 'getSecret']));
        $this->assertTrue(is_callable([$object, 'setSecret']));
    }

    public function testWriteOnlyNullablePropertyHasNoGetter(): void
    {
        $className = $this->generateClassFromFile(
            'WriteOnlyNullable.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className([]);

        $this->assertFalse(is_callable([$object, 'getSecret']));
        $this->assertTrue(is_callable([$object, 'setSecret']));
    }

    public function testWriteOnlyPropertyIsExcludedFromSerialization(): void
    {
        $className = $this->generateClassFromFile(
            'WriteOnly.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className([
            'writeOnlyTrue' => 'hidden',
            'writeOnlyFalse' => 'visible',
            'noWriteOnly' => 'also visible',
        ]);

        $this->assertInstanceOf(SerializationInterface::class, $object);
        $this->assertInstanceOf(JsonSerializable::class, $object);

        $result = $object->toArray();

        $this->assertArrayNotHasKey('writeOnlyTrue', $result);
        $this->assertArrayHasKey('writeOnlyFalse', $result);
        $this->assertArrayHasKey('noWriteOnly', $result);
    }

    public function testWriteOnlyPropertyIsExcludedFromJsonSerialize(): void
    {
        $className = $this->generateClassFromFile(
            'WriteOnly.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className([
            'writeOnlyTrue' => 'hidden',
            'writeOnlyFalse' => 'visible',
            'noWriteOnly' => 'also visible',
        ]);

        $result = $object->jsonSerialize();

        $this->assertArrayNotHasKey('writeOnlyTrue', $result);
        $this->assertArrayHasKey('writeOnlyFalse', $result);
        $this->assertArrayHasKey('noWriteOnly', $result);
    }

    public function testWriteOnlySetterUpdatesInternalState(): void
    {
        $className = $this->generateClassFromFile(
            'WriteOnlyRequired.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['secret' => 'initial']);

        // setter must work and return static
        $this->assertSame($object, $object->setSecret('updated'));
    }
}
