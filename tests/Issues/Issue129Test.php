<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues;

use BackedEnum;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use TypeError;

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
                    TEST_BASE_DIR . DIRECTORY_SEPARATOR . 'Enum',
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

    /**
     * A non-string scalar passed via the constructor must be rejected when "type": "string" is
     * declared alongside "enum". The constructor accepts raw array data (no PHP type hints), so
     * the PassThroughTypeCheckValidator is the enforcement point: it fires for values that are
     * neither a valid enum instance nor a string.
     */
    public function testNonStringScalarIsRejectedWhenPropertyDeclaresBothTypeAndEnum(): void
    {
        $this->addEnumPostProcessor();

        $className = $this->generateClassFromFile(
            'EnumPropertyWithType.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
            false,
        );

        $this->expectException(InvalidTypeException::class);
        new $className(['property' => 1234]);
    }

    /**
     * When "type": "string" is combined with an enum that contains non-string values, the
     * incompatible values must be stripped from the generated PHP enum and a warning printed.
     * The resulting enum only exposes the string cases.
     */
    public function testIncompatibleEnumValuesAreRemovedAndWarningIsEmitted(): void
    {
        $this->addEnumPostProcessor();

        // 1234 is incompatible with "type": "string" — expect a warning on stdout
        $this->expectOutputRegex(
            '/Warning:.*property.*incompatible values.*1234.*removed/i',
        );

        $className = $this->generateClassFromFile(
            'EnumPropertyWithTypeAndMixedValues.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
            false,
        );

        // Only string cases must exist; 1234 must be absent
        $enumClass = $this->getReturnType(new $className(['property' => 'foo']), 'getProperty')->getName();
        $caseValues = array_map(static fn(BackedEnum $case): string => $case->value, $enumClass::cases());
        $this->assertEqualsCanonicalizing(['foo', 'bar'], $caseValues);
    }
}
