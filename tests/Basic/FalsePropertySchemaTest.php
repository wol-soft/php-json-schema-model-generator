<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class FalsePropertySchemaTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class FalsePropertySchemaTest extends AbstractPHPModelGeneratorTestCase
{
    public function testRequiredFalsePropertyThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches("/forbidden.*denied.*required/i");
        $this->generateClassFromFile('RequiredFalseProperty.json');
    }

    public function testNotProvidingFalsePropertyIsValid(): void
    {
        $className = $this->generateClassFromFile('FalseProperty.json');
        $object = new $className(['name' => 'Alice']);
        $this->assertSame('Alice', $object->getName());
    }

    #[DataProvider('falsePropertyValueDataProvider')]
    public function testProvidingFalsePropertyThrowsException(
        GeneratorConfiguration $configuration,
        mixed $value,
    ): void {
        $this->expectValidationError($configuration, 'Value for forbidden is not allowed');
        $className = $this->generateClassFromFile('FalseProperty.json', $configuration);
        new $className(['forbidden' => $value]);
    }

    public static function falsePropertyValueDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'string' => ['hello'],
                'int'    => [42],
                'null'   => [null],
                'bool'   => [true],
                'array'  => [[]],
            ],
        );
    }

    public function testNoGetterGeneratedForFalseProperty(): void
    {
        $className = $this->generateClassFromFile('FalseProperty.json');
        $this->assertFalse(method_exists($className, 'getForbidden'));
    }
}
