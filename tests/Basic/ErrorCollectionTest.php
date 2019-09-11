<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;
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
        $className = $this->generateClassFromFile(
            'MultipleChecksForSingleProperty.json',
            (new GeneratorConfiguration())->setCollectErrors(true)
        );

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

        $className = $this->generateClassFromFile(
            'MultipleChecksForSingleProperty.json',
            (new GeneratorConfiguration())->setCollectErrors(true)
        );

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
                ['property must not be shorter than 2']
            ],
            'pattern and length invalid' => [
                ' ',
                [
                    'property doesn\'t match pattern ^[^\s]+$',
                    'property must not be shorter than 2'
                ]
            ],
            'null' => [null, ['invalid type for property']],
            'int' => [1, ['invalid type for property']],
            'float' => [0.92, ['invalid type for property']],
            'bool' => [true, ['invalid type for property']],
            'array' => [[], ['invalid type for property']],
            'object' => [new stdClass(), ['invalid type for property']],
        ];
    }

    /**
     * Set up an ErrorRegistryException containing the given messages
     *
     * @param array $messages
     *
     * @return ErrorRegistryException
     */
    protected function getErrorRegistryException(array $messages): ErrorRegistryException
    {
        $errorRegistry = new ErrorRegistryException();

        foreach ($messages as $message) {
            $errorRegistry->addError($message);
        }

        return $errorRegistry;
    }
}
