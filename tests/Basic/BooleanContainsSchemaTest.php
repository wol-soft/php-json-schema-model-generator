<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class BooleanContainsSchemaTest extends AbstractPHPModelGeneratorTestCase
{
    public function testContainsFalseAllowsAbsentProperty(): void
    {
        $className = $this->generateClassFromFile('FalseContains.json');
        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    #[DataProvider('containsFalseDataProvider')]
    public function testContainsFalseRejectsAnyArray(
        GeneratorConfiguration $configuration,
        array $value,
    ): void {
        $this->expectValidationError($configuration, 'No item in array property matches contains constraint');

        $className = $this->generateClassFromFile('FalseContains.json', $configuration);
        new $className(['property' => $value]);
    }

    public static function containsFalseDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'empty array'     => [[]],
                'non-empty array' => [[1, 2, 3]],
                'mixed array'     => [['hello', true, null]],
            ],
        );
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testContainsTrueAcceptsNonEmptyArray(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('TrueContains.json', $configuration);

        $object = new $className(['property' => [1, 'hello', true]]);
        $this->assertSame([1, 'hello', true], $object->getProperty());
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testContainsTrueRejectsEmptyArray(GeneratorConfiguration $configuration): void
    {
        $this->expectValidationError($configuration, 'No item in array property matches contains constraint');

        $className = $this->generateClassFromFile('TrueContains.json', $configuration);

        new $className(['property' => []]);
    }
}
