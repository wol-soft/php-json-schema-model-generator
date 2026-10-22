<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Filter;

use DateTime;
use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Filter\InvalidFilterValueException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Throwable;

/**
 * A filter on a property which is declared inside a branch of an object composition must only
 * affect the value while that branch is the active one. The branch class owns the filter; the
 * parent class receives the result of the active branch instead of running the filter itself.
 *
 * Branches with exactly one active branch (if/then/else, oneOf) own their filtered properties. A branch
 * of an anyOf composition cannot: several branches can match, so a filtered property declared in an anyOf
 * branch is rejected at generation time, unless every branch declares the property with the same filter. All
 * branches of an allOf are always active, so a filtered property is fine. A filter which runs on the parent for
 * every input is adopted by a property which the parent declares without a filter.
 *
 * A property which is filtered more than once by declarations that can apply to the same value (allOf branches
 * with diverging filters, or the parent schema plus any branch) is rejected: the filters have no defined order.
 * Several allOf branches with an identical filter list filter the same input, the filters run once.
 * Declarations which exclude each other are fine, e.g. a filter in then and a different one in else.
 *
 * A filter on a property of the if condition runs inside the condition, it is not applied to the parent.
 */
#[ApplicableDrafts]
class FilterCompositionBranchScopeTest extends AbstractFilterTestCase
{
    /** Accepts a string and returns mixed, the result can't be recognized by its type. */
    public static function filterStringToMixed(string $value): mixed
    {
        return strtoupper($value);
    }

    public static function serializeMixedToString(mixed $value): string
    {
        return strtolower((string) $value);
    }

    public static function countCharacters(string $value): int
    {
        return strlen($value);
    }

    public static function serializeCountAsCharacters(int $value): string
    {
        return str_repeat('x', $value);
    }

    /** Not idempotent: reveals a filter which runs more than once. */
    public static function appendExclamationMark(string $value): string
    {
        return $value . '!';
    }

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

