<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class BooleanItemsSchemaTest extends AbstractPHPModelGeneratorTestCase
{
    public function testItemsTrueAcceptsAnyContent(): void
    {
        $className = $this->generateClassFromFile('TrueItems.json');

        $object = new $className(['items' => [1, 'hello', true, null]]);
        $this->assertSame([1, 'hello', true, null], $object->getItems());
    }

    public function testItemsFalseAcceptsEmptyArray(): void
    {
        $className = $this->generateClassFromFile('FalseItems.json');

        $object = new $className(['items' => []]);
        $this->assertSame([], $object->getItems());
    }

    #[DataProvider('nonEmptyArrayDataProvider')]
    public function testItemsFalseRejectsNonEmptyArray(
        GeneratorConfiguration $configuration,
        array $value,
    ): void {
        $this->expectValidationError(
            $configuration,
            'Array items must not contain more than 0 items',
        );

        $className = $this->generateClassFromFile('FalseItems.json', $configuration);
        new $className(['items' => $value]);
    }

    public static function nonEmptyArrayDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'single integer item'  => [[42]],
                'single string item'   => [['hello']],
                'multiple items'       => [[1, 2, 3]],
                'mixed items'          => [[true, null, 'x']],
            ],
        );
    }
}
