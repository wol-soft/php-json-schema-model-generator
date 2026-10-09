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
 * Only branches with exactly one active branch (if/then/else, oneOf) can own a filtered property.
 * A filtered property declared directly in an anyOf or allOf branch is rejected at generation time.
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
    // anyOf / allOf: rejected at generation time
    // -------------------------------------------------------------------------

    public static function ambiguousBranchFilterProvider(): array
    {
        return [
            'anyOf branch' => ['AnyOfBranchFilter.json', 'trim', 'name', 'anyOf'],
            'allOf branch' => ['AllOfBranchFilter.json', 'trim', 'name', 'allOf'],
            'allOf branch redeclaring a filtered root property' =>
                ['AllOfBranchOverridesRootFilter.json', 'trim', 'filteredProperty', 'allOf'],
            'anyOf branch nested in an allOf branch' =>
                ['AnyOfBranchFilterNestedInAllOf.json', 'trim', 'name', 'anyOf'],
            'anyOf branch referenced via $ref' => ['ReferencedAnyOfBranchFilter.json', 'trim', 'name', 'anyOf'],
        ];
    }

    #[DataProvider('ambiguousBranchFilterProvider')]
    public function testFilterOnPropertyDeclaredDirectlyInAnyOfOrAllOfBranchThrowsSchemaException(
        string $schemaFile,
        string $filterToken,
        string $propertyName,
        string $compositionKeyword,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/^' . preg_quote(
                "Filter '{$filterToken}' on property '{$propertyName}' declared directly in a branch of an"
                    . " '{$compositionKeyword}' composition is not supported in file ",
                '/',
            ) . '.+' . preg_quote(
                ': only branches of if/then/else and oneOf can own a filtered property',
                '/',
            ) . ' at line \d+, column \d+$/',
        );

        $this->generateClassFromFile($schemaFile);
    }
}
