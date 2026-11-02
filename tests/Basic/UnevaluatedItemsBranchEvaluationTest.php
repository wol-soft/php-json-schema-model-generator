<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\Arrays\UnevaluatedItemsException;
use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The `unevaluatedItems` keyword of a composition branch evaluates the items which are not evaluated by the
 * keywords of the branch itself: its items, additionalItems, contains and the compositions nested in the branch. It
 * doesn't see the keywords of the enclosing schema or of a sibling branch. A branch which fails evaluates nothing for
 * the enclosing array, a branch which succeeds hands the items it evaluated to the enclosing array.
 */
#[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
class UnevaluatedItemsBranchEvaluationTest extends AbstractPHPModelGeneratorTestCase
{
    #[DataProvider('validationMethodDataProvider')]
    public function testUnevaluatedItemsFalseOfAnAllOfBranchSeesTheContainsOfTheSameBranch(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('ContainsAndUnevaluatedItemsFalseInAllOfBranch.json', $configuration);

        // every item matches contains, nothing is left for unevaluatedItems
        foreach ([['a'], ['a', 'b']] as $tags) {
            $this->assertSame($tags, (new $className(['tags' => $tags]))->getTags());
        }

        // contains evaluates the matched item only
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tags' => ['a', 1]],
            $this->getExpectedExceptionClass($configuration, AllOfException::class),
            <<<'MSG'
            Invalid value for 'tags' declined by composition constraint
              Requires to match all composition elements but matched 0 elements
              - Composition element #1: Failed
                * Provided JSON for 'tags' contains not allowed unevaluated items [#1]
            MSG,
        );

        // contains doesn't match: the item is evaluated by nobody
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tags' => [1]],
            $this->getExpectedExceptionClass($configuration, AllOfException::class),
            $configuration->collectErrors()
                ? <<<'MSG'
                Invalid value for 'tags' declined by composition constraint
                  Requires to match all composition elements but matched 0 elements
                  - Composition element #1: Failed
                    * No item in array 'tags' matches the 'contains' constraint
                    * Provided JSON for 'tags' contains not allowed unevaluated items [#0]
                MSG
                : <<<'MSG'
                Invalid value for 'tags' declined by composition constraint
                  Requires to match all composition elements but matched 0 elements
                  - Composition element #1: Failed
                    * No item in array 'tags' matches the 'contains' constraint
                MSG,
        );
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testUnevaluatedItemsFalseOfAnAnyOfBranchSeesTheContainsOfTheSameBranch(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('ContainsAndUnevaluatedItemsFalseInAnyOfBranch.json', $configuration);

        // the first branch succeeds (contains evaluates every item) / the second branch succeeds (no items)
        foreach ([['a'], ['a', 'b'], []] as $tags) {
            $this->assertSame($tags, (new $className(['tags' => $tags]))->getTags());
        }

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tags' => ['a', 1]],
            $this->getExpectedExceptionClass($configuration, AnyOfException::class),
            <<<'MSG'
            Invalid value for 'tags' declined by composition constraint
              Requires to match at least one composition element
              - Composition element #1: Failed
                * Provided JSON for 'tags' contains not allowed unevaluated items [#1]
              - Composition element #2: Failed
                * Array 'tags' must not contain more than 0 items
            MSG,
        );
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testUnevaluatedItemsSchemaOfAnAllOfBranchSeesTheContainsOfTheSameBranch(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile(
            'ContainsAndUnevaluatedItemsSchemaInAllOfBranch.json',
            $configuration,
        );

        // the strings are matched by contains, the integers are validated by unevaluatedItems
        foreach ([['a'], ['a', 'b'], ['a', 1], [1, 'a', 2]] as $tags) {
            $this->assertSame($tags, (new $className(['tags' => $tags]))->getTags());
        }

        // the boolean is neither matched by contains nor an integer
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tags' => ['a', true]],
            $this->getExpectedExceptionClass($configuration, AllOfException::class),
            <<<'MSG'
            Invalid value for 'tags' declined by composition constraint
              Requires to match all composition elements but matched 0 elements
              - Composition element #1: Failed
                * Invalid unevaluated items in array 'tags':
                  - invalid unevaluated item #1
                    * Invalid type for 'unevaluated item': requires 'int', got 'boolean'
            MSG,
        );
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testUnevaluatedItemsFalseOfABranchSeesTheCompositionNestedInTheSameBranch(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('AnyOfNestedInBranchWithUnevaluatedItemsFalse.json', $configuration);

        // the tuple of the nested anyOf evaluates the first item / the second branch of the anyOf accepts no items
        foreach ([['a'], []] as $tags) {
            $this->assertSame($tags, (new $className(['tags' => $tags]))->getTags());
        }

        // the tuple evaluates the first item only
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tags' => ['a', 1]],
            $this->getExpectedExceptionClass($configuration, AllOfException::class),
            <<<'MSG'
            Invalid value for 'tags' declined by composition constraint
              Requires to match all composition elements but matched 0 elements
              - Composition element #1: Failed
                * Provided JSON for 'tags' contains not allowed unevaluated items [#1]
            MSG,
        );
    }

    /**
     * The contains of a branch evaluates its matched items for the unevaluatedItems of the enclosing array also if
     * the branch is a reference to a definition. Both properties reference the same definition: the first reference
     * creates the property of the definition, the second one is a proxy of it.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testContainsOfAReferencedBranchEvaluatesItemsForTheUnevaluatedItemsOutsideOfTheBranch(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile(
            'ContainsInAReferencedBranchWithUnevaluatedItemsFalseOutsideOfTheBranch.json',
            $configuration,
        );

        foreach (['tags', 'labels'] as $propertyName) {
            foreach ([['a'], ['a', 'b']] as $items) {
                $this->assertSame(
                    $items,
                    (new $className([$propertyName => $items]))->{'get' . ucfirst($propertyName)}(),
                );
            }

            $this->assertInstantiationFailsWithMessage(
                $className,
                [$propertyName => ['a', 1]],
                $this->getExpectedExceptionClass($configuration, UnevaluatedItemsException::class),
                "Provided JSON for '$propertyName' contains not allowed unevaluated items [#1]",
            );
        }
    }

    /**
     * The items a branch evaluates are only credited for an array: a string is accepted by the items keyword of the
     * branch (the keyword applies to arrays only) and has no items to credit.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testValueWhichIsNoArrayIsNotCreditedByTheArrayBranchesOfAnUntypedProperty(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile(
            'ArrayBranchOfAnUntypedPropertyWithUnevaluatedItemsFalse.json',
            $configuration,
        );

        foreach (['ab', 'abcdefg', ['a'], []] as $tags) {
            $this->assertSame($tags, (new $className(['tags' => $tags]))->getTags());
        }

        // the items keyword of the first branch rejects the integer, the second branch evaluates no item
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tags' => ['a', 1]],
            $this->getExpectedExceptionClass($configuration, UnevaluatedItemsException::class),
            "Provided JSON for 'tags' contains not allowed unevaluated items [#0, #1]",
        );
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testUnevaluatedItemsFalseOfAThenBranchSeesTheContainsOfTheSameBranch(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('ContainsAndUnevaluatedItemsFalseInThenBranch.json', $configuration);

        // no items: the condition fails and there is no else branch / contains matches every item
        foreach ([[], ['a'], ['a', 'b']] as $tags) {
            $this->assertSame($tags, (new $className(['tags' => $tags]))->getTags());
        }

        // contains evaluates the matched item only
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tags' => ['a', 1]],
            $this->getExpectedExceptionClass($configuration, ConditionalException::class),
            <<<'MSG'
            Invalid value for 'tags' declined by conditional composition constraint
              - Condition: Valid
              - Conditional branch failed:
                * Provided JSON for 'tags' contains not allowed unevaluated items [#1]
            MSG,
        );
    }

    /**
     * The condition evaluates the items of its own contains for its own unevaluatedItems: ['a'] satisfies the
     * condition and takes the then branch, [1] and ['a', 1] fail the condition and take the else branch.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testUnevaluatedItemsFalseOfAnIfConditionSeesTheContainsOfTheSameCondition(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('ContainsAndUnevaluatedItemsFalseInIfCondition.json', $configuration);

        foreach ([['a'], ['a', 'b', 'c'], [1, 2, 3], ['a', 1, 2]] as $tags) {
            $this->assertSame($tags, (new $className(['tags' => $tags]))->getTags());
        }

        // the condition fails (contains matches the first item only, the second item is unevaluated) and the else
        // branch requires three items
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tags' => ['a', 1]],
            $this->getExpectedExceptionClass($configuration, ConditionalException::class),
            <<<'MSG'
            Invalid value for 'tags' declined by conditional composition constraint
              - Condition: Failed
                * Provided JSON for 'tags' contains not allowed unevaluated items [#1]
              - Conditional branch failed:
                * Array 'tags' must not contain less than 3 items
            MSG,
        );
    }

    /**
     * A condition which fails evaluates nothing for the enclosing array, also if its contains matched an item
     * before another keyword of the condition rejected the value. Crediting the item would accept ['a']: the
     * condition fails on minItems, the else branch evaluates no item.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testFailedIfConditionCreditsNothingToTheUnevaluatedItemsOfTheEnclosingArray(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile(
            'OuterUnevaluatedItemsFalseWithIfConditionWhichHasContainsAndUnevaluatedItemsFalse.json',
            $configuration,
        );

        // the condition succeeds and evaluates both items
        $this->assertSame(['a', 'b'], (new $className(['tags' => ['a', 'b']]))->getTags());

        // the condition fails on minItems after its contains matched the item
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tags' => ['a']],
            $this->getExpectedExceptionClass($configuration, UnevaluatedItemsException::class),
            "Provided JSON for 'tags' contains not allowed unevaluated items [#0]",
        );

        // the condition fails on its unevaluatedItems
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tags' => ['a', 1]],
            $this->getExpectedExceptionClass($configuration, UnevaluatedItemsException::class),
            "Provided JSON for 'tags' contains not allowed unevaluated items [#0, #1]",
        );
    }

    /**
     * A then or else branch which fails evaluates nothing for the enclosing array. The failing conditional is
     * reported, the unevaluatedItems of the enclosing array still validates the value if the errors are collected:
     * the items matched by the contains of the failed branch are reported as unevaluated.
     *
     * @return array<string, array{0: string, 1: array<int, mixed>, 2: array<int, mixed>, 3: string, 4: string}>
     */
    public static function failedConditionalBranchProvider(): array
    {
        return [
            'then branch' => [
                'OuterUnevaluatedItemsFalseWithThenBranchWhichHasContainsAndUnevaluatedItemsFalse.json',
                // the then branch matches all items and evaluates them
                ['a', 'b', 'c'],
                // the then branch fails on maxItems after its contains matched every item
                ['a', 'b', 'c', 'd'],
                <<<'MSG'
                Invalid value for 'tags' declined by conditional composition constraint
                  - Condition: Valid
                  - Conditional branch failed:
                    * Array 'tags' must not contain more than 3 items
                MSG,
                "Provided JSON for 'tags' contains not allowed unevaluated items [#0, #1, #2, #3]",
            ],
            'else branch' => [
                'OuterUnevaluatedItemsFalseWithElseBranchWhichHasContainsAndUnevaluatedItemsFalse.json',
                // the condition fails and the else branch matches both items and evaluates them
                [1, 2],
                // the else branch fails on minItems after its contains matched the item
                [1],
                <<<'MSG'
                Invalid value for 'tags' declined by conditional composition constraint
                  - Condition: Failed
                    * Array 'tags' must not contain less than 3 items
                  - Conditional branch failed:
                    * Array 'tags' must not contain less than 2 items
                MSG,
                "Provided JSON for 'tags' contains not allowed unevaluated items [#0]",
            ],
        ];
    }

    /**
     * @param array<int, mixed> $validTags
     * @param array<int, mixed> $invalidTags
     */
    #[DataProvider('failedConditionalBranchProvider')]
    public function testFailedThenAndElseBranchesCreditNothingToTheUnevaluatedItemsOfTheEnclosingArray(
        string $schemaFile,
        array $validTags,
        array $invalidTags,
        string $conditionalMessage,
        string $unevaluatedItemsMessage,
    ): void {
        // the unevaluatedItems of the enclosing array is only validated after the failed conditional if the errors
        // are collected
        $className = $this->generateClassFromFile($schemaFile, new GeneratorConfiguration());

        $this->assertSame($validTags, (new $className(['tags' => $validTags]))->getTags());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tags' => $invalidTags],
            ErrorRegistryException::class,
            $conditionalMessage . "\n" . $unevaluatedItemsMessage,
        );

        $directClassName = $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $this->assertInstantiationFailsWithMessage(
            $directClassName,
            ['tags' => $invalidTags],
            ConditionalException::class,
            $conditionalMessage,
        );
    }

    /**
     * The unevaluatedItems of a branch doesn't see the indices which a sibling branch evaluated: the first branch
     * validates (and evaluates) every item as an integer, the second branch evaluates nothing and rejects every item.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testUnevaluatedItemsFalseOfABranchDoesNotSeeTheItemsWhichASiblingBranchEvaluated(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile(
            'SiblingBranchesWithUnevaluatedItemsSchemaAndFalse.json',
            $configuration,
        );

        // no item is left for the second branch
        $this->assertSame([], (new $className(['tags' => []]))->getTags());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tags' => [1, 2]],
            $this->getExpectedExceptionClass($configuration, AllOfException::class),
            <<<'MSG'
            Invalid value for 'tags' declined by composition constraint
              Requires to match all composition elements but matched 1 element
              - Composition element #1: Valid
              - Composition element #2: Failed
                * Provided JSON for 'tags' contains not allowed unevaluated items [#0, #1]
            MSG,
        );
    }

    /**
     * A branch which succeeds hands the items it evaluated (its contains matched every item, its own
     * unevaluatedItems evaluated the rest) to the unevaluatedItems of the enclosing array. A branch which fails
     * evaluates nothing for the enclosing array: the items matched by the contains of a failed branch stay
     * unevaluated. Crediting them would accept ['a'] although no succeeding branch evaluates the item.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testBranchWithItsOwnUnevaluatedItemsCreditsTheEnclosingArrayOnlyIfTheBranchSucceeds(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile(
            'OuterAndBranchUnevaluatedItemsFalseWithContainsInTheBranch.json',
            $configuration,
        );

        // the first branch succeeds and evaluates both items
        $this->assertSame(['a', 'b'], (new $className(['tags' => ['a', 'b']]))->getTags());

        // the first branch fails on minItems: the item matched by its contains isn't evaluated by anybody
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tags' => ['a']],
            $this->getExpectedExceptionClass($configuration, UnevaluatedItemsException::class),
            "Provided JSON for 'tags' contains not allowed unevaluated items [#0]",
        );

        // the first branch fails on its unevaluatedItems: neither the matched nor the unevaluated item is credited
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['tags' => ['a', 1]],
            $this->getExpectedExceptionClass($configuration, UnevaluatedItemsException::class),
            "Provided JSON for 'tags' contains not allowed unevaluated items [#0, #1]",
        );
    }
}
