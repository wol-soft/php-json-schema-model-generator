<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Filter;

use DateTime;
use Exception;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for multi-step filter chains.
 *
 * Covers: execution order within a chain, skipping filters before a transforming filter when
 * the value is already in the output type-space, incompatible chain rejection at generation
 * time (type mismatch after a transforming filter), chains on multi-type and untyped properties,
 * filters with partial type-overlap inside a chain, mixed-return transforming filters, accept-all
 * follow-up filters, and non-nullable return type incompatibility with a downstream filter.
 */
class FilterChainTest extends AbstractFilterTestCase
{
    // -------------------------------------------------------------------------
    // Static callables local to this class
    // -------------------------------------------------------------------------

    public static function stripTimeFilter(?DateTime $value): ?DateTime
    {
        return $value !== null ? $value->setTime(0, 0) : null;
    }

    public static function stripTimeFilterStrict(DateTime $value): DateTime
    {
        return $value->setTime(0, 0);
    }

    public static function exceptionFilter(string $value): void
    {
        throw new Exception("Exception filter called with $value");
    }

    public static function exceptionFilterDateTime(?\DateTime $value): void
    {
        throw new Exception("Exception filter called with DateTime");
    }

    /** Transforming filter callable that returns mixed; used by mixed-return chain tests. */
    public static function filterWithMixedReturn(string $value): mixed
    {
        return $value;
    }

    /** Serializer paired with filterWithMixedReturn. */
    public static function serializeMixedReturn(mixed $value): string
    {
        return (string) $value;
    }

    /** Regular filter callable that accepts and returns mixed (accept-all filter). */
    public static function acceptAllFilter(mixed $value): mixed
    {
        return $value;
    }

    /** Transforming filter callable that accepts string and returns a non-nullable int. */
    public static function filterWithIntReturn(string $value): int
    {
        return (int) $value;
    }

    /** Serializer paired with filterWithIntReturn. */
    public static function serializeIntReturn(int $value): string
    {
        return (string) $value;
    }

    // -------------------------------------------------------------------------
    // Pre-transforming-filter execution / skip
    // -------------------------------------------------------------------------

    public function testFilterBeforeTransformingFilterIsExecutedIfNonTransformedValueIsProvided(): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessage(
            'Invalid value for property \'filteredProperty\' denied by filter \'exceptionFilter\': ' .
            'Exception filter called with 12.12.2020',
        );

