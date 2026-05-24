<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues;

use BackedEnum;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use TypeError;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;

/**
 * Regression test for https://github.com/wol-soft/php-json-schema-model-generator/issues/129
 *
 * When a property declares both "type": "string" and "enum", the generated validator incorrectly
 * runs an is_string() typecheck after the UnitEnum passthrough guard. Passing a valid enum
 * instance therefore fails the is_string() check even though it is a legal input value.
 */
class Issue129Test extends AbstractPHPModelGeneratorTestCase
{
    private function addEnumPostProcessor(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(
                new EnumPostProcessor(
                    join(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), 'PHPModelGeneratorTest', 'Enum']),
                    'Enum',
                )
            );
        };
    }

    /**
     * A property with both "type": "string" and "enum" must accept a valid enum instance without
     * throwing an InvalidTypeException for is_string().
     */
    public function testEnumInstanceIsAcceptedWhenPropertyDeclaresBothTypeAndEnum(): void
    {
        $this->addEnumPostProcessor();

        $className = $this->generateClassFromFile(
            'EnumPropertyWithType.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
            false,
        );

        // String input: must be accepted and converted to an enum instance
        $object = new $className(['property' => 'foo']);
        $this->assertInstanceOf(BackedEnum::class, $object->getProperty());
        $this->assertSame('foo', $object->getProperty()->value);

        // Valid enum instance as input: must NOT throw InvalidTypeException for is_string()
        $enumClass = get_class($object->getProperty());
        $object->setProperty($enumClass::from('bar'));
        $this->assertSame('bar', $object->getProperty()->value);
    }

    /**
     * An invalid enum instance (wrong enum type) must still be rejected.
     * With "type": "string" declared, the setter's PHP signature is EnumClass|string|null,
     * so passing a mismatched UnitEnum fails at the PHP type-system level (TypeError).
     */
    public function testWrongEnumInstanceIsRejectedWhenPropertyDeclaresBothTypeAndEnum(): void
    {
        $this->addEnumPostProcessor();

        $className = $this->generateClassFromFile(
            'EnumPropertyWithType.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
            false,
        );

        $object = new $className(['property' => 'foo']);

        $this->expectException(TypeError::class);
        $object->setProperty(\PHPModelGenerator\Tests\PostProcessor\IntEnum::A);
    }
}
