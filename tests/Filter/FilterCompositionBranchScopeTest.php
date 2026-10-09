<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Filter;

use DateTime;
use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\Filter\InvalidFilterValueException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A filter on a property which is declared inside a branch of an object composition must only
 * affect the value while that branch is the active one. The branch class owns the filter; the
 * parent class receives the result of the active branch instead of running the filter itself.
 *
 * Branches with exactly one active branch (if/then/else, oneOf) own their filtered properties. A branch
 * of an anyOf composition cannot: several branches can match, so a filtered property declared in an anyOf
 * branch is rejected at generation time. All branches of an allOf are always active, so a single filtered
 * property is fine.
 *
 * A property which is filtered more than once by declarations that can apply to the same value (several
 * allOf branches, or the parent schema plus any branch) is rejected: the filters have no defined order.
 * Declarations which exclude each other are fine, e.g. a filter in then and a different one in else.
 *
 * A filter on a property of the if condition runs inside the condition, it is not applied to the parent.
 */
#[ApplicableDrafts]
class FilterCompositionBranchScopeTest extends AbstractFilterTestCase
{
    // -------------------------------------------------------------------------
    // if/then/else
    // -------------------------------------------------------------------------

    public function testFilterOnConditionalBranchPropertyRunsOnlyWhileBranchIsActive(): void
    {
        $className = $this->generateClassFromFile(
            'ConditionalBranchFilters.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        // if matches: the then branch is active, the else branch is inactive.
        $object = new $className(['kind' => 'a', 'thenValue' => '  then  ', 'elseValue' => '  else  ']);
        $this->assertSame('then', $object->getThenValue());
        $this->assertSame('  else  ', $object->getElseValue());

        // if does not match: the else branch is active, the then branch is inactive.
        $object = new $className(['kind' => 'b', 'thenValue' => '  then  ', 'elseValue' => '  else  ']);
        $this->assertSame('  then  ', $object->getThenValue());
        $this->assertSame('else', $object->getElseValue());

        // The if property is absent: "required" makes the if fail, so the else branch is active.
        $object = new $className(['thenValue' => '  then  ', 'elseValue' => '  else  ']);
        $this->assertSame('  then  ', $object->getThenValue());
        $this->assertSame('else', $object->getElseValue());

        // The setters re-evaluate the conditional: the inactive branch's filter must stay silent.
        $object = new $className(['kind' => 'a']);
        $object->setElseValue('  else  ');
        $this->assertSame('  else  ', $object->getElseValue());
        $object->setThenValue('  then  ');
        $this->assertSame('then', $object->getThenValue());

        // Switching the branch through the if property applies the filter of the newly active branch
        // to the value which was provided originally.
        $object = new $className(['kind' => 'b', 'thenValue' => '  then  ']);
        $this->assertSame('  then  ', $object->getThenValue());
        $object->setKind('a');
        $this->assertSame('then', $object->getThenValue());
        $object->setKind('b');
        $this->assertSame('  then  ', $object->getThenValue());
    }

    public function testFilterOnRootDeclaredPropertyInConditionalBranchRunsOnlyWhileBranchIsActive(): void
    {
        $className = $this->generateClassFromFile(
            'ConditionalBranchFilterOnRootDeclaredProperty.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        $object = new $className(['kind' => 'a', 'name' => '  name  ']);
        $this->assertSame('name', $object->getName());

        $object = new $className(['kind' => 'b', 'name' => '  name  ']);
        $this->assertSame('  name  ', $object->getName());

        $object = new $className(['name' => '  name  ']);
        $this->assertSame('  name  ', $object->getName());
    }

    public function testSamePropertyWithDifferentFiltersInThenAndElseUsesTheFilterOfTheActiveBranch(): void
    {
        $className = $this->generateClassFromFile(
            'ConditionalBranchSamePropertyDifferentFilters.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(false)
                ->setImmutable(false)
                ->addFilter($this->getCustomFilter([self::class, 'uppercaseFilterStringOnly'], 'upper')),
        );

        // then branch: trim only, the value must not be upper-cased.
        $object = new $className(['kind' => 'a', 'name' => '  name  ']);
        $this->assertSame('name', $object->getName());

        // else branch: upper-case only, the value must not be trimmed.
        $object = new $className(['kind' => 'b', 'name' => '  name  ']);
        $this->assertSame('  NAME  ', $object->getName());

        $object = new $className(['name' => '  name  ']);
        $this->assertSame('  NAME  ', $object->getName());
    }

    public function testTransformingFilterInConditionalBranchOnlyRunsWhileBranchIsActive(): void
    {
        $className = $this->generateClassFromFile(
            'ConditionalBranchTransformingFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false)->setSerialization(true),
        );

        // Active branch: the value is transformed and serialized back by the filter's serializer
        // (default outputFormat DATE_ISO8601).
        $object = new $className(['kind' => 'a', 'when' => '2024-01-01T10:00:00+00:00']);
        $this->assertInstanceOf(DateTime::class, $object->getWhen());
        $this->assertSame('2024-01-01T10:00:00+0000', $object->toArray()['when']);

        // Inactive branch: the filter must not run, so a value the filter could not parse is accepted as provided.
        $object = new $className(['kind' => 'b', 'when' => 'not a date']);
        $this->assertSame('not a date', $object->getWhen());
        $this->assertSame('not a date', $object->toArray()['when']);

        $object->setWhen('still not a date');
        $this->assertSame('still not a date', $object->getWhen());

        // A filter failure inside the active branch fails the branch and therefore the conditional.
        try {
            new $className(['kind' => 'a', 'when' => 'not a date']);
            $this->fail('Expected ConditionalException for an unparsable date in the active branch');
        } catch (ConditionalException $exception) {
            $this->assertNull($exception->getIfException());
            $this->assertInstanceOf(InvalidFilterValueException::class, $exception->getThenException());
            $this->assertNull($exception->getElseException());
        }
    }

    /**
     * The filter may not have run when its branch is inactive: a property which is declared by the parent
     * with a type and transformed by a branch accepts and returns the raw type as well as the transformed one.
     */
    public function testTransformingFilterInConditionalBranchWidensTheTypeOfAParentProperty(): void
    {
        $className = $this->generateClassFromFile(
            'ConditionalBranchTransformingFilterOnTypedParentProperty.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false)->setSerialization(true),
        );

        $this->assertEqualsCanonicalizing(
            ['string', 'DateTime', 'null'],
            $this->getReturnTypeNames($className, 'getWhen'),
        );
        $this->assertEqualsCanonicalizing(
            ['string', 'DateTime', 'null'],
            $this->getParameterTypeNames($className, 'setWhen'),
        );

        $object = new $className(['kind' => 'a', 'when' => '2024-01-01T10:00:00+00:00']);
        $this->assertInstanceOf(DateTime::class, $object->getWhen());
        $this->assertSame('2024-01-01T10:00:00+0000', $object->toArray()['when']);

        $object = new $className(['kind' => 'b', 'when' => 'not a date']);
        $this->assertSame('not a date', $object->getWhen());
        $this->assertSame('not a date', $object->toArray()['when']);

        // An already transformed value is accepted by the setter and serialized by the filter.
        $object = new $className(['kind' => 'a', 'when' => '2024-01-01T10:00:00+00:00']);
        $object->setWhen(new DateTime('2025-05-05T00:00:00+00:00'));
        $this->assertSame('2025-05-05T00:00:00+0000', $object->toArray()['when']);
    }

    /**
     * Only one of the branches is active, so then and else may transform the same property differently. The
     * serializer picks the filter which matches the value.
     */
    public function testDifferentTransformingFiltersInThenAndElseAreSerializedByTheMatchingFilter(): void
    {
        $className = $this->generateClassFromFile(
            'ConditionalBranchDifferentTransformingFilters.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(false)
                ->setImmutable(false)
                ->setSerialization(true)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'convertStringToInt'],
                    'stringToInt',
                )),
        );

