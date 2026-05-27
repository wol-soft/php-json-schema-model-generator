<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\Object\InvalidPatternPropertiesException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class BooleanPatternPropertySchemaTest extends AbstractPHPModelGeneratorTestCase
{
    #[DataProvider('falsePatternMatchingKeyDataProvider')]
    public function testFalsePatternPropertyDeniesMatchingKey(
        GeneratorConfiguration $configuration,
        mixed $value,
    ): void {
        $this->expectValidationError(
            $configuration,
            "invalid property 'secret_value' matching pattern '^secret_.*'",
        );
        $className = $this->generateClassFromFile('FalsePatternProperty.json', $configuration);
        new $className(['secret_value' => $value]);
    }

    public static function falsePatternMatchingKeyDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'string value' => ['hello'],
                'int value'    => [42],
                'null value'   => [null],
                'bool value'   => [true],
                'array value'  => [[]],
            ],
        );
    }

    public function testDeclaredPropertyMatchingForbiddenPatternThrowsSchemaException(): void
    {
        $messagePattern = '/^Property \'secret_data\' is declared in properties'
            . ' but forbidden by patternProperties pattern \'\^secret_\.\*\' in file/';

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches($messagePattern);
        $this->generateClassFromFile('ForbiddenPatternConflict.json');
    }

    public function testFalsePatternPropertyAllowsNonMatchingKey(): void
    {
        $className = $this->generateClassFromFile('FalsePatternProperty.json');
        $object = new $className(['public_value' => 'hello', 'name' => 'Alice']);
        $this->assertSame(['public_value' => 'hello', 'name' => 'Alice'], $object->getRawModelDataInput());
    }

    public function testTruePatternPropertyAcceptsMatchingKey(): void
    {
        $className = $this->generateClassFromFile('TruePatternProperty.json');
        $object = new $className(['any_field' => 'whatever', 'name' => 'Bob']);
        $this->assertSame(['any_field' => 'whatever', 'name' => 'Bob'], $object->getRawModelDataInput());
    }

    /**
     * When multiple keys match a false pattern, all of them are collected and reported
     * in a single InvalidPatternPropertiesException (not just the first match).
     *
     * Even in early-return mode the validator iterates all matching keys before throwing,
     * so both keys appear in the exception message.
     */
    public function testFalsePatternDeniesAllMatchingKeysInOneException(): void
    {
        $className = $this->generateClassFromFile('FalsePatternMultipleMatches.json');

        $this->expectException(InvalidPatternPropertiesException::class);
        $this->expectExceptionMessage(
            <<<EOT
            Provided JSON for $className contains invalid pattern properties.
              - invalid property 'secret_a' matching pattern '^secret_.*'
                * Value for secret_a is not allowed
              - invalid property 'secret_b' matching pattern '^secret_.*'
                * Value for secret_b is not allowed
            EOT,
        );

        new $className(['secret_a' => 'x', 'secret_b' => 'y', 'public' => 'ok']);
    }
}
