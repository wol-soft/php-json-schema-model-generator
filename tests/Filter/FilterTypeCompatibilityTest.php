<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Filter;

use DateTime;
use RuntimeException;
use PHPModelGenerator\Exception\InvalidFilterException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;

/**
 * Tests for filter type-guard generation and callable reflection.
 *
 * Covers: zero-overlap rejection at generation time, partial-overlap (filter applied only when
 * the runtime type matches), full-overlap (filter always runs), mixed type hints on callables
 * (empty accepted types, no runtime guard generated), untyped properties, union type hints on
 * callables, bypass formulas for transforming filters on multi-type properties, union return types,
 * null consumed by a filter, reflection-level rejections (no parameter type hint, void/never
 * return types, missing return type on a transforming filter), and filters applied directly to
 * object-typed properties with nested schemas.
 */
class FilterTypeCompatibilityTest extends AbstractFilterTestCase
{
    // -------------------------------------------------------------------------
    // Static callables local to this class
    // -------------------------------------------------------------------------

    public static function uppercaseFilterAllTypes(mixed $value): ?string
    {
        return is_string($value) ? strtoupper($value) : null;
    }

    public static function uppercaseFilterFloat(float $value): string
    {
        return (string) $value;
    }

    public static function uppercaseFilterMixed(mixed $value): ?string
    {
        return is_string($value) ? strtoupper($value) : null;
    }

    public static function nullPassthrough(null $value): mixed
    {
        return $value;
    }

    public static function negateFilter(int $value): int
    {
        return -$value;
    }

    public static function negateFilterMixed(mixed $value): mixed
    {
        return is_int($value) ? -$value : $value;
    }

    public static function intOrStringFilter(string|int $value): string
    {
        return (string) $value;
    }

    public static function stringOrNullToStringFilter(string|null $value): string
    {
        return (string) $value;
    }

    public static function stringToIntOrStringFilter(string $value): int|string
    {
        return is_numeric($value) ? (int) $value : $value;
    }

    public static function intOrStringSerializer(int|string $value): string
    {
        return (string) $value;
    }

    /**
     * No type hint on first parameter — used by the no-type-hint InvalidFilterException test.
     *
     * @param mixed $value
     */
    public static function untypedFilter($value): string
    {
        return (string) $value;
    }

    /**
     * No return type hint — used by the missing-return-type InvalidFilterException test.
     *
     * @return string
     */
    public static function filterWithNoReturnType(string $value)
    {
        return $value;
    }

    /** Accepts mixed, returns int. Used by the mixed-input applyOutputType branch test. */
    public static function mixedInputToIntFilter(mixed $value): int
    {
        return (int) $value;
    }

    /** Accepts nullable string, returns int. Used by the null-accepted applyOutputType branch test. */
    public static function nullableStringToIntFilter(?string $value): int
    {
        return (int) $value;
    }

    /** Accepts any object and returns DateTime. Used by the nested-schema filter compatibility test. */
    public static function filterObjectToDateTime(object $value): DateTime
    {
        return $value instanceof DateTime ? $value : new DateTime();
    }

    /** Serializer for filterObjectToDateTime. */
    public static function serializeObjectToDateTime(DateTime $value): string
    {
        return $value->format(DATE_ATOM);
    }

    /** Void return type — used by the void-return-type InvalidFilterException test. */
    public static function filterWithVoidReturnType(string $value): void
    {
    }

    /** Never return type — used by the never-return-type InvalidFilterException test. */
    public static function filterWithNeverReturnType(string $value): never
    {
        throw new RuntimeException('never');
    }

    // -------------------------------------------------------------------------
    // mixed type hint (accept-all, no runtime guard)
    // -------------------------------------------------------------------------

