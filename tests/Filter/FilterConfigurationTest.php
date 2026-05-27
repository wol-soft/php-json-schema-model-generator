<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Filter;

use PHPModelGenerator\Exception\InvalidFilterException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Filter\Trim;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for filter registration and lookup on GeneratorConfiguration.
 *
 * Covers: adding built-in and custom filters, looking up a filter by token,
 * rejecting filter registrations with invalid callbacks, and the SchemaException
 * thrown when a schema references a filter token that has not been registered.
 */
class FilterConfigurationTest extends AbstractFilterTestCase
{
    public function testGetFilterReturnsAnExistingFilter(): void
    {
        $this->assertSame('trim', (new GeneratorConfiguration())->getFilter('trim')->getToken());
    }

    public function testGetFilterReturnsNullForNonExistingFilter(): void
    {
        $this->assertNull((new GeneratorConfiguration())->getFilter('somethingElse'));
    }

    #[DataProvider('invalidCustomFilterDataProvider')]
    public function testAddInvalidFilterThrowsAnException(array $customInvalidFilter): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessage('Invalid filter callback for filter customFilter');

        (new GeneratorConfiguration())->addFilter($this->getCustomFilter($customInvalidFilter));
    }

    public static function invalidCustomFilterDataProvider(): array
    {
        return [
            'empty array'          => [[]],
            'one element array'    => [[Trim::class]],
            'Invalid class'        => [[123, 'filter']],
            'Invalid function'     => [[Trim::class, 123]],
            'Non existing class'   => [['NonExistingClass', 'filter']],
            'Non existing function' => [[Trim::class, 'nonExistingMethod']],
            'three array'          => [[Trim::class, 'filter', 'abc']],
        ];
    }

    public function testNonExistingFilterThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Unsupported filter nonExistingFilter');

        $this->generateClassFromFile('NonExistingFilter.json');
    }
}
