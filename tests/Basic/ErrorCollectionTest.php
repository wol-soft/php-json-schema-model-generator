<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\String\MinLengthException;
use PHPModelGenerator\Exception\String\PatternException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Exception\ErrorRegistryException;
use stdClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class ErrorCollectionTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class ErrorCollectionTest extends AbstractPHPModelGeneratorTestCase
{
    #[DataProvider('validValuesForSinglePropertyDataProvider')]
    public function testValidValuesForMultipleChecksForSingleProperty(string $value): void
    {
        $className = $this->generateClassFromFile('MultipleChecksForSingleProperty.json', new GeneratorConfiguration());

        $object = new $className(['property' => $value]);
        $this->assertSame($value, $object->getProperty());
    }

    public static function validValuesForSinglePropertyDataProvider(): array
    {
        return [
            'numeric string' => ['10'],
            'letter string' => ['Ab'],
            'special chars' => ['+.'],
        ];
    }

    #[DataProvider('invalidValuesForSinglePropertyDataProvider')]
    public function testInvalidValuesForMultipleChecksForSinglePropertyThrowsAnException(
        mixed $value,
        array $messages,
        array $expectedPointers,
    ): void {
        try {
            $className = $this->generateClassFromFile(
                'MultipleChecksForSingleProperty.json',
                new GeneratorConfiguration(),
            );

            new $className(['property' => $value]);
        } catch (ErrorRegistryException $e) {
            $this->assertStringContainsString(join("\n", $messages), $e->getMessage());
            $this->assertCount(count($messages), $e->getErrors());

            foreach ($messages as $expectedExceptionClass => $message) {
                $error = $this->assertErrorRegistryContainsException($e, $expectedExceptionClass);

                $this->assertStringContainsString($message, $error->getMessage());
                $this->assertSame('property', $error->getPropertyName());
                $this->assertSame($value, $error->getProvidedValue());
                $this->assertSame($expectedPointers[$expectedExceptionClass], $error->getJsonPointer()->pointer);
            }

            return;
        }

        $this->fail('Exception not thrown');
    }

    public static function invalidValuesForSinglePropertyDataProvider(): array
    {
        return [
            // PatternException → /pattern suffix; minLength → /minLength suffix; type check → /type suffix
            'pattern invalid' => [
                '  ',
                [PatternException::class => 'Value for \'property\' does not match pattern \'^[^\\s]+$\''],
                [PatternException::class => '/properties/property/pattern'],
            ],
            'length invalid' => [
                'a',
                [MinLengthException::class => 'Value for \'property\' must not be shorter than 2'],
                [MinLengthException::class => '/properties/property/minLength'],
            ],
            'pattern and length invalid' => [
                ' ',
                [
                    PatternException::class => 'Value for \'property\' does not match pattern \'^[^\\s]+$\'',
                    MinLengthException::class => 'Value for \'property\' must not be shorter than 2',
                ],
                [
                    PatternException::class => '/properties/property/pattern',
                    MinLengthException::class => '/properties/property/minLength',
                ],
            ],
            'null' => [
                null,
                [InvalidTypeException::class => 'Invalid type for \'property\''],
                [InvalidTypeException::class => '/properties/property/type'],
            ],
            'int' => [
                1,
                [InvalidTypeException::class => 'Invalid type for \'property\''],
                [InvalidTypeException::class => '/properties/property/type'],
            ],
            'float' => [
                0.92,
                [InvalidTypeException::class => 'Invalid type for \'property\''],
                [InvalidTypeException::class => '/properties/property/type'],
            ],
            'bool' => [
                true,
                [InvalidTypeException::class => 'Invalid type for \'property\''],
                [InvalidTypeException::class => '/properties/property/type'],
            ],
            'array' => [
                [],
                [InvalidTypeException::class => 'Invalid type for \'property\''],
                [InvalidTypeException::class => '/properties/property/type'],
            ],
            'object' => [
                new stdClass(),
                [InvalidTypeException::class => 'Invalid type for \'property\''],
                [InvalidTypeException::class => '/properties/property/type'],
            ],
        ];
    }

    #[DataProvider('invalidValuesForCompositionDataProvider')]
    public function testInvalidValuesForMultipleValuesInCompositionThrowsAnException(
        int|string $value,
        string $message,
    ): void {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches("/$message/");

        $className = $this->generateClassFromFile('MultipleChecksInComposition.json', new GeneratorConfiguration());

        new $className(['integerProperty' => $value]);
    }

    public static function invalidValuesForCompositionDataProvider(): array
    {
        return [
            'matching both composition elements' => [
                6,
                <<<ERROR
                Invalid value for (.*?) declined by composition constraint
                  Requires to match one composition element but matched 2 elements
                  - Composition element #1: Valid
                  - Composition element #2: Valid
                ERROR,
            ],
            'too low number both' => [
                0,
                <<<ERROR
                Invalid value for (.*?) declined by composition constraint
                  Requires to match one composition element but matched 0 elements
                  - Composition element #1: Failed
                    \* Value for 'integerProperty' must not be smaller than 2
                  - Composition element #2: Failed
                    \* Value for 'integerProperty' must not be smaller than 3
                ERROR,
            ],
            'nothing matches' => [
                1,
                <<<ERROR
                Invalid value for (.*?) declined by composition constraint
                  Requires to match one composition element but matched 0 elements
                  - Composition element #1: Failed
                    \* Value for 'integerProperty' must not be smaller than 2
                    \* Value for 'integerProperty' must be a multiple of 2
                  - Composition element #2: Failed
                    \* Value for 'integerProperty' must not be smaller than 3
                    \* Value for 'integerProperty' must be a multiple of 3
                ERROR,
            ],
            'invalid type' => [
                "4",
                <<<ERROR
                Invalid value for (.*?) declined by composition constraint
                  Requires to match one composition element but matched 0 elements
                  - Composition element #1: Failed
                    \* Invalid type for 'integerProperty': requires 'int', got 'string'
                  - Composition element #2: Failed
                    \* Invalid type for 'integerProperty': requires 'int', got 'string'
                ERROR,
            ],
        ];
    }
}
