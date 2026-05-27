<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Filter;

use DateTime;
use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\ComposedValue\NotException;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Filter\InvalidFilterValueException;
use PHPModelGenerator\Exception\String\FormatException;
use PHPModelGenerator\Exception\Number\MinimumException;
use PHPModelGenerator\Exception\String\PatternException;
use PHPModelGenerator\Format\FormatValidatorFromRegEx;
use PHPModelGenerator\Model\GeneratorConfiguration;
use stdClass;

/**
 * Tests for runtime ordering of composition validators around a transforming filter.
 *
 * Covers: input-space compositions (allOf, anyOf, oneOf, not, if/then/else) running PRE-transform,
 * output-space compositions running POST-transform, mixed-space allOf split around the filter
 * boundary, validator-priority reassignment (pattern runs pre-transform, minimum post-transform),
 * format-validator skip guard (prevents TypeError when an already-transformed value reaches a
 * moved input-space validator), collect-errors mode for mixed-space allOf, non-transforming
 * filter leaving validator priority unchanged, and empty allOf branches.
 */
class FilterCompositionRuntimeTest extends AbstractFilterTestCase
{
    // -------------------------------------------------------------------------
    // Static callables local to this class
    // -------------------------------------------------------------------------

    /** Transforming filter callable that accepts mixed and returns DateTime. */
    public static function filterMixedToDateTime(mixed $value): DateTime
    {
        return new DateTime((string) $value);
    }

    /** Serializer for filterMixedToDateTime. */
    public static function serializeMixedToDateTime(DateTime $value): string
    {
        return $value->format(DATE_ATOM);
    }

    /** Accepts an object and returns DateTime. Used for the empty-object-branch allOf coverage test. */
    public static function filterObjectToDateTime(object $value): DateTime
    {
        return $value instanceof DateTime ? $value : new DateTime();
    }

    /** Serializer for filterObjectToDateTime. */
    public static function serializeObjectToDateTime(DateTime $value): string
    {
        return $value->format(DATE_ATOM);
    }

    /** Accepts a string and returns mixed. Used to exercise the empty-returnTypeNames code path. */
    public static function filterStringToMixed(string $value): mixed
    {
        return $value;
    }

    /** Serializer for filterStringToMixed. */
    public static function serializeMixedToString(mixed $value): string
    {
        return (string) $value;
    }

    // -------------------------------------------------------------------------
    // Validator-priority reassignment
    // -------------------------------------------------------------------------

    /**
     * With a string→int transforming filter, schema validators registered for input types
     * (pattern → string-space) must run PRE-transform, while validators registered for output
     * types (minimum → int-space) must run POST-transform.
     *
     * Pre-transform proof: "hello" filtered by (int)cast becomes 0 — a value that would pass
     * both pattern and minimum if the validator ran post-transform.  With the fixed ordering,
     * pattern runs against the raw string "hello" and correctly fails.
     *
     * Post-transform proof: -5 passed as an already-transformed integer skips the pre-transform
     * pipeline and goes straight to minimum, which catches the negative value.
     */
    public function testValidatorPriorityReassignmentAroundTransformingFilter(): void
    {
        $configuration = (new GeneratorConfiguration())
            ->setCollectErrors(false)
            ->setImmutable(false)
            ->addFilter($this->getCustomTransformingFilter(
                [self::class, 'serializeIntToString'],
                [self::class, 'convertStringToInt'],
                'stringToInt',
            ));

        $className = $this->generateClassFromFile(
            'ValidatorPriorityWithTransformingFilter.json',
            $configuration,
        );

        // "hello" casts to 0, which would pass both validators post-transform.
        // The fixed ordering makes pattern catch it against the raw string.
        try {
            new $className(['value' => 'hello']);
            $this->fail('Expected PatternException for input "hello"');
        } catch (PatternException $patternException) {
            $this->assertStringContainsString("doesn't match pattern", $patternException->getMessage());
        }

        // "-5" would silently become -5 post-transform, causing MinimumException instead.
        // The fixed ordering catches it at pattern (pre-transform) because "-5" ∉ \d+.
        try {
            new $className(['value' => '-5']);
            $this->fail('Expected PatternException for input "-5"');
        } catch (PatternException $patternException) {
            $this->assertStringContainsString("doesn't match pattern", $patternException->getMessage());
        }

        // Valid string input: pattern passes, filter transforms to 42, minimum passes.
        $object = new $className(['value' => '42']);
        $this->assertSame(42, $object->getValue());

        // Already-transformed int that satisfies minimum: skips pre-transform pipeline.
        $object = new $className(['value' => 42]);
        $this->assertSame(42, $object->getValue());

        // Already-transformed int that fails minimum: minimum runs post-transform.
        try {
            new $className(['value' => -5]);
            $this->fail('Expected MinimumException for input -5');
        } catch (MinimumException $minimumException) {
            $this->assertStringContainsString('must not be smaller than 0', $minimumException->getMessage());
        }
    }

