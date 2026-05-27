<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Filter;

use Exception;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Filter\FilterInterface;
use PHPModelGenerator\Filter\ValidateOptionsInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for custom non-transforming filters.
 *
 * Covers: registering and using a simple custom callable filter, chaining multiple
 * non-transforming filters on a single property, applying a filter to an array property's
 * items, and custom filter option validation via ValidateOptionsInterface (both invalid
 * and valid option configurations).
 */
class CustomFilterTest extends AbstractFilterTestCase
{
    // -------------------------------------------------------------------------
    // Simple custom filter
    // -------------------------------------------------------------------------

    public static function uppercaseFilter(?string $value): ?string
    {
        return $value !== null ? strtoupper($value) : null;
    }

    #[DataProvider('customFilterDataProvider')]
    public function testCustomFilter(?string $input, ?string $expectedValue): void
    {
        $className = $this->generateClassFromFile(
            'Uppercase.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->addFilter($this->getCustomFilter([self::class, 'uppercaseFilter'], 'uppercase')),
        );

        $object = new $className(['property' => $input]);
        $this->assertSame($expectedValue, $object->getProperty());
        $this->assertSame($input, $object->getRawModelDataInput()['property']);

        $object->setProperty($input);
        $this->assertSame($expectedValue, $object->getProperty());

        $object->setProperty('hi');
        $this->assertSame('HI', $object->getProperty());
        $this->assertSame('hi', $object->getRawModelDataInput()['property']);
    }

    public static function customFilterDataProvider(): array
    {
        return [
            'null'           => [null, null],
            'empty string'   => ['', ''],
            'numeric'        => ['123', '123'],
            'spaces'         => ['  ', '  '],
            'uppercase string' => ['ABC', 'ABC'],
            'mixed string'   => ['Hello World!', 'HELLO WORLD!'],
        ];
    }

    // -------------------------------------------------------------------------
    // Multiple filters chained
    // -------------------------------------------------------------------------

    #[DataProvider('multipleFilterDataProvider')]
    public function testMultipleFilters(?string $input, ?string $expectedValue): void
    {
        $className = $this->generateClassFromFile(
            'MultipleFilters.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->addFilter($this->getCustomFilter([self::class, 'uppercaseFilter'], 'uppercase')),
        );

        $object = new $className(['property' => $input]);
        $this->assertSame($expectedValue, $object->getProperty());

        $object->setProperty($input);
        $this->assertSame($expectedValue, $object->getProperty());
    }

    public static function multipleFilterDataProvider(): array
    {
        return [
            'null'           => [null, null],
            'empty string'   => ['', ''],
            'numeric'        => [' 123 ', '123'],
            'spaces'         => ['  ', ''],
            'uppercase string' => [" ABC\n", 'ABC'],
            'mixed string'   => ["  \t Hello World! ", 'HELLO WORLD!'],
        ];
    }

    // -------------------------------------------------------------------------
    // Array item filter
    // -------------------------------------------------------------------------

    #[DataProvider('arrayFilterDataProvider')]
    public function testArrayFilter(?array $input, ?array $output): void
    {
        $className = $this->generateClassFromFile('ArrayFilter.json');

        $object = new $className(['list' => $input]);
        $this->assertSame($output, $object->getList());
    }

    public static function arrayFilterDataProvider(): array
    {
        return [
            'null'         => [null, null],
            'empty array'  => [[], []],
            'string array' => [['', 'Hello', null, '123'], ['Hello', '123']],
            'numeric array' => [[12, 0, 43], [12, 43]],
            'nested array' => [[['Hello'], [], [12], ['']], [['Hello'], [12], ['']]],
        ];
    }

    // -------------------------------------------------------------------------
    // Custom filter option validation (ValidateOptionsInterface)
    // -------------------------------------------------------------------------

    #[DataProvider('invalidEncodingFilterConfigurationsDataProvider')]
    public function testInvalidCustomFilterOptionValidation(string $configuration, string $expectedErrorMessage): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/Invalid filter options on filter encode on property .*\: $expectedErrorMessage/",
        );

        $this->generateClassFromFileTemplate(
            'Encode.json',
            [$configuration],
            (new GeneratorConfiguration())->setImmutable(false)->addFilter($this->getEncodeFilter()),
            false,
        );
    }

    public static function invalidEncodingFilterConfigurationsDataProvider(): array
    {
        return [
            'simple notation without options'             => ['"encode"', 'Missing charset configuration'],
            'object notation without charset configuration' => [
                '{"filter": "encode"}',
                'Missing charset configuration',
            ],
            'Invalid charset configuration'  => ['{"filter": "encode", "charset": 1}', 'Unsupported charset'],
            'Invalid charset configuration 2' => ['{"filter": "encode", "charset": "UTF-16"}', 'Unsupported charset'],
        ];
    }

    #[DataProvider('validEncodingsDataProvider')]
    public function testValidCustomFilterOptionValidation(string $encoding, string $input, string $output): void
    {
        $classname = $this->generateClassFromFileTemplate(
            'Encode.json',
            [sprintf('{"filter": "encode", "charset": "%s"}', $encoding)],
            (new GeneratorConfiguration())->setImmutable(false)->addFilter($this->getEncodeFilter()),
            false,
        );

        $object = new $classname(['property' => $input]);

        $this->assertSame($encoding, mb_detect_encoding($object->getProperty()));
        $this->assertSame($output, $object->getProperty());
    }

    public static function validEncodingsDataProvider(): array
    {
        return [
            'ASCII to ASCII' => ['ASCII', 'Hello World', 'Hello World'],
            'UTF-8 to ASCII' => ['ASCII', 'áéó', '???'],
            'UTF-8 to UTF-8' => ['UTF-8', 'áéó', 'áéó'],
        ];
    }

    private function getEncodeFilter(): FilterInterface
    {
        return new class () implements FilterInterface, ValidateOptionsInterface {
            public function getToken(): string
            {
                return 'encode';
            }

            public function getFilter(): array
            {
                return [CustomFilterTest::class, 'encode'];
            }

            public function validateOptions(array $options): void
            {
                if (!isset($options['charset'])) {
                    throw new Exception('Missing charset configuration');
                }

                if (!in_array($options['charset'], ['UTF-8', 'ASCII'])) {
                    throw new Exception('Unsupported charset');
                }
            }
        };
    }

    public static function encode(string $value, array $options): string
    {
        return mb_convert_encoding($value, $options['charset'], 'auto');
    }
}
