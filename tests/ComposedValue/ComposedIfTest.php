<?php

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
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
     * @param GeneratorConfiguration $configuration
     * @param string $streetAddress
     * @param string $country
     * @param string $postalCode
     */
    public function testConditionalObjectProperty(
        GeneratorConfiguration $configuration,
        ?string $streetAddress,
        ?string $country,
        ?string $postalCode
    ): void {
        $className = $this->generateClassFromFile('ConditionalObjectProperty.json', $configuration);

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
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'not provided postal code' => ['1600 Pennsylvania Avenue NW', 'USA', null],
                'USA postal code' => ['1600 Pennsylvania Avenue NW', 'USA', '20500'],
                'Canada postal code' => ['24 Sussex Drive', 'Canada', 'K1M 1M4'],
            ]
        );
    }

    /**
     * @dataProvider invalidConditionalObjectPropertyDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param string $streetAddress
     * @param string $country
     * @param string $postalCode
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testInvalidConditionalObjectPropertyThrowsAnException(
        GeneratorConfiguration $configuration,
        ?string $streetAddress,
        ?string $country,
        ?string $postalCode
    ): void {
        $this->expectValidationErrorRegExp($configuration, '/postal_code doesn\'t match pattern .*/');

        $className = $this->generateClassFromFile('ConditionalObjectProperty.json', $configuration);

        new $className([
            'street_address' => $streetAddress,
            'country' => $country,
            'postal_code' => $postalCode,
        ]);
    }

    public function invalidConditionalObjectPropertyDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'empty provided postal code' => ['1600 Pennsylvania Avenue NW', 'USA', ''],
                'Canadian postal code for USA' => ['1600 Pennsylvania Avenue NW', 'USA', 'K1M 1M4'],
                'USA postal code for Canada' => ['24 Sussex Drive', 'Canada', '20500'],
                'Unmatching postal code for both' => ['24 Sussex Drive', 'Canada', 'djqwWDJId8juw9duq9'],
            ]
        );
    }

    public function testIncompleteCompositionThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Incomplete conditional composition for property');

        $this->generateClassFromFile('IncompleteConditional.json');
    }
}