        $this->assertEqualsCanonicalizing(
            ['string', 'DateTime', 'int', 'null'],
            $this->getReturnTypeNames($className, 'getValue'),
        );

        $object = new $className(['kind' => 'a', 'value' => '2024-01-01T10:00:00+00:00']);
        $this->assertInstanceOf(DateTime::class, $object->getValue());
        $this->assertSame('2024-01-01T10:00:00+0000', $object->toArray()['value']);

        $object = new $className(['kind' => 'b', 'value' => '42']);
        $this->assertSame(42, $object->getValue());
        $this->assertSame('42', $object->toArray()['value']);
    }

    // -------------------------------------------------------------------------
    // Compositions nested in the branch of another composition
    // -------------------------------------------------------------------------

    /**
     * The filtered value has to be handed over twice: from the then branch to the class of the oneOf
     * branch and from there to the parent class.
     */
    public function testFilterInConditionalNestedInOneOfBranchRunsOnlyWhileBothBranchesAreActive(): void
    {
        $className = $this->generateClassFromFile(
            'ConditionalNestedInOneOfBranch.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        // oneOf branch "x" and the then branch are active.
        $object = new $className(['type' => 'x', 'mode' => 1, 'name' => '  a  ']);
        $this->assertSame('a', $object->getName());

        // oneOf branch "x" is active, the condition of the nested conditional fails.
        $object = new $className(['type' => 'x', 'mode' => 2, 'name' => '  a  ']);
        $this->assertSame('  a  ', $object->getName());

        // The oneOf branch which contains the filter is inactive.
        $object = new $className(['type' => 'y', 'mode' => 1, 'name' => '  a  ']);
        $this->assertSame('  a  ', $object->getName());
    }

    public function testFilterInOneOfNestedInThenBranchRunsOnlyWhileBothBranchesAreActive(): void
    {
        $className = $this->generateClassFromFile(
            'OneOfNestedInThenBranch.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        // The then branch and the oneOf branch "a" are active.
        $object = new $className(['mode' => 1, 'sub' => 'a', 'name' => '  a  ']);
        $this->assertSame('a', $object->getName());

        // The then branch is active but the oneOf branch which contains the filter is not.
        $object = new $className(['mode' => 1, 'sub' => 'b', 'name' => '  a  ']);
        $this->assertSame('  a  ', $object->getName());

        // The then branch is inactive.
        $object = new $className(['mode' => 2, 'name' => '  a  ']);
        $this->assertSame('  a  ', $object->getName());
    }

    // -------------------------------------------------------------------------
    // oneOf
    // -------------------------------------------------------------------------

    public function testFilterOnOneOfBranchPropertyRunsOnlyForTheMatchingBranch(): void
    {
        $className = $this->generateClassFromFile(
            'OneOfBranchFilters.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(false)
                ->setImmutable(false)
                ->addFilter($this->getCustomFilter([self::class, 'uppercaseFilterStringOnly'], 'upper')),
        );

        // Branch "a" matches: trim only, the value must not be upper-cased.
        $object = new $className(['kind' => 'a', 'name' => '  name  ']);
        $this->assertSame('name', $object->getName());

        // Branch "b" matches: upper-case only, the value must not be trimmed.
        $object = new $className(['kind' => 'b', 'name' => '  name  ']);
        $this->assertSame('  NAME  ', $object->getName());

        // No branch matches: no filter result is adopted.
        try {
            new $className(['kind' => 'c', 'name' => '  name  ']);
            $this->fail('Expected OneOfException when no branch matches');
        } catch (OneOfException $exception) {
            $this->assertSame(0, $exception->getSucceededCompositionElements());
        }

        // A setter switching the matching branch applies the filter of the new branch.
        $object = new $className(['kind' => 'a', 'name' => '  name  ']);
        $object->setKind('b');
        $this->assertSame('  NAME  ', $object->getName());
    }

    // -------------------------------------------------------------------------
    // Filter on a property of the if condition
    // -------------------------------------------------------------------------

    /**
     * The condition is evaluated in a class of its own where the filter runs before the validators of the
     * property, so the trimmed code is compared with the const. The condition only selects the branch: the
     * parent class keeps the code as provided and no value is handed over.
     */
    public function testFilterOnIfConditionPropertyRunsInsideTheConditionButIsNotAppliedToTheParent(): void
    {
        $className = $this->generateClassFromFile(
            'IfConditionFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        // The trimmed code matches the const: the condition holds and the then branch is active.
        $object = new $className(['code' => '  abc  ', 'thenValue' => '  then  ', 'elseValue' => '  else  ']);
        $this->assertSame('  abc  ', $object->getCode());
        $this->assertSame('then', $object->getThenValue());
        $this->assertSame('  else  ', $object->getElseValue());

        // The condition fails: the else branch is active.
        $object = new $className(['code' => '  xyz  ', 'thenValue' => '  then  ', 'elseValue' => '  else  ']);
        $this->assertSame('  xyz  ', $object->getCode());
        $this->assertSame('  then  ', $object->getThenValue());
        $this->assertSame('  else  ', $object->getElseValue());
    }

    // -------------------------------------------------------------------------
    // Compositions on a property whose value is an object
    // -------------------------------------------------------------------------

    /**
     * A property-level anyOf with a single object branch: the value of the property is an instance of
     * the branch class, so the filter of the branch property runs inside that class and no property is
     * merged into a parent class. Must keep working.
     */
    public function testFilterInSingleObjectBranchOfPropertyLevelAnyOfRunsInsideTheBranch(): void
    {
        $className = $this->generateClassFromFile(
            'PropertyLevelSingleAnyOfObjectBranch.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        $object = new $className(['filteredProperty' => ['nested' => '  x  ']]);
        $this->assertSame('x', $object->getFilteredProperty()->getNested());

        // The trim filter doesn't accept an integer, so the value passes through unchanged.
        $object = new $className(['filteredProperty' => ['nested' => 5]]);
        $this->assertSame(5, $object->getFilteredProperty()->getNested());

        $object = new $className(['filteredProperty' => []]);
        $this->assertNull($object->getFilteredProperty()->getNested());

        $this->expectException(AnyOfException::class);
        new $className(['filteredProperty' => 'not an object']);
    }

    public static function propertyLevelDisjunctiveObjectBranchesProvider(): array
    {
        return [
            'anyOf (merged class)' => ['PropertyLevelAnyOfObjectBranches.json'],
            'oneOf' => ['PropertyLevelOneOfObjectBranches.json'],
        ];
    }

    /**
     * With several object branches on a property the value is created from the matching branch only, so
     * the filter of a non-matching branch must not touch the value. Must keep working.
     */
    #[DataProvider('propertyLevelDisjunctiveObjectBranchesProvider')]
    public function testFilterOnObjectBranchPropertyOfPropertyLevelCompositionRunsOnlyForTheMatchingBranch(
        string $schemaFile,
    ): void {
        $className = $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        $object = new $className(['filteredProperty' => ['kind' => 'a', 'name' => '  x  ']]);
        $this->assertSame('x', $object->getFilteredProperty()->getName());

        $object = new $className(['filteredProperty' => ['kind' => 'b', 'name' => '  x  ']]);
        $this->assertSame('  x  ', $object->getFilteredProperty()->getName());
    }

    /**
     * A conditional on an object-typed property is processed like a root-level conditional: the
     * branch properties are merged into the class of the property, so the same scoping applies.
     */
    public function testFilterInConditionalBranchOfObjectPropertyRunsOnlyWhileBranchIsActive(): void
    {
        $className = $this->generateClassFromFile(
            'PropertyLevelConditionalObjectBranch.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        $object = new $className(['filteredProperty' => ['kind' => 'a', 'name' => '  x  ']]);
        $this->assertSame('x', $object->getFilteredProperty()->getName());

        $object = new $className(['filteredProperty' => ['kind' => 'b', 'name' => '  x  ']]);
        $this->assertSame('  x  ', $object->getFilteredProperty()->getName());
    }

    // -------------------------------------------------------------------------
    // allOf: a single filtered property works; a property filtered more than once is rejected
    // -------------------------------------------------------------------------

    /**
     * Every branch of an allOf is always active, so the filter of a property declared in a single allOf
     * branch applies to every input. Must keep working.
     */
    public function testFilterOnPropertyOfSingleAllOfBranchRunsForEveryInput(): void
    {
        $className = $this->generateClassFromFile(
            'AllOfBranchFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        $object = new $className(['name' => '  x  ']);
        $this->assertSame('x', $object->getName());

        $object->setName('  y  ');
        $this->assertSame('y', $object->getName());
    }

    public static function propertyFilteredMoreThanOnceProvider(): array
    {
        return [
            'two allOf branches filter the same property' =>
                ['AllOfBranchesFilterSameProperty.json', 'name', 'trim', 'upper'],
            'parent property and allOf branch filter the same property' =>
                ['AllOfBranchOverridesRootFilter.json', 'filteredProperty', 'dateTime', 'trim'],
            'parent property and then branch filter the same property' =>
                ['ThenBranchOverridesRootFilter.json', 'name', 'trim', 'upper'],
            'parent property and oneOf branch filter the same property' =>
                ['OneOfBranchOverridesRootFilter.json', 'name', 'trim', 'upper'],
            'a oneOf and an if/then/else filter the same property' =>
                ['OneOfAndConditionalFilterSameProperty.json', 'name', 'trim', 'upper'],
        ];
    }

    /**
     * The filters of one property have no defined order. Without the rejection only one of them would run
     * (the first declaration wins) and the other would be silently dropped. The filter of the parent is
     * named first, followed by the branches in declaration order.
     */
    #[DataProvider('propertyFilteredMoreThanOnceProvider')]
    public function testPropertyFilteredMoreThanOnceThrowsSchemaException(
        string $schemaFile,
        string $propertyName,
        string $firstFilterToken,
        string $secondFilterToken,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/^' . preg_quote(
                "Property '{$propertyName}' is filtered more than once ('{$firstFilterToken}', '{$secondFilterToken}')"
                    . ' in file ',
                '/',
            ) . '.+' . preg_quote(': filters of the same property have no defined order', '/')
                . ' at line \d+, column \d+$/',
        );

        $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())
                ->addFilter($this->getCustomFilter([self::class, 'uppercaseFilterStringOnly'], 'upper')),
        );
    }

    // -------------------------------------------------------------------------
    // anyOf: a filtered property declared in a branch is rejected
    // -------------------------------------------------------------------------

    public static function anyOfBranchFilterProvider(): array
    {
        return [
            'anyOf branch' => ['AnyOfBranchFilter.json'],
            'anyOf branch nested in an allOf branch' => ['AnyOfBranchFilterNestedInAllOf.json'],
            'anyOf branch referenced via $ref' => ['ReferencedAnyOfBranchFilter.json'],
            // The oneOf branch could own the filter, but its value would have to be forwarded through
            // the anyOf, where several branches can match.
            'oneOf branch nested in an anyOf branch' => ['OneOfBranchFilterNestedInAnyOf.json'],
        ];
    }

    #[DataProvider('anyOfBranchFilterProvider')]
    public function testFilterOnPropertyDeclaredInAnyOfBranchThrowsSchemaException(string $schemaFile): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/^' . preg_quote(
                "Filter 'trim' on property 'name' declared in a branch of an 'anyOf' composition is not supported"
                    . ' in file ',
                '/',
            ) . '.+' . preg_quote(
                ': more than one branch can match, so the filtered value cannot be attributed to a single branch',
                '/',
            ) . ' at line \d+, column \d+$/',
        );

        $this->generateClassFromFile($schemaFile, new GeneratorConfiguration());
    }
}
