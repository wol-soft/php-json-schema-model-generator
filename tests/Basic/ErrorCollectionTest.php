<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
use PHPModelGeneratorException\ErrorRegistryException;
use stdClass;

/**
 * Class ErrorCollectionTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class ErrorCollectionTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @dataProvider validValuesForSinglePropertyDataProvider
     *
     * @param string $value
     */
    public function testValidValuesForMultipleChecksForSingleProperty(string $value): void
    {
        $className = $this->generateClassFromFile('MultipleChecksForSingleProperty.json', new GeneratorConfiguration());

        $object = new $className(['property' => $value]);
        $this->assertSame($value, $object->getProperty());
    }

    public function validValuesForSinglePropertyDataProvider(): array
    {
        return [
            'numeric string' => ['10'],
            'letter string' => ['Ab'],
            'special chars' => ['+.'],
        ];
    }
    /**
     * @dataProvider invalidValuesForSinglePropertyDataProvider
     *
     * @param string $value
     */
    public function testInvalidValuesForMultipleChecksForSinglePropertyThrowsAnException(
        $value,
        array $messages
    ): void {
        $this->expectExceptionObject($this->getErrorRegistryException($messages));

        $className = $this->generateClassFromFile('MultipleChecksForSingleProperty.json', new GeneratorConfiguration());

        new $className(['property' => $value]);
    }

    public function invalidValuesForSinglePropertyDataProvider(): array
    {
        return [
            'pattern invalid' => [
                '  ',
                ['property doesn\'t match pattern ^[^\s]+$']
            ],
            'length invalid' => [
                'a',
                ['Value for property must not be shorter than 2']
            ],
            'pattern and length invalid' => [
                ' ',
                [
                    'property doesn\'t match pattern ^[^\s]+$',
                    'Value for property must not be shorter than 2'
                ]
            ],
            'null' => [null, ['Invalid type for property']],
            'int' => [1, ['Invalid type for property']],
            'float' => [0.92, ['Invalid type for property']],
            'bool' => [true, ['Invalid type for property']],
            'array' => [[], ['Invalid type for property']],
            'object' => [new stdClass(), ['Invalid type for property']],
        ];
    }

    /**
     * @dataProvider invalidValuesForCompositionDataProvider
     */
    public function testInvalidValuesForMultipleValuesInCompositionThrowsAnException($value, string $message): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches("/$message/");

        $className = $this->generateClassFromFile('MultipleChecksInComposition.json', new GeneratorConfiguration());

        new $className(['integerProperty' => $value]);
    }

    public function invalidValuesForCompositionDataProvider(): array
    {
        return [
            'matching both composition elements' => [
                6,
                <<<ERROR
Invalid value for (.*?) declined by composition constraint\.
  Requires to match one composition element but matched 2 elements\.
  - Composition element #1: Valid
  - Composition element #2: Valid
ERROR
            ],
            'too low number both' => [
                0,
                <<<ERROR
Invalid value for (.*?) declined by composition constraint\.
  Requires to match one composition element but matched 0 elements\.
  - Composition element #1: Failed
    \* Value for integerProperty must not be smaller than 2
  - Composition element #2: Failed
    \* Value for integerProperty must not be smaller than 3
ERROR
            ],
            'nothing matches' => [
                1,
                <<<ERROR
Invalid value for (.*?) declined by composition constraint\.
  Requires to match one composition element but matched 0 elements\.
  - Composition element #1: Failed
    \* Value for integerProperty must not be smaller than 2
    \* Value for integerProperty must be a multiple of 2
  - Composition element #2: Failed
    \* Value for integerProperty must not be smaller than 3
    \* Value for integerProperty must be a multiple of 3
ERROR
            ],
            'invalid type' => [
                "4",
                <<<ERROR
Invalid value for (.*?) declined by composition constraint\.
  Requires to match one composition element but matched 0 elements\.
  - Composition element #1: Failed
    \* Invalid type for integerProperty. Requires int, got string
  - Composition element #2: Failed
    \* Invalid type for integerProperty. Requires int, got string
ERROR
            ],
        ];
    }
}