    /**
     * A format validator (registered under the string type, hence input-space) that is moved
     * pre-filter must not run when the property value is already in the filter's output type-space.
     * FormatValidatorFromRegEx::validate() declares a string parameter under strict types, so
     * calling it with an already-transformed int throws TypeError rather than a validation
     * exception.  The skip guard prepended to moved input-space validators prevents this.
     */
    public function testFormatValidatorOnMultiTypePropertyDoesNotFireForAlreadyTransformedValue(): void
    {
        $configuration = (new GeneratorConfiguration())
            ->setCollectErrors(false)
            ->setImmutable(false)
            ->addFormat('onlyNumbers', new FormatValidatorFromRegEx('/^\d+$/'))
            ->addFilter($this->getCustomTransformingFilter(
                [self::class, 'serializeIntToString'],
                [self::class, 'convertStringToInt'],
                'stringToInt',
            ));

        $className = $this->generateClassFromFile(
            'MultiTypeFormatWithTransformingFilter.json',
            $configuration,
        );

        // String "42": format passes pre-transform, filter converts to int 42, minimum passes.
        $object = new $className(['value' => '42']);
        $this->assertSame(42, $object->getValue());

        // String "hello": format check fires against the raw string → FormatException.
        try {
            new $className(['value' => 'hello']);
            $this->fail('Expected FormatException for string input "hello"');
        } catch (FormatException $formatException) {
            $this->assertStringContainsString('onlyNumbers', $formatException->getMessage());
        }

        // Already-transformed int -5: skip guard bypasses format check (no TypeError),
        // execution reaches minimum and correctly throws MinimumException.
        try {
            new $className(['value' => -5]);
            $this->fail('Expected MinimumException for int input -5');
        } catch (MinimumException $minimumException) {
            $this->assertStringContainsString('must not be smaller than 0', $minimumException->getMessage());
        }

        // Already-transformed int 42: skip guard bypasses format check, minimum passes.
        $object = new $className(['value' => 42]);
        $this->assertSame(42, $object->getValue());
    }

    // -------------------------------------------------------------------------
    // allOf: output-only, input-only, mixed-space, non-transforming, empty branch
    // -------------------------------------------------------------------------

