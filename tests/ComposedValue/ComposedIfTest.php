<?php

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

/**
 * Class ComposedIfTest
 *
 * @package PHPModelGenerator\Tests\ComposedValue
 */
class ComposedIfTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @dataProvider validConditionalObjectPropertyDataProvider
     *
     * @param string $streetAddress
     * @param string $country
     * @param string $postalCode
     */
    public function testConditionalObjectProperty(?string $streetAddress, ?string $country, ?string $postalCode): void
    {
        $className = $this->generateClassFromFile('ConditionalObjectProperty.json');

        $object = new $className([
            'street_address' => $streetAddress,
            'country' => $country,
            'postal_code' => $postalCode,
        ]);

        $this->assertSame($streetAddress, $object->getStreetAddress());
        $this->assertSame($country, $object->getCountry());
        $this->assertSame($postalCode, $object->getPostalCode());
    }

    public function validConditionalObjectPropertyDataProvider(): array
    {
        return [
            'not provided postal code' => ['1600 Pennsylvania Avenue NW', 'USA', null],
            'USA postal code' => ['1600 Pennsylvania Avenue NW', 'USA', '20500'],
            'Canada postal code' => ['24 Sussex Drive', 'Canada', 'K1M 1M4'],
        ];
    }

    /**
     * @dataProvider invalidConditionalObjectPropertyDataProvider
     *
     * @param string $streetAddress
     * @param string $country
     * @param string $postalCode
     */
    public function testInvalidConditionalObjectPropertyThrowsAnException(
        ?string $streetAddress,
        ?string $country,
        ?string $postalCode
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageRegExp('/postal_code doesn\'t match pattern .*/');

        $className = $this->generateClassFromFile('ConditionalObjectProperty.json');

        new $className([
            'street_address' => $streetAddress,
            'country' => $country,
            'postal_code' => $postalCode,
        ]);
    }

    public function invalidConditionalObjectPropertyDataProvider(): array
    {
        return [
            'empty provided postal code' => ['1600 Pennsylvania Avenue NW', 'USA', ''],
            'Canadian postal code for USA' => ['1600 Pennsylvania Avenue NW', 'USA', 'K1M 1M4'],
            'USA postal code for Canada' => ['24 Sussex Drive', 'Canada', '20500'],
            'Unmatching postal code for both' => ['24 Sussex Drive', 'Canada', 'djqwWDJId8juw9duq9'],
        ];
    }
}
