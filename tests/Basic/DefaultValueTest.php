<?php

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

/**
 * Class DefaultValueTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class DefaultValueTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @dataProvider defaultValueForTypedPropertyDataProvider
     *
     * @param string $valueType
     * @param mixed  $defaultValue
     * @param mixed  $compareValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testDefaultValueForTypedProperty(string $valueType, $defaultValue, $compareValue): void
    {
        $className = $this->generateClassFromFileTemplate(
            'DefaultValueTypedProperty.json',
            [$valueType, $defaultValue],
            null,
            false
        );

        $object = new $className([]);

        $this->assertSame($object->getProperty(), $compareValue);
    }

    public function testTypedPropertyTypeHintsWithImplicitNullEnabledAcceptNull(): void
    {
        $className = $this->generateClassFromFileTemplate(
            'DefaultValueTypedProperty.json',
            ['"integer"', 0],
            (new GeneratorConfiguration())->setImmutable(false),
            false
        );

        $object = new $className([]);

        $this->assertSame('int|null', $this->getPropertyTypeAnnotation($object, 'property'));

        $this->assertSame('int|null', $this->getMethodReturnTypeAnnotation($object, 'getProperty'));
        $returnType = $this->getReturnType($object, 'getProperty');
        $this->assertSame('int', $returnType->getName());
        // as implicit null is enabled the default value may be overwritten by a null value
        $this->assertTrue($returnType->allowsNull());

        $this->assertSame('int|null', $this->getMethodParameterTypeAnnotation($object, 'setProperty'));
        $parameterType = $this->getParameterType($object, 'setProperty');
        $this->assertSame('int', $parameterType->getName());
        // as implicit null is enabled the default value may be overwritten by a null value
        $this->assertTrue($parameterType->allowsNull());
    }

    public function testTypedPropertyTypeHintsWithImplicitNullDisabledDeclinesNull(): void
    {
        $className = $this->generateClassFromFileTemplate(
            'DefaultValueTypedProperty.json',
            ['"integer"', 0],
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            false
        );

        $object = new $className([]);

        $this->assertSame('int', $this->getPropertyTypeAnnotation($object, 'property'));

        $this->assertSame('int', $this->getMethodReturnTypeAnnotation($object, 'getProperty'));
        $returnType = $this->getReturnType($object, 'getProperty');
        $this->assertSame('int', $returnType->getName());
        $this->assertFalse($returnType->allowsNull());

        $this->assertSame('int', $this->getMethodParameterTypeAnnotation($object, 'setProperty'));
        $parameterType = $this->getParameterType($object, 'setProperty');
        $this->assertSame('int', $parameterType->getName());
        $this->assertFalse($parameterType->allowsNull());
    }

    public function defaultValueForTypedPropertyDataProvider(): array
    {
        return [
            'int negative value' => ['"integer"', -10, -10],
            'int zero' => ['"integer"', 0, 0],
            'int positive value' => ['"integer"', 10, 10],
            'float negative value' => ['"number"', -10.5, -10.5],
            'float negative int value' => ['"number"', -10, -10.],
            'float zero' => ['"number"', 0., 0.],
            'float positive value' => ['"number"', 10.5, 10.5],
            'float positive int value' => ['"number"', 10, 10.],
            'string empty' => ['"string"', '""', ''],
            'string numeric' => ['"string"', '"123"', '123'],
            'string' => ['"string"', '"Hello"', 'Hello'],
            'bool' => ['"boolean"', 'true', true],
            'array empty' => ['"array"', '[]', []],
            'array no index' => ['"array"', '["a", "b"]', ['a', 'b']],
            'array numeric index' => ['"array"', '{"3": "b", "4": "c"}', [3 => 'b', 4 => 'c']],
            'array associative index' => ['"array"', '{"a": 2, "b": 3}', ['a' => 2, 'b' => 3]],
            'multi type string' => ['["string", "number"]', '"Hey"', 'Hey'],
            // casted to float due to number type
            'multi type int' => ['["string", "number"]', -11, -11.],
            'multi type float' => ['["string", "number"]', 10.5, 10.5],
        ];
    }

    /**
     * @dataProvider invalidDefaultValueForTypedPropertyDataProvider
     *
     * @param string $valueType
     * @param mixed  $defaultValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidDefaultValueForTypedPropertyThrowsAnException(string $valueType, $defaultValue): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Invalid type for default value of property property');

        $this->generateClassFromFileTemplate(
            'DefaultValueTypedProperty.json',
            [$valueType, $defaultValue],
            null,
            false
        );
    }

    public function invalidDefaultValueForTypedPropertyDataProvider(): array
    {
        return [
            'int property float default' => ['"integer"', 10.5],
            'int property numeric string default' => ['"integer"', '"123"'],
            'int property string default' => ['"integer"', '"Hello"'],
            'int property bool default' => ['"integer"', 'false'],
            'int property array default' => ['"integer"', '[]'],
            'float property numeric string default' => ['"number"', '"123"'],
            'float property string default' => ['"number"', '"Hello"'],
            'float property bool default' => ['"number"', 'false'],
            'float property array default' => ['"number"', '[]'],
            'string property int default' => ['"string"', 10],
            'string property float default' => ['"string"', 123.5],
            'string property bool default' => ['"string"', 'false'],
            'string property array default' => ['"string"', '[]'],
            'bool property int default' => ['"boolean"', 10],
            'bool property float default' => ['"boolean"', 123.5],
            'bool property string default' => ['"boolean"', '"Hello"'],
            'bool property array default' => ['"boolean"', '[]'],
            'array property int default' => ['"array"', 10],
            'array property float default' => ['"array"', 123.5],
            'array property string default' => ['"array"', '"Hello"'],
            'array property bool default' => ['"array"', 'true'],
            'multi type property bool default' => ['["string", "number"]', 'true'],
            'multi type property array default' => ['["string", "number"]', '[]'],
        ];
    }

    /**
     * @dataProvider defaultValueForUntypedPropertyDataProvider
     *
     * @param mixed  $defaultValue
     * @param mixed  $compareValue
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testDefaultValueForUntypedTypedProperty($defaultValue, $compareValue): void
    {
        $className = $this->generateClassFromFileTemplate(
            'DefaultValueUntypedProperty.json',
            [$defaultValue],
            null,
            false
        );

        $object = new $className([]);

        $this->assertSame($object->getProperty(), $compareValue);
    }

    public function defaultValueForUntypedPropertyDataProvider(): array
    {
        return [
            'int' => [9, 9],
            'float' => [9.5, 9.5],
            'bool' => ['true', true],
            'string' => ['"Hey"', 'Hey'],
            'array' => ['[]', []],
        ];
    }

    /**
     * @dataProvider implicitNullDataProvider
     */
    public function testUntypedPropertyTypeAnnotationsAreMixed(bool $implicitNull): void
    {
        $className = $this->generateClassFromFileTemplate(
            'DefaultValueUntypedProperty.json',
            [10],
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull
        );

        $object = new $className([]);

        $this->assertSame('mixed', $this->getPropertyTypeAnnotation($object, 'property'));

        $this->assertSame('mixed', $this->getMethodReturnTypeAnnotation($object, 'getProperty'));
        $this->assertNull($this->getReturnType($object, 'getProperty'));

        $this->assertSame('mixed', $this->getMethodParameterTypeAnnotation($object, 'setProperty'));
        $this->assertNull($this->getParameterType($object, 'setProperty'));
    }
}