    /**
     * A filter which declares no return type can't be recognized by its output. Its serializer is tried last,
     * otherwise the serializers of the other filters would be unreachable.
     */
    public function testSerializerOfAFilterWithoutReturnTypeIsTriedLast(): void
    {
        $className = $this->generateClassFromFile(
            'ConditionalBranchTransformingFilterWithUntypedResult.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(false)
                ->setImmutable(false)
                ->setSerialization(true)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeMixedToString'],
                    [self::class, 'filterStringToMixed'],
                    'stringToMixed',
                )),
        );

        // Output of the dateTime filter: serialized by the dateTime serializer, not by the untyped one.
        $object = new $className(['kind' => 'b', 'value' => '2024-01-01T10:00:00+00:00']);
        $this->assertInstanceOf(DateTime::class, $object->getValue());
        $this->assertSame('2024-01-01T10:00:00+0000', $object->toArray()['value']);

        // Output of the untyped filter.
        $object = new $className(['kind' => 'a', 'value' => 'Text']);
        $this->assertSame('TEXT', $object->getValue());
        $this->assertSame('text', $object->toArray()['value']);
    }

    /**
     * Then and else transform the same property with the same filter but different options. Both outputs have the
     * same type, so the type can't tell which serializer applies: the property remembers which filter produced the
     * value it holds and is serialized by exactly that filter.
     */
    public function testSameTransformingFilterWithDifferentOptionsIsSerializedWithTheOptionsOfTheProducingBranch(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            'ConditionalBranchesSameTransformingFilterDifferentOptions.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false)->setSerialization(true),
        );

        $date = '2026-10-22T13:45:00+00:00';

        $this->assertSame('2026-10-22', (new $className(['kind' => 'a', 'when' => $date]))->toArray()['when']);
        $this->assertSame('13:45', (new $className(['kind' => 'b', 'when' => $date]))->toArray()['when']);

        // A branch switch via a setter and via populate() changes the producing filter.
        $object = new $className(['kind' => 'a', 'when' => $date]);
        $object->setKind('b');
        $this->assertSame('13:45', $object->toArray()['when']);
        $object->populate(['kind' => 'a']);
        $this->assertSame('2026-10-22', $object->toArray()['when']);

        // An already transformed value set while the else branch is active is held for the else branch.
        $object->populate(['kind' => 'b']);
        $object->setWhen(new DateTime('2026-10-23T08:15:00+00:00'));
        $this->assertSame('08:15', $object->toArray()['when']);

        // 'aa' selects the then branch but violates the maxLength of kind: the rejected update keeps the filter.
        try {
            $object->setKind('aa');
            $this->fail('Expected the maxLength violation of kind');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('08:15', $object->toArray()['when']);
    }

    /**
     * The value is handed over through nested compositions: from a conditional inside an allOf branch, from the
     * branches of a oneOf, and from a oneOf inside a then branch next to an else branch. The filter which produced the
     * value is handed over together with the value.
     */
    public function testProducingFilterIsHandedOverThroughNestedCompositions(): void
    {
        $className = $this->generateClassFromFile(
            'NestedBranchesSameTransformingFilterDifferentOptions.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false)->setSerialization(true),
        );

        $date = '2026-10-22T13:45:00+00:00';
        $values = ['first' => $date, 'second' => $date, 'third' => $date];

        $object = new $className(['mode' => 1, 'sel' => 'p', 'sw' => 1, 'pick' => 'x'] + $values);
        $this->assertSame(
            ['2026-10-22', '2026', '2026'],
            array_values(array_intersect_key($object->toArray(), $values)),
        );

        $object = new $className(['mode' => 2, 'sel' => 'q', 'sw' => 1, 'pick' => 'y'] + $values);
        $this->assertSame(
            ['13:45', '13:45', '10'],
            array_values(array_intersect_key($object->toArray(), $values)),
        );

        $object = new $className(['mode' => 2, 'sel' => 'q', 'sw' => 2] + $values);
        $this->assertSame('22', $object->toArray()['third']);

        // A setter switches the branch of the nested composition.
        $object->setMode(1);
        $this->assertSame('2026-10-22', $object->toArray()['first']);
    }

    /**
     * Then and else use different transforming filters with the same output type.
     */
    public function testDifferentTransformingFiltersWithTheSameOutputTypeAreSerializedByTheProducingFilter(): void
    {
        $className = $this->generateClassFromFile(
            'ConditionalBranchesTransformingFiltersWithSameOutputType.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(false)
                ->setImmutable(false)
                ->setSerialization(true)
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeIntToString'],
                    [self::class, 'convertStringToInt'],
                    'stringToInt',
                ))
                ->addFilter($this->getCustomTransformingFilter(
                    [self::class, 'serializeCountAsCharacters'],
                    [self::class, 'countCharacters'],
                    'countToInt',
                )),
        );

        $object = new $className(['kind' => 'a', 'value' => '42']);
        $this->assertSame(42, $object->getValue());
        $this->assertSame('42', $object->toArray()['value']);

        $object = new $className(['kind' => 'b', 'value' => 'abc']);
        $this->assertSame(3, $object->getValue());
        $this->assertSame('xxx', $object->toArray()['value']);
    }

    // -------------------------------------------------------------------------
    // Definitions shared via $ref
    // -------------------------------------------------------------------------

    /**
     * A definition which is referenced by a branch property and by other properties is shared: transferring the
     * branch property to the parent (scoping its filter to the branch, handing over the value) must not change the
     * definition, otherwise the other properties referencing it lose their filter or validators.
     */
    #[DataProvider('collectErrorsProvider')]
    public function testSharedDefinitionReferencedByOneOfBranchesKeepsWorkingForEveryReference(
        bool $collectErrors,
        string $expectedException,
    ): void {
        $className = $this->generateClassFromFile(
            'SharedDefinitionInOneOfBranches.json',
            (new GeneratorConfiguration())->setCollectErrors($collectErrors)->setImmutable(false),
        );

        $values = ['code' => '  c  ', 'name' => '  n  ', 'label' => '  l  '];

        $object = new $className(['kind' => 'a'] + $values);
        $this->assertSame(['c', 'n', 'l'], [$object->getCode(), $object->getName(), $object->getLabel()]);

        // The second branch declares name without the definition, the root property code keeps its filter.
        $object = new $className(['kind' => 'b'] + $values);
        $this->assertSame(['c', '  n  ', 'l'], [$object->getCode(), $object->getName(), $object->getLabel()]);

        // The root property keeps the type check of the definition.
        try {
            new $className(['kind' => 'b', 'code' => 5]);
            $this->fail('Expected the type violation of code');
        } catch (Throwable $exception) {
            $this->assertInstanceOf($expectedException, $exception);
            $this->assertSame("Invalid type for 'code': requires 'string', got 'integer'", $exception->getMessage());
        }
    }

    public function testSharedDefinitionReferencedByConditionalBranchesKeepsWorkingForEveryReference(): void
    {
        $className = $this->generateClassFromFile(
            'SharedDefinitionInConditionalBranches.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false)->setSerialization(true),
        );

        $values = ['first' => '  f  ', 'second' => '  s  ', 'date' => '2026-01-01', 'other' => '2021-01-01'];

        $object = new $className(['kind' => 'a'] + $values);
        $this->assertSame(['f', 's'], [$object->getFirst(), $object->getSecond()]);
        $this->assertSame('2026', $object->toArray()['date']);
        // other references the same definition as date, the transforming filter of the then branch is not its own
        $this->assertSame('2021-01-01', $object->getOther());
        $this->assertSame('?string', (string) (new ReflectionMethod($className, 'getOther'))->getReturnType());

        $object = new $className(['kind' => 'b'] + $values);
        $this->assertSame(['f', '  s  '], [$object->getFirst(), $object->getSecond()]);

        // count references the definition of the then-only property amount: it keeps its type
        $this->assertSame('?int', (string) (new ReflectionMethod($className, 'getCount'))->getReturnType());

        try {
            new $className(['kind' => 'b', 'count' => 'abc']);
            $this->fail('Expected the type violation of count');
        } catch (ValidationException $exception) {
            $this->assertSame("Invalid type for 'count': requires 'int', got 'string'", $exception->getMessage());
        }
    }

    /**
     * The adopted filter belongs to the adopting property only, not to the definition it references.
     */
    public function testFilterAdoptedByAPropertyReferencingASharedDefinitionIsNotSharedWithOtherReferences(): void
    {
        $className = $this->generateClassFromFile(
            'SharedDefinitionAdoptedByAllOfBranches.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(false)
                ->setImmutable(false)
                ->addFilter($this->getCustomFilter([self::class, 'uppercaseFilterStringOnly'], 'upper'))
                ->addFilter($this->getCustomFilter([self::class, 'appendExclamationMark'], 'exclaim')),
        );

        $object = new $className(['title' => 'ab', 'other' => 'cd', 'plain' => 'ef']);
        $this->assertSame(['AB', 'cd!', 'ef'], [$object->getTitle(), $object->getOther(), $object->getPlain()]);

        // the definition's own validators still apply to every reference
        try {
            new $className(['plain' => 'e']);
            $this->fail('Expected the minLength violation of plain');
        } catch (ValidationException $exception) {
            $this->assertSame("Value for 'plain' must not be shorter than 2", $exception->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // A failing update leaves the model untouched
    // -------------------------------------------------------------------------

    public static function collectErrorsProvider(): array
    {
        return [
            'exception on the first violation' => [false, ValidationException::class],
            'collect errors' => [true, ErrorRegistryException::class],
        ];
    }

    /**
     * The new value of kind selects the then branch (the composition succeeds) but violates the maxLength of kind
     * itself. The values which the branches handed over and the properties which depend on the active branch must
     * not change.
     *
     * @param class-string<Throwable> $expectedException
     */
    #[DataProvider('collectErrorsProvider')]
    public function testSetterWhichFailsAfterTheCompositionLeavesTheBranchStateUntouched(
        bool $collectErrors,
        string $expectedException,
    ): void {
        $className = $this->generateClassFromFile(
            'ConditionalBranchSwitchRejectedByOwnValidation.json',
            (new GeneratorConfiguration())->setCollectErrors($collectErrors)->setImmutable(false),
        );

        $object = new $className(['kind' => 'a', 'name' => '  n  ']);
        $this->assertSame('  n  ', $object->getName());

        try {
            $object->setKind('ccc');
            $this->fail('Expected the maxLength violation of kind');
        } catch (Throwable $exception) {
            $this->assertInstanceOf($expectedException, $exception);
        }

        $this->assertSame('a', $object->getKind());
        // The name belongs to the then branch which was not activated by the failed update.
        $this->assertSame('  n  ', $object->getName());

        // The failed update must not influence later updates.
        $object->setName('  m  ');
        $this->assertSame('  m  ', $object->getName());
    }

    /**
     * The update switches the active branch, so the property which depends on the branch is validated again. Its
     * filtered value violates a constraint of the schema: the update is rejected before anything is assigned, the
     * model keeps the previous branch and values (a model the constructor would reject must never be left behind).
     *
     * @param class-string<Throwable> $expectedException
     */
    #[DataProvider('collectErrorsProvider')]
    public function testUpdateRejectedByThePropertyWhichDependsOnTheBranchLeavesTheModelUntouched(
        bool $collectErrors,
        string $expectedException,
    ): void {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            'ConditionalBranchSwitchRejectedByDependentProperty.json',
            (new GeneratorConfiguration())->setCollectErrors($collectErrors)->setImmutable(false),
        );

        $object = new $className(['kind' => 'b', 'title' => '  a  ']);
        $this->assertSame('  a  ', $object->getTitle());

        foreach (
            [
                'setter' => static fn() => $object->setKind('a'),
                'populate' => static fn() => $object->populate(['kind' => 'a']),
            ] as $update => $rejectedUpdate
        ) {
            try {
                $rejectedUpdate();
                $this->fail("Expected the minLength violation of the trimmed title ($update)");
            } catch (Throwable $exception) {
                $this->assertInstanceOf($expectedException, $exception);
                $this->assertSame("Value for 'title' must not be shorter than 3", $exception->getMessage());
            }

            $this->assertSame('b', $object->getKind(), $update);
            $this->assertSame('  a  ', $object->getTitle(), $update);
        }

        // The rejected updates don't influence later updates.
        $object->setTitle('  abc  ');
        $this->assertSame('  abc  ', $object->getTitle());

        $object->setKind('a');
        $this->assertSame('abc', $object->getTitle());

        // The dependent property is part of the update: it is processed with the new branch, not validated again.
        $object->populate(['kind' => 'b', 'title' => '  x  ']);
        $this->assertSame('  x  ', $object->getTitle());

        $object->populate(['kind' => 'a', 'title' => '  xyz  ']);
        $this->assertSame('xyz', $object->getTitle());
    }

    /**
     * populate() applies all properties or none. The failing update must not leave the branch state of the
     * attempt behind, the successful update switches the branch and validates the dependent property again.
     */
    public function testPopulateWhichFailsLeavesTheBranchStateUntouchedAndPopulateWhichSucceedsSwitchesTheBranch(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            'ConditionalBranchSwitchByPopulate.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        $object = new $className(['kind' => 'a', 'name' => '  n  ']);
        $this->assertSame('  n  ', $object->getName());

        // kind selects the then branch, label violates its maxLength.
        try {
            $object->populate(['kind' => 'c', 'label' => 'too long']);
            $this->fail('Expected the maxLength violation of label');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('a', $object->getKind());
        $this->assertSame('  n  ', $object->getName());

        // The successful update activates the then branch: the name which was not part of the update is filtered.
        $object->populate(['kind' => 'c', 'label' => 'ok']);
        $this->assertSame('c', $object->getKind());
        $this->assertSame('n', $object->getName());

        // And back: the branch is inactive, the name is returned as provided.
        $object->populate(['kind' => 'a']);
        $this->assertSame('  n  ', $object->getName());
    }

    /**
     * The composition only evaluates the components whose properties are part of an update, the other components
     * keep the result of their previous evaluation. The filtered value which the unchanged active branch handed
     * over must survive an update which only touches another branch.
     */
    #[DataProvider('collectErrorsProvider')]
    public function testUpdateOfAnInactiveOneOfBranchKeepsTheFilteredValueOfTheActiveBranch(
        bool $collectErrors,
    ): void {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            'OneOfBranchWithFilterAndUnrelatedBranch.json',
            (new GeneratorConfiguration())->setCollectErrors($collectErrors)->setImmutable(false),
        );

        $object = new $className(['type' => 'a', 'name' => '  n  ']);
        $this->assertSame('n', $object->getName());

        $object->setOther('o');
        $this->assertSame('n', $object->getName());

        $object->populate(['other' => 'p']);
        $this->assertSame('n', $object->getName());

        // The filtered property of the active branch is still updated by its own setter.
        $object->setName('  m  ');
        $this->assertSame('m', $object->getName());
    }

    /**
     * Same as above for the components of an allOf: an update which only affects the second conditional must not
     * drop the filtered value which the first (unchanged) conditional handed over.
     */
    #[DataProvider('collectErrorsProvider')]
    public function testUpdateOfOneAllOfComponentKeepsTheFilteredValueOfTheOtherComponent(
        bool $collectErrors,
    ): void {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            'AllOfConditionalsWithFilteredProperties.json',
            (new GeneratorConfiguration())->setCollectErrors($collectErrors)->setImmutable(false),
        );

        $object = new $className([
            'firstKind' => 'x',
            'secondKind' => 'x',
            'first' => '  a  ',
            'second' => '  b  ',
        ]);
        $this->assertSame('  a  ', $object->getFirst());
        $this->assertSame('  b  ', $object->getSecond());

        $object->setFirstKind('c');
        $this->assertSame('a', $object->getFirst());
        $this->assertSame('  b  ', $object->getSecond());

        // The second conditional becomes active, the first one is not part of the update.
        $object->setSecondKind('c');
        $this->assertSame('a', $object->getFirst());
        $this->assertSame('b', $object->getSecond());

        // The first conditional becomes inactive, the second one keeps its value.
        $object->setFirstKind('x');
        $this->assertSame('  a  ', $object->getFirst());
        $this->assertSame('b', $object->getSecond());

        $object->populate(['firstKind' => 'c', 'secondKind' => 'c']);
        $this->assertSame('a', $object->getFirst());
        $this->assertSame('b', $object->getSecond());

        $object->populate(['firstKind' => 'x']);
        $this->assertSame('  a  ', $object->getFirst());
        $this->assertSame('b', $object->getSecond());
    }

    /**
     * A populate which fails after the composition staged the value of its component leaves that staged value
     * behind. A later populate which keeps the result of the component must not adopt the stale value.
     */
    #[DataProvider('collectErrorsProvider')]
    public function testFailedPopulateDoesNotLeakItsStagedValueIntoALaterPopulate(bool $collectErrors): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            'OneOfBranchWithFilterAndOwnLimit.json',
            (new GeneratorConfiguration())->setCollectErrors($collectErrors)->setImmutable(false),
        );

        $object = new $className(['type' => 'a', 'name' => '  n  ']);
        $this->assertSame('n', $object->getName());

        try {
            $object->populate(['name' => '  q  ', 'label' => 'too long']);
            $this->fail('Expected the maxLength violation of label');
        } catch (Throwable) {
            // expected
        }

        $this->assertSame('n', $object->getName());

        $object->populate(['other' => 'o']);
        $this->assertSame('n', $object->getName());
    }

    /**
     * An immutable model has no setters and no composition validation on updates: the values which the active branch
     * hands over are adopted by the constructor only.
     */
    #[DataProvider('collectErrorsProvider')]
    public function testFilterInConditionalBranchOfImmutableModelRunsOnlyWhileBranchIsActive(
        bool $collectErrors,
    ): void {
        $className = $this->generateClassFromFile(
            'ConditionalBranchSwitchByPopulate.json',
            (new GeneratorConfiguration())->setCollectErrors($collectErrors)->setImmutable(true),
        );

        $object = new $className(['kind' => 'c', 'name' => '  n  ']);
        $this->assertSame('n', $object->getName());

        $object = new $className(['kind' => 'a', 'name' => '  n  ']);
        $this->assertSame('  n  ', $object->getName());

        $this->assertFalse(method_exists($object, 'setName'));
    }

    /**
     * A populate which re-passes the filtered property together with the property which switches the branch (a form
     * which submits all fields): the value which equals the current attribute is not processed by populate, the
     * filter of the newly active branch must nevertheless be applied.
     */
    #[DataProvider('collectErrorsProvider')]
    public function testPopulateWhichRepassesTheFilteredPropertyWhileSwitchingTheBranchAppliesTheFilter(
        bool $collectErrors,
    ): void {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            'ConditionalBranchSwitchByPopulate.json',
            (new GeneratorConfiguration())->setCollectErrors($collectErrors)->setImmutable(false),
        );

        $object = new $className(['kind' => 'a', 'name' => '  n  ']);
        $this->assertSame('  n  ', $object->getName());

        // The unchanged name is passed again while the then branch becomes active.
        $object->populate(['kind' => 'c', 'name' => '  n  ']);
        $this->assertSame('n', $object->getName());

        // The setter agrees with the populated state.
        $object->setName('  m  ');
        $this->assertSame('m', $object->getName());

        // And the other way round: the unchanged raw value is passed again while the branch becomes inactive.
        $object->populate(['kind' => 'a', 'name' => '  m  ']);
        $this->assertSame('  m  ', $object->getName());
    }

    /**
     * The class attribute of a property whose name starts with a digit is prefixed. The value which the active
     * branch hands over, and the validation of the property when the branch switches, address the prefixed attribute.
     */
    public function testFilterOfPropertyStartingWithDigitIsAppliedWhenTheBranchSwitches(): void
    {
        $className = $this->generateClassFromFile(
            'ConditionalBranchPropertyStartingWithDigit.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        $object = new $className(['kind' => 'a', '1st name' => '  n  ']);
        $this->assertSame('  n  ', $object->get1stName());

        $object->setKind('c');
        $this->assertSame('n', $object->get1stName());

        $object->setKind('a');
        $this->assertSame('  n  ', $object->get1stName());

        $object = new $className(['kind' => 'c', '1st name' => '  n  ']);
        $this->assertSame('n', $object->get1stName());
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

    /**
     * A filter which runs on the parent for every input (a single allOf branch, the identical filters of several
     * allOf branches) is adopted by a property which the parent schema declares without a filter, as if the
     * property were declared only in the branch: the filter runs first, the validators of the parent check the
     * filtered value. Without the adoption the declaration of the parent took precedence and the branch filter was
     * silently dropped.
     *
     * Several allOf branches which declare an identical filter list (same filters, options and order) don't chain
     * their filters: every branch filters the same input, so the result is the same and the filters run once.
     */
    public function testFilterOfAllOfBranchIsAdoptedByParentPropertyAndIdenticalFiltersOfAllOfBranchesRunOnce(): void
    {
        $className = $this->generateClassFromFile(
            'AllOfBranchFilterAdoption.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(false)
                ->setImmutable(false)
                ->setSerialization(true)
                ->addFilter($this->getCustomFilter([self::class, 'appendExclamationMark'], 'exclaim')),
        );

        $object = new $className([
            'title' => '  abc  ',
            'date' => '2026-10-21',
            'label' => 'l',
            'code' => 'c',
            'item' => ['title' => '  i  '],
            'stamp' => '2026-10-21T13:45:00+00:00',
        ]);

        // parent declares the property without a filter, a single allOf branch filters it
        $this->assertSame('abc', $object->getTitle());
        $this->assertInstanceOf(DateTime::class, $object->getDate());
        $this->assertSame('2026-10-21', $object->getDate()->format('Y-m-d'));
        $this->assertSame((new DateTime('2026-10-21'))->format(DATE_ISO8601), $object->toArray()['date']);
        // the branch declares the property via $ref, the filter options are adopted as well
        $this->assertSame('13:45', $object->getStamp()->format('H:i'));
        $this->assertSame('13:45', $object->toArray()['stamp']);
        // nested object schema with its own allOf
        $this->assertSame('i', $object->getItem()->getTitle());

        // identical filters in two allOf branches run once (with and without a declaration in the parent)
        $this->assertSame('l!', $object->getLabel());
        $this->assertSame('c!', $object->getCode());

        $object->setTitle('  xyz  ');
        $this->assertSame('xyz', $object->getTitle());
        $object->setLabel('m');
        $this->assertSame('m!', $object->getLabel());
        $object->setCode('d');
        $this->assertSame('d!', $object->getCode());

        // the minLength of the parent validates the filtered value
        try {
            new $className(['title' => '  ab  ']);
            $this->fail('Expected a ValidationException for the trimmed title');
        } catch (ValidationException $exception) {
            $this->assertSame("Value for 'title' must not be shorter than 3", $exception->getMessage());
        }
    }

    /**
     * The filter which every anyOf branch declares in the same way runs on the parent for every input, so it is
     * adopted by a property which the parent schema declares without a filter, like the filter of an allOf branch.
     */
    public function testFilterWhichEveryAnyOfBranchDeclaresIsAdoptedByParentProperty(): void
    {
        $className = $this->generateClassFromFile(
            'AnyOfBranchesSameFilterOnRootDeclaredProperty.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        $this->assertSame('xy', (new $className(['kind' => 'a', 'name' => '  xy  ']))->getName());
        $this->assertSame('xy', (new $className(['kind' => 'b', 'name' => '  xy  ']))->getName());

        $object = new $className(['kind' => 'a']);
        $object->setName('  yz  ');
        $this->assertSame('yz', $object->getName());

        // the minLength of the parent validates the filtered value
        try {
            new $className(['kind' => 'a', 'name' => '  x  ']);
            $this->fail('Expected a ValidationException for the trimmed name');
        } catch (ValidationException $exception) {
            $this->assertSame("Value for 'name' must not be shorter than 2", $exception->getMessage());
        }
    }

    public static function propertyFilteredMoreThanOnceProvider(): array
    {
        return [
            'two allOf branches filter the same property' =>
                ['AllOfBranchesFilterSameProperty.json', 'name', 'trim', 'upper'],
            'two allOf branches use the same filter with different options' =>
                ['AllOfBranchesSameFilterDifferentOptions.json', 'date', 'dateTime', 'dateTime'],
            'two allOf branches use the same filters in a different order' =>
                ['AllOfBranchesSameFiltersDifferentOrder.json', 'name', 'trim', 'upper'],
            'the filters of an allOf branch are a subset of the filters of another allOf branch' =>
                ['AllOfBranchFilterListIsSubsetOfOther.json', 'name', 'trim', 'trim'],
            // The two conditionals can select different branches, the forwarded values can diverge.
            'two allOf branches forward the same filter from nested conditionals' =>
                ['AllOfBranchesForwardSameFilterFromConditionals.json', 'name', 'trim', 'trim'],
            'parent property and allOf branch filter the same property' =>
                ['AllOfBranchOverridesRootFilter.json', 'filteredProperty', 'dateTime', 'trim'],
            // An identical repetition is rejected as well, the filter belongs to one declaration.
            'parent property and allOf branch declare the identical filter' =>
                ['ParentFilterRepeatedInAllOfBranch.json', 'name', 'trim', 'trim'],
            'parent property declared via $ref and then branch filter the same property' =>
                ['ParentPropertyByReferenceAndThenBranchFilterSameProperty.json', 'name', 'trim', 'upper'],
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

    /**
     * Whichever branch matches, the property is filtered by the same filter: the filtered value can be attributed
     * without knowing the matching branch. Works without the rejection, so it must keep working.
     */
    public function testFilterWhichEveryAnyOfBranchDeclaresForAPropertyIsApplied(): void
    {
        $className = $this->generateClassFromFile(
            'AnyOfBranchesSameFilter.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false),
        );

        $this->assertSame('x', (new $className(['kind' => 'a', 'name' => '  x  ']))->getName());
        $this->assertSame('x', (new $className(['kind' => 'b', 'name' => '  x  ']))->getName());

        $object = new $className(['kind' => 'a']);
        $object->setName('  y  ');
        $this->assertSame('y', $object->getName());
    }

    public static function anyOfBranchFilterProvider(): array
    {
        return [
            'anyOf branch' => ['AnyOfBranchFilter.json'],
            'anyOf branch nested in an allOf branch' => ['AnyOfBranchFilterNestedInAllOf.json'],
            'anyOf branches declare different filters' => ['AnyOfBranchesDifferentFilters.json'],
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
                ': more than one branch can match, the filtered value can only be attributed when every branch'
                    . ' declares the property with the same filter',
                '/',
            ) . ' at line \d+, column \d+$/',
        );

        $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())
                ->addFilter($this->getCustomFilter([self::class, 'uppercaseFilterStringOnly'], 'upper')),
        );
    }
}
