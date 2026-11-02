<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The keywords which validate the items of an array and the keys of an object (additionalItems, unevaluatedItems,
 * contains, minContains, maxContains, items, additionalProperties, patternProperties, propertyNames,
 * unevaluatedProperties) skip a property which isn't provided. With implicit null the validators of the property run
 * for the null value, the keywords must not read it as an array or an object.
 */
#[ApplicableDrafts]
class OptionalKeywordPropertyTest extends AbstractPHPModelGeneratorTestCase
{
    private const array ARRAY_PROPERTIES = [
        'additionalItems',
        'additionalItemsFalse',
        'unevaluatedItemsSchema',
        'unevaluatedItemsFalse',
        'contains',
        'containsMinMax',
        'items',
        'tuple',
    ];

    private const array OBJECT_PROPERTIES = [
        'objectAdditionalProperties',
        'objectPatternProperties',
        'objectPropertyNames',
        'objectUnevaluatedProperties',
    ];

    private const array UNTYPED_PROPERTIES = [
        'untypedAdditionalProperties',
        'untypedPropertyNames',
        'untypedContains',
        'untypedAdditionalItems',
        'untypedUnevaluatedItems',
    ];

    #[DataProvider('validationMethodWithImplicitNullDataProvider')]
    public function testKeywordsOfPropertiesWhichAreNotProvidedAreSkipped(
        GeneratorConfiguration $configuration,
        bool $implicitNull,
    ): void {
        $className = $this->generateClassFromFile(
            'OptionalKeywordProperties.json',
            $configuration,
            implicitNull: $implicitNull,
        );

        $object = new $className([]);

        foreach ([...self::ARRAY_PROPERTIES, ...self::OBJECT_PROPERTIES, ...self::UNTYPED_PROPERTIES] as $property) {
            $this->assertNull($object->{'get' . ucfirst($property)}(), $property);
        }
    }

    /**
     * A property which is provided as null is accepted with implicit null. Without implicit null a typed property
     * rejects null by its type, an untyped property accepts any value.
     */
    #[DataProvider('validationMethodWithImplicitNullDataProvider')]
    public function testKeywordsOfPropertiesWhichAreProvidedAsNullAreSkipped(
        GeneratorConfiguration $configuration,
        bool $implicitNull,
    ): void {
        $className = $this->generateClassFromFile(
            'OptionalKeywordProperties.json',
            $configuration,
            implicitNull: $implicitNull,
        );

        foreach ([...self::UNTYPED_PROPERTIES, ...self::ARRAY_PROPERTIES, ...self::OBJECT_PROPERTIES] as $property) {
            $isTyped = !in_array($property, self::UNTYPED_PROPERTIES, true);

            if ($isTyped && !$implicitNull) {
                $type = in_array($property, self::ARRAY_PROPERTIES, true) ? 'array' : 'object';

                $this->assertInstantiationFailsWithMessage(
                    $className,
                    [$property => null],
                    $this->getExpectedExceptionClass($configuration, InvalidTypeException::class),
                    "Invalid type for '$property': requires '$type', got 'NULL'",
                );

                continue;
            }

            $this->assertNull(
                (new $className([$property => null]))->{'get' . ucfirst($property)}(),
                $property,
            );
        }
    }

    public static function validationMethodWithImplicitNullDataProvider(): array
    {
        return self::combineDataProvider(self::validationMethodDataProvider(), self::implicitNullDataProvider());
    }
}