    public function testFilterWithMixedTypeHintIsCompatibleWithAnyPropertyType(): void
    {
        // A callable with 'mixed' type hint derives empty acceptedTypes — no runtime type guard,
        // the filter runs for all value types.
        $className = $this->generateClassFromFile(
            'StringPropertyAcceptAllFilter.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilterAllTypes'], 'acceptAll'),
            ),
        );

        $object = new $className(['property' => 'hello']);
        $this->assertSame('HELLO', $object->getProperty());
    }

    public function testAddFilterWithMixedTypedCallableIsAllowed(): void
    {
        // A callable with (mixed $value) derives empty acceptedTypes — always compatible.
        $config = (new GeneratorConfiguration())->addFilter(
            $this->getCustomFilter([self::class, 'uppercaseFilterMixed'], 'mixedFilter'),
        );

        $this->assertNotNull($config->getFilter('mixedFilter'));
    }

    public function testMixedTypedCallableGeneratesNoRuntimeTypeCheck(): void
    {
        // A callable with (mixed $value) means "accept all types" — generation succeeds for both
        // typed and untyped properties, and no runtime typeCheck guard is emitted.

        // typed string property — filter runs
        $className = $this->generateClassFromFile(
            'StringPropertyMixedFilter.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilterMixed'], 'mixedFilter'),
            ),
        );
        $object = new $className(['property' => 'hello']);
        $this->assertSame('HELLO', $object->getProperty());

        // untyped property — filter runs
        $className = $this->generateClassFromFile(
            'UntypedPropertyMixedFilter.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilterMixed'], 'mixedFilter'),
            ),
        );
        $object = new $className(['property' => 'hello']);
        $this->assertSame('HELLO', $object->getProperty());

        // typed integer property — generation succeeds, filter runs (no typeCheck guard)
        $className = $this->generateClassFromFile(
            'IntegerPropertyMixedFilter.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'negateFilterMixed'], 'mixedFilter'),
            ),
        );
        $object = new $className(['property' => 5]);
        $this->assertSame(-5, $object->getProperty());
    }

    // -------------------------------------------------------------------------
    // Zero-overlap rejection
    // -------------------------------------------------------------------------

    public function testZeroOverlapThrowsSchemaException(): void
    {
        // float has zero overlap with int — SchemaException at generation time.
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/Filter numberFilter is not compatible with property type int for property property/',
        );

        $this->generateClassFromFile(
            'IntegerPropertyZeroOverlapFilter.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilterFloat'], 'numberFilter'),
            ),
        );
    }

    // -------------------------------------------------------------------------
    // Partial-overlap: filter applied only when runtime type matches
    // -------------------------------------------------------------------------

    public function testPartialOverlapStringFilterOnMultiTypeProperty(): void
    {
        // Filter callable has (string $value) — accepted type is string only.
        // Filter applies for string values; integer is not accepted so the filter
        // is skipped and the integer value passes through unchanged.
        $className = $this->generateClassFromFile(
            'StringIntegerPropertyCustomFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilterStringOnly'], 'customFilter'),
            ),
        );

        $object = new $className(['property' => 'hello']);
        $this->assertSame('HELLO', $object->getProperty()); // filter applied

        $object = new $className(['property' => 5]);
        $this->assertSame(5, $object->getProperty()); // filter skipped, value unchanged
    }

    public function testPartialOverlapStringFilterSkipsNullOnNullableProperty(): void
    {
        // Filter callable has (string $value) — null is not accepted.
        // Filter applies for string values; null passes through unchanged.
        $className = $this->generateClassFromFile(
            'StringNullPropertyCustomFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilterStringOnly'], 'customFilter'),
            ),
        );

        $object = new $className(['property' => 'hello']);
        $this->assertSame('HELLO', $object->getProperty()); // filter applied

        $object = new $className(['property' => null]);
        $this->assertNull($object->getProperty()); // filter skipped, null unchanged
    }

    public function testPartialOverlapNullFilterSkipsStringOnNullableProperty(): void
    {
        // Filter callable has (null $value) — only null is accepted.
        // Filter runs for null (passes through); string is not accepted so skipped.
        $className = $this->generateClassFromFile(
            'StringNullPropertyCustomFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->addFilter(
                $this->getCustomFilter([self::class, 'nullPassthrough'], 'customFilter'),
            ),
        );

        $object = new $className(['property' => null]);
        $this->assertNull($object->getProperty()); // filter ran, returned null

        $object = new $className(['property' => 'hello']);
        $this->assertSame('hello', $object->getProperty()); // filter skipped, string unchanged
    }

    public function testPartialOverlapFilterRunsWhenPropertyTypeIsInAcceptedTypes(): void
    {
        // Filter callable has (int $value) — overlap with integer property type, filter runs.
        $className = $this->generateClassFromFile(
            'IntegerPropertyCustomFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->addFilter(
                $this->getCustomFilter([self::class, 'negateFilter'], 'customFilter'),
            ),
        );

        $object = new $className(['property' => 5]);
        $this->assertSame(-5, $object->getProperty());
    }

    // -------------------------------------------------------------------------
    // Untyped properties
    // -------------------------------------------------------------------------

    public function testRestrictedFilterOnUntypedPropertyIsAllowed(): void
    {
        // 'trim' accepts string|null (from ?string type hint). An untyped property can hold any
        // value, so the filter is applied only when the runtime type matches — generation must
        // succeed without throwing a SchemaException.
        $className = $this->generateClassFromFile('UntypedPropertyFilter.json');

        $object = new $className(['property' => '  hello  ']);
        $this->assertSame('hello', $object->getProperty());

        $object = new $className(['property' => null]);
        $this->assertNull($object->getProperty());
    }

    public function testMixedTypedCallableFilterOnUntypedProperty(): void
    {
        // callable with (mixed $value) derives empty acceptedTypes — no runtime typeCheck.
        $className = $this->generateClassFromFile(
            'UntypedPropertyCustomFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilterMixed'], 'customFilter'),
            ),
        );

        $object = new $className(['property' => 'hello']);
        $this->assertSame('HELLO', $object->getProperty()); // filter applied for string
    }

    public function testNarrowFilterOnUntypedPropertySkipsNonMatchingType(): void
    {
        // Callable with (string $value) on an untyped property — filter applies for string,
        // integer is not accepted so the filter is skipped and the value passes through.
        $className = $this->generateClassFromFile(
            'UntypedPropertyCustomFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->addFilter(
                $this->getCustomFilter([self::class, 'uppercaseFilterStringOnly'], 'customFilter'),
            ),
        );

        $object = new $className(['property' => 'hello']);
        $this->assertSame('HELLO', $object->getProperty()); // filter applied for string

        $object = new $className(['property' => 5]);
        $this->assertSame(5, $object->getProperty()); // filter skipped, integer unchanged
    }

    // -------------------------------------------------------------------------
    // Union type hints on callables
    // -------------------------------------------------------------------------

    public function testFilterCallableWithUnionTypeHintAppliesFilterForBothAcceptedTypes(): void
    {
        // Both string and int are in the callable's union type hint — both pass the runtime guard.
        $className = $this->generateClassFromFile(
            'StringIntegerPropertyCustomFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->addFilter(
                $this->getCustomFilter([self::class, 'intOrStringFilter'], 'customFilter'),
            ),
        );

        // string input: is_string passes → filter runs → result is string (unchanged)
        $object = new $className(['property' => 'hello']);
        $this->assertSame('hello', $object->getProperty());

        // int input: is_int passes → filter runs → result is string '42'
        $object = new $className(['property' => 42]);
        $this->assertSame('42', $object->getProperty());
    }

    // -------------------------------------------------------------------------
    // Transforming filter: bypass formula and union return types
    // -------------------------------------------------------------------------

    /**
     * TransformingFilter (int→string via binary) on a string|integer property.
     * The filter callable accepts only int, so string values bypass the filter unchanged.
     * Verifies the bypass formula: bypass_names = base_names − accepted_non_null.
     */
    public function testTransformingFilterWithBypassOnMultiTypeProperty(): void
    {
        // base type = string|int; filter accepts int only → string bypasses, int is transformed.
        $className = $this->generateClassFromFile(
            'StringIntegerPropertyBinaryFilter.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(false)
                ->setImmutable(false)
                ->addFilter(
                    $this->getCustomTransformingFilter(
                        [self::class, 'serializeBinaryToInt'],
                        [self::class, 'filterIntToBinary'],
                        'binary',
                    ),
                ),
        );

        // int input: filter applies (decbin), returns binary string
        $object = new $className(['property' => 9]);
        $this->assertSame('1001', $object->getProperty());

        // string input: filter is skipped (string bypasses), value passes through unchanged
        $object = new $className(['property' => 'hello']);
        $this->assertSame('hello', $object->getProperty());

        // setter: int is re-transformed
        $object->setProperty(5);
        $this->assertSame('101', $object->getProperty());

        // setter: string is preserved (bypass)
        $object->setProperty('world');
        $this->assertSame('world', $object->getProperty());
    }

    /**
     * TransformingFilter with a union return type (int|string) on a string property.
     * The output type is widened to int|string; the setter must accept both int and string.
     */
    public function testTransformingFilterWithUnionReturnType(): void
    {
        $className = $this->generateClassFromFile(
            'StringPropertyIntOrStringFilter.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(false)
                ->setImmutable(false)
                ->addFilter(
                    $this->getCustomTransformingFilter(
                        [self::class, 'intOrStringSerializer'],
                        [self::class, 'stringToIntOrStringFilter'],
                        'intOrString',
                    ),
                ),
        );

        // numeric string → filter converts to int
        $object = new $className(['property' => '42']);
        $this->assertSame(42, $object->getProperty());

        // non-numeric string → filter returns as-is (string)
        $object = new $className(['property' => 'hello']);
        $this->assertSame('hello', $object->getProperty());

        // setter accepts int (pass-through: already a transformed output type)
        $object->setProperty(7);
        $this->assertSame(7, $object->getProperty());

        // setter accepts string (base type or output type string)
        $object->setProperty('abc');
        $this->assertSame('abc', $object->getProperty());
    }

    /**
     * TransformingFilter where both string and null are in its accepted types.
     * Null is NOT a bypass type — the filter runs for null and converts it to string.
     */
    public function testTransformingFilterNullConsumedByFilter(): void
    {
        $className = $this->generateClassFromFile(
            'StringNullPropertyStrOrNullFilter.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(false)
                ->setImmutable(false)
                ->addFilter(
                    $this->getCustomTransformingFilter(
                        [self::class, 'intOrStringSerializer'],
                        [self::class, 'stringOrNullToStringFilter'],
                        'strOrNull',
                    ),
                ),
        );

        // string input: filter runs and returns string
        $object = new $className(['property' => 'hello']);
        $this->assertSame('hello', $object->getProperty());

        // null input: filter runs (null IS accepted) and converts null → ''
        $object = new $className(['property' => null]);
        $this->assertSame('', $object->getProperty());
    }

    // -------------------------------------------------------------------------
    // Callable reflection rejections
    // -------------------------------------------------------------------------

    /**
     * Filter callable whose first parameter has no type hint throws an InvalidFilterException
     * at class-generation time (reflection cannot derive the accepted types).
     */
    public function testFilterCallableWithNoTypeHintThrowsInvalidFilterException(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessageMatches('/Filter noTypeHint must declare a type hint/');

        $this->generateClassFromFile(
            'StringPropertyNoTypeHintFilter.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomFilter([self::class, 'untypedFilter'], 'noTypeHint'),
            ),
        );
    }

    /**
     * Transforming filter callable with no return type hint throws an InvalidFilterException
     * at class-generation time (reflection cannot derive the output type).
     */
    public function testTransformingFilterWithMissingReturnTypeThrowsInvalidFilterException(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessageMatches('/Transforming filter noReturnType must declare a return type/');

        $this->generateClassFromFile(
            'StringPropertyNoReturnTypeFilter.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomTransformingFilter(
                    [self::class, 'intOrStringSerializer'],
                    [self::class, 'filterWithNoReturnType'],
                    'noReturnType',
                ),
            ),
        );
    }

    /**
     * Transforming filter callable with a void return type throws an InvalidFilterException
     * at class-generation time (void is not a valid output type for a transforming filter).
     */
    public function testTransformingFilterWithVoidReturnTypeThrowsInvalidFilterException(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessageMatches('/Transforming filter voidReturn must not declare a void return type/');

        $this->generateClassFromFile(
            'StringPropertyVoidReturnFilter.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomTransformingFilter(
                    [self::class, 'intOrStringSerializer'],
                    [self::class, 'filterWithVoidReturnType'],
                    'voidReturn',
                ),
            ),
        );
    }

    /**
     * Transforming filter callable with a never return type throws an InvalidFilterException
     * at class-generation time (never, like void, cannot produce a usable return value).
     */
    public function testTransformingFilterWithNeverReturnTypeThrowsInvalidFilterException(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessageMatches('/Transforming filter neverReturn must not declare a never return type/');

        $this->generateClassFromFile(
            'StringPropertyNeverReturnFilter.json',
            (new GeneratorConfiguration())->addFilter(
                $this->getCustomTransformingFilter(
                    [self::class, 'intOrStringSerializer'],
                    [self::class, 'filterWithNeverReturnType'],
                    'neverReturn',
                ),
            ),
        );
    }

    /**
     * When the transforming filter callable has a mixed parameter (acceptedTypes = []),
     * the bypass type list is empty: a mixed-input filter consumes all input types and
     * nothing is passed through unchanged. The getter returns only the filter output type
     * (int), with no bypass union.
     */
    public function testTransformingFilterWithMixedInputProducesEmptyBypassTypes(): void
    {
        $className = $this->generateClassFromFile(
            'StringPropertyMixedInputTransformingFilter.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(false)
                ->setImmutable(false)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'mixedInputToIntFilter'],
                    'mixedToInt',
                )),
        );

        // No bypass: the getter returns only the filter's output type (int).
        $this->assertEqualsCanonicalizing(['int'], $this->getReturnTypeNames($className, 'getFilteredProperty'));

        // String input: filter transforms to int.
        $object = new $className(['filteredProperty' => '42']);
        $this->assertSame(42, $object->getFilteredProperty());

        // Setter: string is still transformed to int (no int-bypass because accepted types = mixed).
        $object->setFilteredProperty('7');
        $this->assertSame(7, $object->getFilteredProperty());
    }

    /**
     * When the transforming filter callable accepts nullable string (?string), null is in
     * the accepted types, so bypassNullable is false — null is not a bypass type because
     * the filter handles it. The getter returns only the filter output type (int), and
     * null input is transformed by the filter rather than passed through unchanged.
     */
    public function testTransformingFilterWithNullableInputConsumesNullWithoutBypass(): void
    {
        $className = $this->generateClassFromFile(
            'NullableStringPropertyNullableStringTransformingFilter.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(false)
                ->setImmutable(false)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'nullableStringToIntFilter'],
                    'nullableStringToInt',
                )),
        );

        // No null bypass: the getter returns only the filter's output type (int), not int|null.
        $this->assertEqualsCanonicalizing(['int'], $this->getReturnTypeNames($className, 'getFilteredProperty'));

        // String input: filter transforms to int.
        $object = new $className(['filteredProperty' => '7']);
        $this->assertSame(7, $object->getFilteredProperty());

        // Null input: null is accepted by the filter (not a bypass), filter transforms to 0.
        $object = new $className(['filteredProperty' => null]);
        $this->assertSame(0, $object->getFilteredProperty());
    }

    // -------------------------------------------------------------------------
    // FilterValidator::runCompatibilityCheck — nested-schema property path
    // -------------------------------------------------------------------------

    /**
     * When a filter is applied to an object-typed property that has a nested schema,
     * runCompatibilityCheck takes the $nestedSchema !== null branch, derives typeNames as
     * ['object'] and isNullable as false, and verifies overlap with the filter's accepted types.
     * A filter accepting 'object' has full overlap; generation succeeds and the filter transforms
     * the instantiated inner object to a DateTime.
     *
     * Array input is first passed through the ObjectInstantiationDecorator (array → inner class
     * instance), then the transforming filter receives the inner class object and converts it to
     * DateTime. There is no bypass for nested-schema properties because the type check for the
     * generated inner class is not wrapped with a skip-check.
     */
    public function testFilterOnObjectTypedPropertyWithNestedSchema(): void
    {
        $configuration = (new GeneratorConfiguration())
            ->addFilter($this->getCustomTransformingFilter(
                [self::class, 'serializeObjectToDateTime'],
                [self::class, 'filterObjectToDateTime'],
                'objectToDateTime',
            ));

        $className = $this->generateClassFromFile(
            'ObjectPropertyWithNestedSchemaAndFilter.json',
            $configuration,
        );

        // Array input: ObjectInstantiationDecorator constructs the inner class from the array,
        // then filterObjectToDateTime receives the inner class instance and returns DateTime.
        $object = new $className(['filteredProperty' => ['name' => 'Alice']]);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());
    }
}