    /**
     * Output-only allOf (all branches output-space) runs POST-transform.
     *
     * Observable proof: "200" → filter → 200 → maximum:100 fails → AllOfException.
     * If the allOf ran PRE-transform, minimum and maximum would be no-ops on the string "200"
     * and both branches would always pass vacuously — no exception would be thrown.
     */
    public function testOutputOnlyAllOfCompositionRunsPostTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionAllOfOutputOnly.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(true)
                ->setImmutable(false)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'convertStringToInt'],
                    'stringToInt',
                )),
        );

        // "50": filter → 50 → minimum:0 passes, maximum:100 passes → result: 50.
        $object = new $className(['filteredProperty' => '50']);
        $this->assertSame(50, $object->getFilteredProperty());

        // "200": filter → 200 → maximum:100 fails → AllOfException.
        // Branch 0 (minimum:0) passes, branch 1 (maximum:100) fails → succeeded=1.
        try {
            new $className(['filteredProperty' => '200']);
            $this->fail('Expected AllOfException for "200"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 1 elements.
                  - Composition element #1: Valid
                  - Composition element #2: Failed
                    * Value for filteredProperty must not be larger than 100
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(1, $errors[0]->getSucceededCompositionElements());
        }

        // "-5": filter → -5 → minimum:0 fails → AllOfException.
        // Branch 0 (minimum:0) fails, branch 1 (maximum:100) passes → succeeded=1.
        try {
            new $className(['filteredProperty' => '-5']);
            $this->fail('Expected AllOfException for "-5"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 1 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be smaller than 0
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(1, $errors[0]->getSucceededCompositionElements());
        }

        // Already-transformed int 50 → filter skipped → output-space allOf still runs and passes.
        $object = new $className(['filteredProperty' => 50]);
        $this->assertSame(50, $object->getFilteredProperty());
    }

    /**
     * A transforming filter with a mixed accept type (empty accepted types) applied to a property
     * that gets its type from an allOf sibling branch. The allOf branch {type:string} is
     * classified as input-space, so it runs pre-transform. The filter then converts the string
     * to DateTime. No post-transform composition exists.
     */
    public function testAllOfPropertyWithMixedAcceptTransformingFilter(): void
    {
        $className = $this->generateClassFromFile(
            'AllOfPropertyWithMixedAcceptTransformingFilter.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(true)
                ->setImmutable(false)
                ->addFilter(
                    $this->getCustomTransformingFilter(
                        [self::class, 'serializeMixedToDateTime'],
                        [self::class, 'filterMixedToDateTime'],
                        'mixedAcceptDateTimeFilter',
                    ),
                ),
        );

        // Valid string input: input-space allOf passes, filter transforms to DateTime.
        $object = new $className(['filteredProperty' => '2024-01-01']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());

        // Non-string raw input via constructor: the constructor bypasses the setter type hint,
        // so 42 reaches validateFilteredProperty directly. The input-space allOf fires pre-transform
        // and rejects it with AllOfException (not a TypeError).
        try {
            new $className(['filteredProperty' => 42]);
            $this->fail('Expected AllOfException for non-string raw input');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Invalid type for filteredProperty. Requires string, got integer
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }

        // Already-constructed DateTime: pre-transform pipeline skipped, accepted as-is.
        $existingDateTime = new DateTime('2024-06-01');
        $object->setFilteredProperty($existingDateTime);
        $this->assertSame($existingDateTime, $object->getFilteredProperty());
    }

    /**
     * Input-space allOf runs PRE-transform.
     *
     * Observable proof: "2024" (4 chars < 5) throws AllOfException.  Post-transform that
     * input would produce a DateTime and the minLength check would silently skip.
     */
    public function testInputSpaceAllOfCompositionRunsPreTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionAllOfInputSpace.json',
            (new GeneratorConfiguration())->setCollectErrors(true)->setImmutable(false),
        );

        // "20240101" (8 chars ≥ 5): allOf passes pre-transform → filter → DateTime.
        $object = new $className(['filteredProperty' => '20240101']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());

        // "2024" (4 chars < 5): allOf fails pre-transform → AllOfException.
        // Single branch (minLength:5) fails → succeeded=0.
        try {
            new $className(['filteredProperty' => '2024']);
            $this->fail('Expected AllOfException for "2024"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be shorter than 5
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }

        // Already-transformed DateTime skips the input-space allOf.
        $dateTime = new DateTime('2024-06-01');
        $object = new $className(['filteredProperty' => $dateTime]);
        $this->assertSame($dateTime, $object->getFilteredProperty());
    }

    /**
     * Mixed-space allOf is split around the transforming filter.
     *
     * - {type:string, minLength:1} is input-space  (string constraint, runs PRE-transform).
     * - {minimum:0}                is output-space (numeric constraint, runs POST-transform).
     *
     * (a) "5"  → pre-allOf passes, filter→5, post-allOf passes → 5.
     * (b) ""   → pre-allOf fails (minLength:1) → AllOfException before the filter.
     * (c) "-5" → pre-allOf passes, filter→-5, post-allOf fails (minimum:0) → AllOfException.
     * (d) 5    → already-int, skip pre-allOf, post-allOf passes → 5.
     * (e) -5   → already-int, skip pre-allOf, post-allOf fails → AllOfException.
     */
    public function testMixedSpaceAllOfSplitAroundTransformingFilter(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionAllOfMixedSpaces.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(true)
                ->setImmutable(false)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'convertStringToInt'],
                    'stringToInt',
                )),
        );

        // (a) Valid string — both spaces satisfied.
        $object = new $className(['filteredProperty' => '5']);
        $this->assertSame(5, $object->getFilteredProperty());

        // (b) Empty string — input-space minLength:1 fails before filter runs.
        // Pre-subset has one branch (minLength:1); it fails → succeeded=0.
        try {
            new $className(['filteredProperty' => '']);
            $this->fail('Expected AllOfException for ""');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be shorter than 1
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }

        // (c) "-5" passes input-space (len≥1) but transforms to -5 which fails minimum:0.
        // Post-subset has one branch (minimum:0); it fails → succeeded=0.
        try {
            new $className(['filteredProperty' => '-5']);
            $this->fail('Expected AllOfException for "-5"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be smaller than 0
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }

        // (d) Already-int 5: skip input pipeline, post-allOf minimum:0 passes.
        $object = new $className(['filteredProperty' => 5]);
        $this->assertSame(5, $object->getFilteredProperty());

        // (e) Already-int -5: skip input pipeline, post-allOf minimum:0 fails → succeeded=0.
        try {
            new $className(['filteredProperty' => -5]);
            $this->fail('Expected AllOfException for -5 (already-int)');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be smaller than 0
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }
    }

    /**
     * Mixed-space allOf split in collect-errors mode collects errors from both subsets
     * independently.
     */
    public function testMixedSpaceAllOfSplitWithCollectErrors(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionAllOfMixedSpaces.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(true)
                ->setImmutable(false)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'convertStringToInt'],
                    'stringToInt',
                )),
        );

        // "5": both spaces satisfied → no errors.
        $object = new $className(['filteredProperty' => '5']);
        $this->assertSame(5, $object->getFilteredProperty());

        // "": pre-allOf minLength:1 fails → ErrorRegistryException containing AllOfException.
        try {
            new $className(['filteredProperty' => '']);
            $this->fail('Expected ErrorRegistryException for ""');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be shorter than 1
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }

        // "-5": pre-allOf passes (len≥1), filter→-5, post-allOf minimum:0 fails →
        // ErrorRegistryException containing one AllOfException.
        try {
            new $className(['filteredProperty' => '-5']);
            $this->fail('Expected ErrorRegistryException for "-5"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be smaller than 0
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }
    }

    /**
     * Non-transforming filter + allOf: the allOf validates the POST-filter (trimmed) value.
     *
     * Observable proof: "   hi   " trims to "hi" (2 chars) and fails minLength:5 → AllOfException.
     * If the allOf ran pre-trim, "   hi   " has 8 chars and would pass minLength:5.
     */
    public function testNonTransformingFilterWithAllOfValidatesAfterFilter(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionAllOfWithTrim.json',
            (new GeneratorConfiguration())->setCollectErrors(true)->setImmutable(false),
        );

        // "   hello   " trims to "hello" (5 chars) → allOf minLength:5 passes.
        $object = new $className(['filteredProperty' => '   hello   ']);
        $this->assertSame('hello', $object->getFilteredProperty());

        // "   hi   " trims to "hi" (2 chars) → allOf minLength:5 fails → AllOfException.
        try {
            new $className(['filteredProperty' => '   hi   ']);
            $this->fail('Expected AllOfException for "   hi   "');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AllOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be shorter than 5
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }
    }

    /**
     * An empty {} allOf branch under a transforming filter is a no-op: it imposes no constraints
     * so the filter runs and the value is transformed.
     */
    public function testEmptyAllOfBranchWithTransformingFilterIsNoOp(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionAllOfEmptyBranch.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        $object = new $className(['filteredProperty' => '2024-01-01']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());

        $dateTime = new DateTime('2024-06-01');
        $object = new $className(['filteredProperty' => $dateTime]);
        $this->assertSame($dateTime, $object->getFilteredProperty());
    }

    // -------------------------------------------------------------------------
    // anyOf: input-space and output-space
    // -------------------------------------------------------------------------

    /**
     * Input-space anyOf runs PRE-transform.
     *
     * Observable proof: "2024-01-01" succeeds (type:string branch passes pre-transform).
     * If anyOf ran POST-transform on DateTime, neither branch would pass → AnyOfException.
     */
    public function testInputSpaceAnyOfCompositionRunsPreTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionAnyOfInputOnly.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        // "2024-01-01" is a string → type:string branch passes → anyOf passes → DateTime.
        $object = new $className(['filteredProperty' => '2024-01-01']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());

        // Already-transformed DateTime skips the input-space anyOf.
        $dateTime = new DateTime('2024-06-01');
        $object = new $className(['filteredProperty' => $dateTime]);
        $this->assertSame($dateTime, $object->getFilteredProperty());
    }

    /**
     * Output-space anyOf runs POST-transform.
     *
     * Observable proof: "15" → filter → 15 → neither branch passes → AnyOfException.
     * If anyOf ran PRE-transform, minimum/maximum would be no-ops on the string "15"
     * and both branches would always pass vacuously — no exception would be thrown.
     */
    public function testOutputSpaceAnyOfCompositionRunsPostTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionAnyOfOutputSpace.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(true)
                ->setImmutable(false)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'convertStringToInt'],
                    'stringToInt',
                )),
        );

        // "5": filter → 5 → {min:0, max:10} passes → anyOf passes.
        $object = new $className(['filteredProperty' => '5']);
        $this->assertSame(5, $object->getFilteredProperty());

        // "15": filter → 15 → neither branch passes → AnyOfException; both fail → succeeded=0.
        try {
            new $className(['filteredProperty' => '15']);
            $this->fail('Expected AnyOfException for "15"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(AnyOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match at least one composition element.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be larger than 10
                  - Composition element #2: Failed
                    * Value for filteredProperty must not be smaller than 20
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }

        // Already-transformed int 5 → filter skipped → output-space anyOf still runs and passes.
        $object = new $className(['filteredProperty' => 5]);
        $this->assertSame(5, $object->getFilteredProperty());
    }

    // -------------------------------------------------------------------------
    // oneOf: input-space and output-space
    // -------------------------------------------------------------------------

    /**
     * Input-space oneOf runs PRE-transform.
     *
     * Observable proof: "20240101" (8 chars) passes only {minLength:5} → exactly one →
     * oneOf passes → DateTime.  Post-transform, DateTime is not a string so both minLength and
     * maxLength skip, both branches "pass", two pass → OneOfException.
     */
    public function testInputSpaceOneOfCompositionRunsPreTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionOneOfInputSpace.json',
            (new GeneratorConfiguration())->setCollectErrors(true)->setImmutable(false),
        );

        // "20240101" (8 chars): minLength:5 passes, maxLength:3 fails → 1 match → DateTime.
        $object = new $className(['filteredProperty' => '20240101']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());

        // "2024" (4 chars): minLength:5 fails, maxLength:3 fails → 0 matches → OneOfException.
        try {
            new $className(['filteredProperty' => '2024']);
            $this->fail('Expected OneOfException for "2024"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(OneOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match one composition element but matched 0 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be shorter than 5
                  - Composition element #2: Failed
                    * Value for filteredProperty must not be longer than 3
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }

        // Already-transformed DateTime skips the input-space oneOf.
        $dateTime = new DateTime('2024-06-01');
        $object = new $className(['filteredProperty' => $dateTime]);
        $this->assertSame($dateTime, $object->getFilteredProperty());
    }

    /**
     * Output-space oneOf runs POST-transform.
     *
     * Observable proof: "5" → 5 → exactly {min:0,max:10} passes → 1 match → oneOf passes.
     * "15" → 15 → neither branch passes → OneOfException.
     */
    public function testOutputSpaceOneOfCompositionRunsPostTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionOneOfOutputSpace.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(true)
                ->setImmutable(false)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'convertStringToInt'],
                    'stringToInt',
                )),
        );

        // "5": filter → 5 → {min:0, max:10} passes, {min:20, max:30} fails → exactly 1 → passes.
        $object = new $className(['filteredProperty' => '5']);
        $this->assertSame(5, $object->getFilteredProperty());

        // "15": filter → 15 → neither branch passes → OneOfException; both fail → succeeded=0.
        try {
            new $className(['filteredProperty' => '15']);
            $this->fail('Expected OneOfException for "15"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(OneOfException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match one composition element but matched 0 elements.
                  - Composition element #1: Failed
                    * Value for filteredProperty must not be larger than 10
                  - Composition element #2: Failed
                    * Value for filteredProperty must not be smaller than 20
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(0, $errors[0]->getSucceededCompositionElements());
        }

        // Already-transformed int 5 → filter skipped → output-space oneOf still runs and passes.
        $object = new $className(['filteredProperty' => 5]);
        $this->assertSame(5, $object->getFilteredProperty());
    }

    // -------------------------------------------------------------------------
    // not: input-space and output-space
    // -------------------------------------------------------------------------

    /**
     * Input-space not runs PRE-transform.
     *
     * Observable proof: "2024-01-01" (10 chars, valid date) → inner minLength:5 passes → not
     * violated → NotException. Post-transform, DateTime is not a string so minLength would skip,
     * the branch would "fail", not would pass — no exception.
     */
    public function testInputSpaceNotCompositionRunsPreTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionNotInputSpace.json',
            (new GeneratorConfiguration())->setCollectErrors(true)->setImmutable(false),
        );

        // "now" (3 chars): not { minLength: 5 } → inner fails (3 < 5) → not passes → DateTime.
        $object = new $className(['filteredProperty' => 'now']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());

        // "2024-01-01" (10 chars): inner minLength:5 passes → not violated → NotException.
        // Inner schema (minLength:5) passes → succeeded=1 → composition element is Valid.
        try {
            new $className(['filteredProperty' => '2024-01-01']);
            $this->fail('Expected NotException for "2024-01-01"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(NotException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match none composition element but matched 1 elements.
                  - Composition element #1: Valid
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(1, $errors[0]->getSucceededCompositionElements());
        }

        // Already-transformed DateTime → input-space not skipped → passes.
        $dateTime = new DateTime('2024-06-01');
        $object = new $className(['filteredProperty' => $dateTime]);
        $this->assertSame($dateTime, $object->getFilteredProperty());
    }

    /**
     * Output-space not runs POST-transform.
     *
     * Observable proof: "5" → filter → 5 → not { minimum: 0 }: 5 ≥ 0 → inner passes → not
     * violated → NotException. If not ran pre-transform, minimum would not apply to the string
     * "5" (is_int check fails), inner would fail, not would pass — no exception.
     */
    public function testOutputSpaceNotCompositionRunsPostTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionNotOutputSpace.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(true)
                ->setImmutable(false)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'convertStringToInt'],
                    'stringToInt',
                )),
        );

        // "-5": filter → -5 → not { minimum: 0 }: -5 < 0 → inner fails → not passes → -5.
        $object = new $className(['filteredProperty' => '-5']);
        $this->assertSame(-5, $object->getFilteredProperty());

        // "5": filter → 5 → inner passes → not violated → NotException; inner succeeds → succeeded=1.
        try {
            new $className(['filteredProperty' => '5']);
            $this->fail('Expected NotException for "5"');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(NotException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match none composition element but matched 1 elements.
                  - Composition element #1: Valid
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(1, $errors[0]->getSucceededCompositionElements());
        }

        // Already-transformed int -5 → filter skipped → not { minimum: 0 }: -5 < 0 → passes.
        $object = new $className(['filteredProperty' => -5]);
        $this->assertSame(-5, $object->getFilteredProperty());

        // Already-transformed int 5 → filter skipped → not { minimum: 0 }: 5 ≥ 0 → not violated.
        try {
            new $className(['filteredProperty' => 5]);
            $this->fail('Expected NotException for already-transformed int 5');
        } catch (ErrorRegistryException $exception) {
            $errors = $exception->getErrors();
            $this->assertCount(1, $errors);
            $this->assertContainsOnlyInstancesOf(NotException::class, $errors);
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by composition constraint.
                  Requires to match none composition element but matched 1 elements.
                  - Composition element #1: Valid
                ERROR,
                $errors[0]->getMessage(),
            );
            $this->assertSame(1, $errors[0]->getSucceededCompositionElements());
        }
    }

    // -------------------------------------------------------------------------
    // if/then/else: input-space, output-space, if/then only
    // -------------------------------------------------------------------------

    /**
     * Input-space if/then/else runs PRE-transform.
     *
     * Observable proof: "" (0 chars) triggers ConditionalException pre-transform.
     * Post-transform, DateTime is not a string so both minLength checks would skip, the
     * if-branch would "pass", the then-branch would "pass", and no exception would be thrown.
     */
    public function testInputSpaceIfThenElseCompositionRunsPreTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionIfThenElseInputSpace.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        // "20240101" (8 chars): if minLength:8 passes → then maxLength:20 passes → DateTime.
        $object = new $className(['filteredProperty' => '20240101']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());

        // "" (0 chars): if minLength:8 fails, else minLength:1 fails → ConditionalException.
        try {
            new $className(['filteredProperty' => '']);
            $this->fail('Expected ConditionalException for ""');
        } catch (ConditionalException $exception) {
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by conditional composition constraint
                  - Condition: Failed
                    * Value for filteredProperty must not be shorter than 8
                  - Conditional branch failed:
                    * Value for filteredProperty must not be shorter than 1
                ERROR,
                $exception->getMessage(),
            );
            $this->assertNotNull($exception->getIfException());
            $this->assertNull($exception->getThenException());
            $this->assertNotNull($exception->getElseException());
        }

        // Already-transformed DateTime skips the input-space conditional.
        $dateTime = new DateTime('2024-06-01');
        $object = new $className(['filteredProperty' => $dateTime]);
        $this->assertSame($dateTime, $object->getFilteredProperty());
    }

    /**
     * Output-space if/then/else runs POST-transform.
     *
     * Observable proof: "200" → filter → 200 → if:{min:0} passes → then:{max:100} fails
     * → ConditionalException.  If the conditional ran PRE-transform, minimum/maximum would be
     * no-ops on the string "200" and all branches would always pass vacuously — no exception.
     */
    public function testOutputSpaceIfThenElseCompositionRunsPostTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionIfThenElseOutputSpace.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(false)
                ->setImmutable(false)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'convertStringToInt'],
                    'stringToInt',
                )),
        );

        // "50": filter → 50 → if:{min:0} passes → then:{max:100} passes → success.
        $object = new $className(['filteredProperty' => '50']);
        $this->assertSame(50, $object->getFilteredProperty());

        // "-5": filter → -5 → if:{min:0} fails → else:{min:-100} passes (-5 ≥ -100) → success.
        $object = new $className(['filteredProperty' => '-5']);
        $this->assertSame(-5, $object->getFilteredProperty());

        // "200": filter → 200 → if passes → then:{max:100} fails → ConditionalException.
        try {
            new $className(['filteredProperty' => '200']);
            $this->fail('Expected ConditionalException for "200"');
        } catch (ConditionalException $exception) {
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by conditional composition constraint
                  - Condition: Valid
                  - Conditional branch failed:
                    * Value for filteredProperty must not be larger than 100
                ERROR,
                $exception->getMessage(),
            );
            $this->assertNull($exception->getIfException());
            $this->assertNotNull($exception->getThenException());
            $this->assertNull($exception->getElseException());
        }

        // "-200": filter → -200 → if fails → else:{min:-100} also fails → ConditionalException.
        try {
            new $className(['filteredProperty' => '-200']);
            $this->fail('Expected ConditionalException for "-200"');
        } catch (ConditionalException $exception) {
            $this->assertStringContainsString(
                <<<ERROR
                Invalid value for filteredProperty declined by conditional composition constraint
                  - Condition: Failed
                    * Value for filteredProperty must not be smaller than 0
                  - Conditional branch failed:
                    * Value for filteredProperty must not be smaller than -100
                ERROR,
                $exception->getMessage(),
            );
            $this->assertNotNull($exception->getIfException());
            $this->assertNull($exception->getThenException());
            $this->assertNotNull($exception->getElseException());
        }

        // Already-transformed int 50 → filter skipped → output-space conditional still runs and passes.
        $object = new $className(['filteredProperty' => 50]);
        $this->assertSame(50, $object->getFilteredProperty());
    }

    /**
     * if/then (no else) under a transforming filter: the conditional runs pre-transform.
     * When the if-condition fails (short string), no else means the value passes through.
     * When the if-condition passes but then fails, ConditionalException is thrown pre-transform.
     */
    public function testInputSpaceIfThenOnlyCompositionRunsPreTransform(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionIfThenOnlyInputSpace.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        // "20240101" satisfies if (minLength:8) and then (maxLength:20) → filter runs → DateTime.
        $object = new $className(['filteredProperty' => '20240101']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());

        // "now" (3 chars) fails if (minLength:8) → no else → conditional passes → filter → DateTime.
        $object = new $className(['filteredProperty' => 'now']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());

        // Overlong string: if passes, then fails → ConditionalException thrown pre-transform.
        try {
            new $className(['filteredProperty' => 'abcdefghijklmnopqrstuvwxyz']);
            $this->fail('Expected ConditionalException for overlong string');
        } catch (ConditionalException $exception) {
            $this->assertNull($exception->getIfException());
            $this->assertNotNull($exception->getThenException());
            $this->assertNull($exception->getElseException());
            $this->assertStringContainsString(
                'Value for filteredProperty must not be longer than 20',
                $exception->getMessage(),
            );
        }

        // Already-transformed DateTime skips the pre-transform conditional (pass-through).
        $dateTime = new DateTime('2024-06-01');
        $object = new $className(['filteredProperty' => $dateTime]);
        $this->assertSame($dateTime, $object->getFilteredProperty());
    }

    // -------------------------------------------------------------------------
    // Empty object branch: addExtendedInstanceOfCheckForObjectBranches positive path
    // -------------------------------------------------------------------------

    /**
     * When a transforming filter returns an object type (DateTime) and an allOf branch is
     * an empty object schema ({type: object} with no declared properties), the strict instanceof
     * check is removed from the composition branch and a property-level PropertyValidator is
     * added instead. The pre-transform allOf is wrapped in a FilterPreTransformGuardValidator
     * so that already-transformed DateTime values skip the object-type check; the guard is
     * unwrapped to scan inner branches and detect the empty object schema.
     */
    public function testExtendedInstanceOfCheckAddedForEmptyObjectBranchInAllOf(): void
    {
        $configuration = (new GeneratorConfiguration())
            ->setCollectErrors(false)
            ->setImmutable(false)
            ->addFilter($this->getCustomTransformingFilter(
                [self::class, 'serializeObjectToDateTime'],
                [self::class, 'filterObjectToDateTime'],
                'objectToDateTime',
            ));

        $className = $this->generateClassFromFile(
            'FilterCompositionDateTimeWithEmptyObjectBranch.json',
            $configuration,
        );

        // Already-transformed DateTime: pre-transform allOf guard skips the allOf check;
        // filter pass-through also skips; property-level instanceof check passes.
        $dateTime = new DateTime('2024-01-01');
        $object = new $className(['filteredProperty' => $dateTime]);
        $this->assertSame($dateTime, $object->getFilteredProperty());

        // Non-DateTime object: allOf({type:object}) passes pre-transform, filter converts to
        // DateTime. The property-level instanceof check confirms the stored value is a DateTime.
        $object = new $className(['filteredProperty' => new stdClass()]);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());

        // Non-object input (string): fails the pre-transform allOf {type:object} constraint.
        try {
            new $className(['filteredProperty' => 'not-an-object']);
            $this->fail('Expected AllOfException for non-object input');
        } catch (AllOfException $allOfException) {
            $this->assertStringContainsString('filteredProperty', $allOfException->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Mixed-return transforming filter: input-space composition moved without guard
    // -------------------------------------------------------------------------

    /**
     * When the transforming filter has a mixed return type (returnTypeNames = []), input-space
     * composition validators are moved to pre-filter priority directly — without wrapping in a
     * FilterPreTransformGuardValidator — because there is no return type to build a skip
     * condition from.
     *
     * Observable proof: minLength:1 runs PRE-filter. An empty string fails the anyOf check
     * before the filter is ever invoked.
     */
    public function testMixedReturnTransformingFilterMovesInputSpaceCompositionWithoutGuard(): void
    {
        $configuration = (new GeneratorConfiguration())
            ->setCollectErrors(false)
            ->setImmutable(false)
            ->addFilter($this->getCustomTransformingFilter(
                [self::class, 'serializeMixedToString'],
                [self::class, 'filterStringToMixed'],
                'stringToMixed',
            ));

        $className = $this->generateClassFromFile(
            'FilterCompositionMixedReturnWithInputSpaceComposition.json',
            $configuration,
        );

        // Non-empty string: anyOf (minLength:1) passes pre-filter, then filter runs.
        $object = new $className(['filteredProperty' => 'hello']);
        $this->assertSame('hello', $object->getFilteredProperty());

        // Empty string: anyOf (minLength:1) fires pre-filter and rejects it.
        // If the guard were absent, the filter would run first and minLength would never
        // be evaluated against the raw input.
        try {
            new $className(['filteredProperty' => '']);
            $this->fail('Expected AnyOfException for empty string');
        } catch (AnyOfException $anyOfException) {
            $this->assertStringContainsString('filteredProperty', $anyOfException->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Root-level input-space constraint on filtered sub-property
    // -------------------------------------------------------------------------

    /**
     * A root-level allOf with an input-space constraint targeting a filtered sub-property is
     * allowed at generation time. At runtime the property validator runs first (including the
     * filter), so the root-level constraint sees the already-transformed output value and is
     * effectively inert against it.
     */
    public function testRootLevelInputSpaceConstraintOnFilteredSubpropertyRunsSuccessfully(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionRootInputSpaceConstrainsFilteredSubproperty.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        $object = new $className(['filteredProperty' => '2024-01-01']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());

        $dateTime = new DateTime('2024-06-01');
        $object = new $className(['filteredProperty' => $dateTime]);
        $this->assertSame($dateTime, $object->getFilteredProperty());
    }

    // -------------------------------------------------------------------------
    // addExtendedInstanceOfCheckForObjectBranches: early return for non-empty branches
    // -------------------------------------------------------------------------

    /**
     * When a transforming filter (dateTime, returning non-primitive DateTime) is combined with
     * anyOf branches that are all non-object-typed (type: string, minLength: 1), no branch loses
     * its InstanceOfValidator. addExtendedInstanceOfCheckForObjectBranches finds no empty object
     * branch and returns early without adding a property-level instanceof check.
     *
     * The anyOf branches are both input-space (string-space), so they run PRE-transform; a valid
     * string passes and is converted to DateTime. An already-transformed DateTime bypasses the
     * pre-transform validators and the filter entirely. An unparseable string passes the anyOf
     * (via the type: string branch) but fails the filter, throwing InvalidFilterValueException.
     */
    public function testNoExtendedInstanceOfCheckWhenAllObjectBranchesAreNonEmpty(): void
    {
        $className = $this->generateClassFromFile(
            'FilterCompositionDateTimeWithNonEmptyObjectBranch.json',
        );

        // String input satisfies both anyOf branches and is transformed to DateTime.
        $object = new $className(['filteredProperty' => '2024-01-01']);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredProperty());

        // Already-transformed DateTime bypasses the filter entirely.
        $dateTime = new DateTime('2024-01-01');
        $object = new $className(['filteredProperty' => $dateTime]);
        $this->assertSame($dateTime, $object->getFilteredProperty());

        // A string that is not a valid datetime passes the anyOf but fails the filter.
        $this->expectException(InvalidFilterValueException::class);
        new $className(['filteredProperty' => 'Hello']);
    }
}