        $className = $this->generateClassFromFileTemplate(
            'FilterChain.json',
            ['["exceptionFilter", "dateTime"]'],
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'exceptionFilter'], 'exceptionFilter'),
            ),
            false,
        );

        new $className(['filteredProperty' => '12.12.2020']);
    }

    public function testFilterBeforeTransformingFilterIsSkippedIfTransformedValueIsProvided(): void
    {
        $className = $this->generateClassFromFileTemplate(
            'FilterChain.json',
            ['["exceptionFilter", "dateTime"]'],
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'exceptionFilter'], 'exceptionFilter'),
            ),
            false,
        );

        $object = new $className(['filteredProperty' => new DateTime('2020-12-10')]);

        $this->assertSame(
            (new DateTime('2020-12-10'))->format(DATE_ATOM),
            $object->getFilteredProperty()->format(DATE_ATOM),
        );
    }

    // -------------------------------------------------------------------------
    // Incompatible chain rejection at generation time
    // -------------------------------------------------------------------------

    public function testInvalidFilterChainWithTransformingFilterThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage(
            'Filter trim is not compatible with transformed property type ' .
            '[null, DateTime] for property filteredProperty',
        );

        $this->generateClassFromFileTemplate('FilterChain.json', ['["dateTime", "trim"]'], null, false);
    }

    public function testFilterAfterTransformingFilterIsSkippedIfTransformingFilterFails(): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessage(
            'Invalid value for property \'filteredProperty\' denied by filter \'dateTime\': ' .
                'Invalid Date Time value "Hello"',
        );

        $className = $this->generateClassFromFile(
            'FilterChainMultiType.json',
            (new GeneratorConfiguration())
                ->addFilter(
                    $this->getCustomFilter(
                        [self::class, 'exceptionFilterDateTime'],
                        'stripTime',
                    )
                ),
            false,
        );

        new $className(['filteredProperty' => 'Hello']);
    }

    // -------------------------------------------------------------------------
    // Chains on multi-type properties
    // -------------------------------------------------------------------------

    public function testFilterChainWithTransformingFilter(): void
    {
        $className = $this->generateClassFromFileTemplate(
            'FilterChain.json',
            ['["trim", "dateTime", "stripTime"]'],
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->addFilter(
                    $this->getCustomFilter(
                        [self::class, 'stripTimeFilter'],
                        'stripTime',
                    )
                ),
            false,
        );

        $object = new $className(['filteredProperty' => '2020-12-12 12:12:12']);

        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());
        $this->assertSame('2020-12-12T00:00:00+00:00', $object->getFilteredProperty()->format(DateTime::ATOM));

        $object->setFilteredProperty(null);
        $this->assertNull($object->getFilteredProperty());

        $object->setFilteredProperty(new DateTime('2020-12-12 12:12:12'));
        $this->assertSame('2020-12-12T00:00:00+00:00', $object->getFilteredProperty()->format(DateTime::ATOM));
    }

    #[DataProvider('implicitNullNamespaceDataProvider')]
    public function testFilterChainWithTransformingFilterOnMultiTypeProperty(
        bool $implicitNull,
        string $namespace,
    ): void {
        $className = $this->generateClassFromFile(
            'FilterChainMultiType.json',
            (new GeneratorConfiguration())
                ->setNamespacePrefix($namespace)
                ->setSerialization(true)
                ->setImmutable(false)
                ->addFilter(
                    $this->getCustomFilter(
                        [self::class, 'stripTimeFilter'],
                        'stripTime',
                    )
                ),
            false,
            $implicitNull,
        );

        $fqcn = $namespace . $className;
        $object = new $fqcn(['filteredProperty' => '2020-12-12 12:12:12']);

        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());
        $this->assertSame('2020-12-12T00:00:00+00:00', $object->getFilteredProperty()->format(DateTime::ATOM));

        $object->setFilteredProperty(null);
        $this->assertNull($object->getFilteredProperty());

        $object->setFilteredProperty(new DateTime('2020-12-12 12:12:12'));
        $this->assertSame('2020-12-12T00:00:00+00:00', $object->getFilteredProperty()->format(DateTime::ATOM));

        $this->assertSame(['filteredProperty' => '2020-12-12T00:00:00+0000'], $object->toArray());
    }

    public function testFilterChainWithIncompatibleFilterAfterTransformingFilterOnMultiTypeProperty(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage(
            'Filter stripTime is not compatible with transformed ' .
            'property type [null, DateTime] for property filteredProperty',
        );

        $this->generateClassFromFile(
            'FilterChainMultiType.json',
            (new GeneratorConfiguration())
                ->addFilter(
                    $this->getCustomFilter(
                        [self::class, 'stripTimeFilterStrict'],
                        'stripTime',
                    )
                ),
        );
    }

    public function testFilterWhichAppliesToMultiTypePropertyPartiallyIsAllowed(): void
    {
        // A filter with acceptedTypes = ['string'] applied to a string|null property has partial
        // overlap and is valid — the runtime typeCheck skips the filter for null values.
        $className = $this->generateClassFromFile(
            'FilterChainMultiType.json',
            (new GeneratorConfiguration())
                ->addFilter(
                    $this->getCustomFilter(
                        [self::class, 'uppercaseFilterStringOnly'],
                        'trim',
                    )
                )
                ->addFilter(
                    $this->getCustomFilter(
                        [self::class, 'stripTimeFilter'],
                        'stripTime',
                    )
                ),
            false,
        );

        $this->assertNotNull($className);
    }

    /**
     * [trim, dateTime] filter chain on a string|integer property.
     * trim accepts only string|null — the int input bypasses trim.
     * dateTime accepts string|int|float|null — both inputs are converted to DateTime.
     */
    public function testFilterChainTrimDateTimeOnStringIntegerProperty(): void
    {
        $className = $this->generateClassFromFile(
            'StringIntegerPropertyFilterChain.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        // string input: trim trims whitespace, dateTime converts to DateTime
        $object = new $className(['created' => ' 2020-12-12 ']);
        $this->assertInstanceOf(\DateTime::class, $object->getCreated());
        $this->assertSame(
            (new \DateTime('2020-12-12'))->format(DATE_ATOM),
            $object->getCreated()->format(DATE_ATOM),
        );

        // int input: trim is skipped (not a string), dateTime converts timestamp to DateTime
        $object = new $className(['created' => 0]);
        $this->assertInstanceOf(\DateTime::class, $object->getCreated());
        $this->assertSame(
            (new \DateTime('@0'))->format(DATE_ATOM),
            $object->getCreated()->format(DATE_ATOM),
        );

        // setter accepts DateTime (already-transformed output type)
        $object->setCreated(new \DateTime('2020-12-12'));
        $this->assertSame(
            (new \DateTime('2020-12-12'))->format(DATE_ATOM),
            $object->getCreated()->format(DATE_ATOM),
        );
    }

    // -------------------------------------------------------------------------
    // Chains on untyped properties
    // -------------------------------------------------------------------------

    public function testFilterChainWithTransformingFilterOnUntypedProperty(): void
    {
        // ['trim', 'dateTime'] on an untyped property — trim accepts string|null (from ?string
        // type hint) but the property is untyped, so no SchemaException is thrown and the
        // chain works correctly.
        $className = $this->generateClassFromFile('UntypedPropertyFilterChain.json');

        $object = new $className(['filteredProperty' => ' 2020-12-12 ']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());
        $this->assertSame(
            (new DateTime('2020-12-12'))->format(DATE_ATOM),
            $object->getFilteredProperty()->format(DATE_ATOM),
        );

        $object = new $className(['filteredProperty' => null]);
        $this->assertNull($object->getFilteredProperty());
    }

    // -------------------------------------------------------------------------
    // Mixed-return and accept-all follow-up filter
    // -------------------------------------------------------------------------

    /**
     * A transforming filter with a mixed return type followed by a filter that does NOT
     * accept all types must throw a SchemaException.
     */
    public function testMixedReturnTransformingFilterFollowedByTypedFilterThrowsException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage(
            'Filter trim is not compatible with the unconstrained output of'
            . ' transforming filter mixedReturnFilter for property filteredProperty',
        );

        $this->generateClassFromFileTemplate(
            'FilterChain.json',
            ['["mixedReturnFilter", "trim"]'],
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomTransformingFilter(
                    [self::class, 'serializeMixedReturn'],
                    [self::class, 'filterWithMixedReturn'],
                    'mixedReturnFilter',
                ),
            ),
            false,
        );
    }

    /**
     * A transforming filter with a mixed return type followed by an accept-all filter must not
     * throw, and neither must a concrete-return transforming filter followed by an accept-all
     * filter.
     */
    public function testFilterChainWithAcceptAllNextFilter(): void
    {
        $acceptAllFilter = $this->getCustomFilter([self::class, 'acceptAllFilter'], 'acceptAll');

        // Mixed-return transforming filter + accept-all follow-up — no SchemaException.
        $mixedReturnClassName = $this->generateClassFromFileTemplate(
            'FilterChain.json',
            ['["mixedReturnFilter", "acceptAll"]'],
            (new GeneratorConfiguration())
                ->addFilter(
                    $this->getCustomTransformingFilter(
                        [self::class, 'serializeMixedReturn'],
                        [self::class, 'filterWithMixedReturn'],
                        'mixedReturnFilter',
                    ),
                )
                ->addFilter($acceptAllFilter),
            false,
        );

        // The mixed-return filter just passes the string through; value is still a string.
        $object = new $mixedReturnClassName(['filteredProperty' => 'hello']);
        $this->assertSame('hello', $object->getFilteredProperty());

        // Concrete-return transforming filter (dateTime → DateTime) + accept-all follow-up
        // — no SchemaException.
        $dateTimeClassName = $this->generateClassFromFileTemplate(
            'FilterChain.json',
            ['["dateTime", "acceptAll"]'],
            (new GeneratorConfiguration())->addFilter($acceptAllFilter),
            false,
        );

        // The dateTime filter converts the string to DateTime; acceptAll passes it through.
        $object = new $dateTimeClassName(['filteredProperty' => '2020-12-12']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());
    }

    /**
     * A transforming filter with a non-nullable return type followed by a filter that does not
     * accept that return type must throw a SchemaException.
     */
    public function testNonNullableReturnTransformingFilterWithIncompatibleNextFilterThrowsException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage(
            'Filter trim is not compatible with transformed property type int'
            . ' for property filteredProperty',
        );

        $this->generateClassFromFileTemplate(
            'FilterChain.json',
            ['["intReturnFilter", "trim"]'],
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomTransformingFilter(
                    [self::class, 'serializeIntReturn'],
                    [self::class, 'filterWithIntReturn'],
                    'intReturnFilter',
                ),
            ),
            false,
        );
    }
}
