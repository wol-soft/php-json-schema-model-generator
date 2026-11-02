<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use DateTime;
use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ComposedValue\AnyOfException;
use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\ComposedValue\NotException;
use PHPModelGenerator\Exception\Dependency\InvalidPropertyDependencyException;
use PHPModelGenerator\Exception\Dependency\InvalidSchemaDependencyException;
use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\Exception\Arrays\InvalidUnevaluatedItemsException;
use PHPModelGenerator\Exception\Arrays\MinItemsException;
use PHPModelGenerator\Exception\Arrays\UnevaluatedItemsException;
use PHPModelGenerator\Exception\Generic\DeniedPropertyException;
use PHPModelGenerator\Exception\Generic\EnumException;
use PHPModelGenerator\Exception\Generic\InvalidConstException;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\JSONModelValidationException;
use PHPModelGenerator\Exception\Number\MinimumException;
use PHPModelGenerator\Exception\Object\AdditionalPropertiesException;
use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Exception\Object\RequiredValueException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\BuilderClassPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The name of a property is part of the generated code: it is the key of the provided data, embedded in a string
 * literal. A name which contains a quote or a backslash must neither break the generated code nor change the key
 * which is validated.
 */
class QuotedPropertyNameTest extends AbstractPHPModelGeneratorTestCase
{
    private const array VALID_INPUT = [
        "it's" => 'a',
        'say "hi"' => 2,
        'back\\slash' => ['x'],
        'trailing\\' => ["nested's" => 'n'],
        "mixed\\'\"" => 5,
        "enum's" => 'a',
        'const "x"' => 'x',
        "not's" => 5,
        'multi \\ type' => 3,
    ];

    #[DataProvider('validationMethodDataProvider')]
    public function testPropertiesWithQuotesInTheNameAreProcessedAsProvided(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateModelWithQuotedPropertyNames($configuration);

        $object = new $className(self::VALID_INPUT);

        $this->assertSame(self::VALID_INPUT, $object->meta()->rawInput());
        $this->assertSame(self::VALID_INPUT, $object->toArray());
        $this->assertSame('a', $object->getItS());
        $this->assertSame(2, $object->getSayHi());
        $this->assertSame(['x'], $object->getBackSlash());
        $this->assertSame('x', $object->getConstX());

        // the default value of a property is applied for the key of the property
        $minimalObject = (new $className(["it's" => 'a']))->toArray();
        $this->assertSame('a', $minimalObject["it's"]);
        $this->assertSame(3, $minimalObject['say "hi"']);
    }

    /**
     * @param array<string, mixed> $changedInput
     */
    #[DataProvider('invalidInputDataProvider')]
    public function testInvalidValuesOfPropertiesWithQuotesInTheNameAreRejected(
        array $changedInput,
        string $exceptionClass,
        string $expectedMessage,
    ): void {
        $configuration = (new GeneratorConfiguration())->setCollectErrors(false);
        $className = $this->generateModelWithQuotedPropertyNames($configuration);

        $this->assertInstantiationFailsWithMessage(
            $className,
            $changedInput === [] ? [] : array_merge(self::VALID_INPUT, $changedInput),
            $exceptionClass,
            $expectedMessage,
        );
    }

    public static function invalidInputDataProvider(): array
    {
        return [
            'missing required property' => [
                [],
                RequiredValueException::class,
                "Missing required value for 'it's'",
            ],
            'minimum' => [
                ['say "hi"' => 0],
                MinimumException::class,
                "Value for 'say \"hi\"' must not be smaller than 1",
            ],
            'item of an array' => [
                ['back\\slash' => [1]],
                InvalidItemException::class,
                <<<'MSG'
                Invalid items in array 'back\slash':
                  - invalid item #0
                    * Invalid type for 'back\slash': requires 'string', got 'integer'
                MSG,
            ],
            'minimum amount of items' => [
                ['back\\slash' => []],
                MinItemsException::class,
                "Array 'back\\slash' must not contain less than 1 items",
            ],
            'required property of a nested object' => [
                ['trailing\\' => []],
                NestedObjectException::class,
                <<<'MSG'
                Invalid nested object for property 'trailing\':
                  - Missing required value for 'nested's'
                MSG,
            ],
            'anyOf' => [
                ["mixed\\'\"" => true],
                AnyOfException::class,
                <<<'MSG'
                Invalid value for 'mixed\'"' declined by composition constraint
                  Requires to match at least one composition element
                  - Composition element #1: Failed
                    * Invalid type for 'mixed\'"': requires 'string', got 'boolean'
                  - Composition element #2: Failed
                    * Invalid type for 'mixed\'"': requires 'int', got 'boolean'
                MSG,
            ],
            'enum' => [
                ["enum's" => 'c'],
                EnumException::class,
                // the implicit null of the optional property is part of the allowed values
                'Value for \'enum\'s\' must be one of ["a","b",null], got "c"',
            ],
            'const' => [
                ['const "x"' => 'wrong'],
                InvalidConstException::class,
                'Value for \'const "x"\' must be "x", got "wrong"',
            ],
            'not' => [
                ["not's" => 'a string'],
                NotException::class,
                <<<'MSG'
                Invalid value for 'not's' declined by composition constraint
                  Requires to match none composition element but matched 1 element
                  - Composition element #1: Valid
                MSG,
            ],
            'multi type' => [
                ['multi \\ type' => true],
                InvalidTypeException::class,
                "Invalid type for 'multi \\ type': requires ['string', 'int'], got 'boolean'",
            ],
            'additional property' => [
                ['other' => 1],
                AdditionalPropertiesException::class,
                "Provided JSON for '%class%' contains not allowed additional properties ['other']",
            ],
        ];
    }

    /**
     * The key of an update (a setter, populate, the builder) is the name of the property.
     */
    public function testUpdatesOfPropertiesWithQuotesInTheNameUseTheKeyAsProvided(): void
    {
        $configuration = (new GeneratorConfiguration())->setCollectErrors(false);
        $className = $this->generateModelWithQuotedPropertyNames($configuration);

        $object = new $className(self::VALID_INPUT);

        $this->assertSame('b', $object->setItS('b')->getItS());
        $this->assertSame('b', $object->meta()->rawInput()["it's"]);

        $object->populate(["it's" => 'c', 'const "x"' => 'x']);
        $this->assertSame('c', $object->getItS());

        $this->assertUpdateFailsWithMessage(
            fn() => $object->populate(['const "x"' => 'wrong']),
            InvalidConstException::class,
            'Value for \'const "x"\' must be "x", got "wrong"',
        );
        // the rejected update left the stored state untouched
        $this->assertSame('x', $object->getConstX());

        $this->assertUpdateFailsWithMessage(
            fn() => $object->setSayHi(0),
            MinimumException::class,
            "Value for 'say \"hi\"' must not be smaller than 1",
        );
        $this->assertSame(2, $object->getSayHi());

        $builder = new ($className . 'Builder')();
        $builder->setItS('x')->setSayHi(4);
        $this->assertSame('x', $builder->getItS());
        $this->assertSame(4, $builder->getSayHi());
        $this->assertSame('x', $builder->meta()->rawInput()["it's"]);

        $this->assertInstanceOf($className, $builder->setItS('x')->setBackSlash(['y'])->validate());
    }

    #[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
    #[DataProvider('validationMethodDataProvider')]
    public function testDependenciesOfPropertiesWithQuotesInTheNameAreValidated(
        GeneratorConfiguration $configuration,
    ): void {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            'QuotedPropertyNameDependencies.json',
            (clone $configuration)->setImmutable(false),
        );

        $input = ["owner's" => 'a', 'dependant "x"' => 'b'];
        $this->assertSame($input, (new $className($input))->meta()->rawInput());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ["owner's" => 'a'],
            $this->getExpectedExceptionClass($configuration, InvalidPropertyDependencyException::class),
            <<<'MSG'
            Missing required attributes which are dependants of 'owner's':
              - 'dependant "x"'
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['other\\' => 'a'],
            $this->getExpectedExceptionClass($configuration, InvalidSchemaDependencyException::class),
            <<<'MSG'
            Invalid schema which is dependant on 'other\':
              - Missing required value for 'owner's'
            MSG,
        );

        // the name of the property is part of the name of the class which validates the dependency schema
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['dash-ed name' => 'a'],
            $this->getExpectedExceptionClass($configuration, InvalidSchemaDependencyException::class),
            <<<'MSG'
            Invalid schema which is dependant on 'dash-ed name':
              - Missing required value for 'owner's'
            MSG,
        );

        // an update of a property validates the schema dependencies of the properties which aren't updated
        $object = new $className(['other\\' => 'x', "owner's" => 'abc', 'dependant "x"' => 'b']);
        $this->assertUpdateFailsWithMessage(
            fn() => $object->populate(["owner's" => 'abcdef']),
            $this->getExpectedExceptionClass($configuration, InvalidSchemaDependencyException::class),
            <<<'MSG'
            Invalid schema which is dependant on 'other\':
              - Value for 'owner's' must not be longer than 3
            MSG,
        );
        $this->assertSame('abc', $object->getOwnerS());
    }

    /**
     * The name of a property is part of the checks of a composition of the object, of the properties a composition
     * branch depends on (the cached result of a branch is only valid as long as none of them is updated) and of the
     * values a filter inside of a branch publishes.
     */
    public function testObjectCompositionsAndFilteredBranchPropertiesWithQuotesInTheNameAreValidatedOnUpdates(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $configuration = (new GeneratorConfiguration())
            ->setCollectErrors(false)
            ->setImmutable(false)
            ->setSerialization(true);
        $className = $this->generateClassFromFile('QuotedPropertyNameObjectCompositions.json', $configuration);

        $object = new $className(["owner's" => 'a', "date's" => '2020-01-01']);
        $this->assertInstanceOf(DateTime::class, $object->getDateS());
        // the optional property which isn't provided is serialized as null (implicit null is enabled by the harness)
        $this->assertSame(
            ["owner's" => 'a', "date's" => '2020-01-01T00:00:00+0000', "alt's" => null],
            $object->toArray(),
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ["date's" => '2020-01-01'],
            AnyOfException::class,
            <<<'MSG'
            Invalid value for '%class%' declined by composition constraint
              Requires to match at least one composition element
            MSG,
        );

        $object->populate(["owner's" => 'b']);
        $this->assertSame('b', $object->getOwnerS());

        // the branch depends on the updated property: it is validated again, the update is rejected as a whole
        $this->assertUpdateFailsWithMessage(
            fn() => $object->populate(["date's" => 5]),
            AllOfException::class,
            <<<'MSG'
            Invalid value for '%class%' declined by composition constraint
              Requires to match all composition elements but matched 0 elements
            MSG,
        );
        $this->assertSame(
            ["owner's" => 'b', "date's" => '2020-01-01T00:00:00+0000', "alt's" => null],
            $object->toArray(),
        );
    }

    /**
     * The value which the filter of the active oneOf branch produces is published to the property of the object
     * by the name of the property.
     */
    public function testFilteredValueOfABranchWithQuotesInThePropertyNameIsPublishedToTheProperty(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $configuration = (new GeneratorConfiguration())
            ->setCollectErrors(false)
            ->setImmutable(false)
            ->setSerialization(true);
        $className = $this->generateClassFromFile('QuotedPropertyNameBranchFilteredValues.json', $configuration);

        $object = new $className(["pick's" => '2020-01-01']);
        $this->assertInstanceOf(DateTime::class, $object->getPickS());
        $this->assertSame(["pick's" => '2020-01-01T00:00:00+0000', "other's" => null], $object->toArray());

        $object->populate(["pick's" => '2021-02-02']);
        $this->assertInstanceOf(DateTime::class, $object->getPickS());
        $this->assertSame('2021-02-02T00:00:00+0000', $object->toArray()["pick's"]);

        $object->setPickS('2022-03-03');
        $this->assertInstanceOf(DateTime::class, $object->getPickS());
        $this->assertSame('2022-03-03T00:00:00+0000', $object->toArray()["pick's"]);

        // the update activates the second branch as well: it is rejected and the stored state stays untouched
        $this->assertUpdateFailsWithMessage(
            fn() => $object->populate(["other's" => 5]),
            OneOfException::class,
            <<<'MSG'
            Invalid value for '%class%' declined by composition constraint
              Requires to match one composition element but matched 2 elements
            MSG,
        );
        $this->assertSame(["pick's" => '2022-03-03T00:00:00+0000', "other's" => null], $object->toArray());
    }

    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testUnevaluatedItemsOfArraysWithQuotesInTheNameAreEvaluatedAndSerialized(): void
    {
        $configuration = (new GeneratorConfiguration())
            ->setCollectErrors(false)
            ->setImmutable(false)
            ->setSerialization(true);
        $className = $this->generateClassFromFile('QuotedPropertyNameUnevaluatedItems.json', $configuration);

        // the contains of the first array, the filter of the unevaluated items of the second array and the filters of
        // the items and the unevaluated items of the third array are evaluated by the name of the arrays
        $object = new $className([
            "tags's" => ['a', 'b'],
            "dates's" => ['x', '2020-01-01', '2021-02-02'],
            "filtered's" => ['2022-03-03'],
        ]);
        $this->assertInstanceOf(DateTime::class, $object->getDatesS()[1]);
        $this->assertInstanceOf(DateTime::class, $object->getFilteredS()[0]);
        $this->assertSame(
            [
                "tags's" => ['a', 'b'],
                "dates's" => ['x', '2020-01-01T00:00:00+0000', '2021-02-02T00:00:00+0000'],
                "filtered's" => ['2022-03-03T00:00:00+0000'],
            ],
            $object->toArray(),
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ["tags's" => ['a', 1]],
            UnevaluatedItemsException::class,
            "Provided JSON for 'tags's' contains not allowed unevaluated items [#1]",
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ["dates's" => ['x', 5]],
            InvalidUnevaluatedItemsException::class,
            <<<'MSG'
            Invalid unevaluated items in array 'dates's':
              - invalid unevaluated item #1
                * Invalid type for 'unevaluated item': requires ['DateTime', 'string'], got 'integer'
            MSG,
        );

        // the items credited by a previous validation don't count for the next one
        $object->setTagsS(['a']);
        $this->assertUpdateFailsWithMessage(
            fn() => $object->setTagsS(['b', 1]),
            UnevaluatedItemsException::class,
            "Provided JSON for 'tags's' contains not allowed unevaluated items [#1]",
        );
    }

    /**
     * The evaluated items of a composition of an array are stored in a slot which is named after the array: the slot
     * of a composition which is nested directly inside of a branch is read by the name of the slot, and so are the
     * slots of a conditional composition.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testCompositionsOfArraysWithQuotesInTheNameCreditTheEvaluatedItemsOfTheBranches(): void
    {
        $configuration = (new GeneratorConfiguration())->setCollectErrors(false)->setImmutable(false);
        $className = $this->generateClassFromFile(
            'QuotedPropertyNameUnevaluatedItemsCompositions.json',
            $configuration,
        );

        // the items evaluated by the contains of the nested oneOf and by the contains of the second branch of the
        // anyOf are credited to the array
        $object = new $className(["slots's" => ['a'], "cond's" => []]);
        $this->assertSame(['a'], $object->getSlotsS());
        $this->assertSame(['a', 1], (new $className(["slots's" => ['a', 1]]))->getSlotsS());
        $this->assertSame(['x', 'y'], (new $className(["cond's" => ['x', 'y']]))->getCondS());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ["slots's" => ['a', 'x']],
            UnevaluatedItemsException::class,
            "Provided JSON for 'slots's' contains not allowed unevaluated items [#1]",
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ["slots's" => ['a', 'b']],
            AnyOfException::class,
            <<<'MSG'
            Invalid value for 'slots's' declined by composition constraint
              Requires to match at least one composition element
              - Composition element #1: Failed
                * Invalid value for 'slots's' declined by composition constraint
                  Requires to match one composition element but matched 2 elements
                  - Composition element #1: Valid
                  - Composition element #2: Valid
              - Composition element #2: Failed
                * No item in array 'slots's' matches the 'contains' constraint
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ["cond's" => ['x', 1]],
            UnevaluatedItemsException::class,
            "Provided JSON for 'cond's' contains not allowed unevaluated items [#1]",
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ["cond's" => [1, 2]],
            ConditionalException::class,
            <<<'MSG'
            Invalid value for 'cond's' declined by conditional composition constraint
              - Condition: Valid
              - Conditional branch failed:
                * No item in array 'cond's' matches the 'contains' constraint
            MSG,
        );

        // the items credited by a previous validation don't count for the next one
        $object->setSlotsS(['a']);
        $this->assertUpdateFailsWithMessage(
            fn() => $object->setSlotsS(['b', 'x']),
            UnevaluatedItemsException::class,
            "Provided JSON for 'slots's' contains not allowed unevaluated items [#1]",
        );
        $this->assertSame(['a'], $object->getSlotsS());
    }

    /**
     * An optional property which isn't provided is skipped if the implicit null isn't allowed: the check looks for
     * the name of the property as the key of the provided data.
     */
    public function testOptionalPropertiesWithQuotesInTheNameAreSkippedIfNotProvidedWithoutImplicitNull(): void
    {
        $className = $this->generateModelWithQuotedPropertyNames(
            (new GeneratorConfiguration())->setCollectErrors(false),
            implicitNull: false,
        );

        // the enum of an optional property doesn't contain null: the missing property isn't validated
        $object = new $className(["it's" => 'a']);
        $this->assertSame(['it\'s' => 'a', 'say "hi"' => 3], $object->toArray());
        $this->assertSame('a', $object->setItS('a')->getItS());
    }

    /**
     * A name with a double quote is a string like any other for a single quoted string of the generated code. The
     * key is looked up as provided: a value which violates the keyword of the property is rejected (an escaped double
     * quote would keep its backslash in a single quoted string, the key would never be found and the keyword would
     * not be validated at all).
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testDoubleQuoteInThePropertyNameDoesNotChangeTheValidatedKey(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('DoubleQuotedPropertyNames.json', $configuration);

        $input = ['const "x"' => 'x', 'not "y"' => 5];
        $this->assertSame($input, (new $className($input))->meta()->rawInput());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['const "x"' => 'wrong'],
            $this->getExpectedExceptionClass($configuration, InvalidConstException::class),
            'Value for \'const "x"\' must be "x", got "wrong"',
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['not "y"' => 'a string'],
            $this->getExpectedExceptionClass($configuration, NotException::class),
            <<<'MSG'
            Invalid value for 'not "y"' declined by composition constraint
              Requires to match none composition element but matched 1 element
              - Composition element #1: Valid
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['denied "z"' => 1],
            $this->getExpectedExceptionClass($configuration, DeniedPropertyException::class),
            "Value for 'denied \"z\"' is not allowed",
        );
    }

    /**
     * Keywords which check the presence of the key of their property (a denied property, the const of an optional
     * property without implicit null, the branches of a composition which accept or reject a property by its
     * presence) look up the name of the property as the key of the provided data.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testSingleQuoteInThePropertyNameOfKeywordsWhichCheckThePresenceOfTheKey(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile(
            'SingleQuotedKeywordPropertyNames.json',
            $configuration,
            implicitNull: false,
        );

        $input = ["const's" => 'x', "oneOf false's" => 'a'];
        $this->assertSame($input, (new $className($input))->meta()->rawInput());
        $this->assertSame([], (new $className([]))->meta()->rawInput());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ["denied's" => 1],
            $this->getExpectedExceptionClass($configuration, DeniedPropertyException::class),
            "Value for 'denied's' is not allowed",
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ["const's" => 'wrong'],
            $this->getExpectedExceptionClass($configuration, InvalidConstException::class),
            'Value for \'const\'s\' must be "x", got "wrong"',
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ["not's true" => 1],
            $this->getExpectedExceptionClass($configuration, NotException::class),
            <<<'MSG'
            Invalid value for 'not's true' declined by composition constraint
              Requires to match none composition element but matched 1 element
              - Composition element #1: Valid
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ["oneOf false's" => 5],
            $this->getExpectedExceptionClass($configuration, OneOfException::class),
            <<<'MSG'
            Invalid value for 'oneOf false's' declined by composition constraint
              Requires to match one composition element but matched 0 elements
              - Composition element #1: Failed
                * Value for 'oneOf false's' is not allowed
              - Composition element #2: Failed
                * Invalid type for 'oneOf false's': requires 'string', got 'integer'
            MSG,
        );
    }

    private function generateModelWithQuotedPropertyNames(
        GeneratorConfiguration $configuration,
        bool $implicitNull = true,
    ): string {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator
                ->addPostProcessor(new PopulatePostProcessor())
                ->addPostProcessor(new BuilderClassPostProcessor());
        };

        return $this->generateClassFromFile(
            'QuotedPropertyNames.json',
            (clone $configuration)->setImmutable(false)->setSerialization(true),
            implicitNull: $implicitNull,
        );
    }

    /**
     * @param class-string<JSONModelValidationException> $exceptionClass
     */
    private function assertUpdateFailsWithMessage(
        callable $update,
        string $exceptionClass,
        string $expectedMessage,
    ): void {
        try {
            $update();
        } catch (JSONModelValidationException $exception) {
            $this->assertInstanceOf($exceptionClass, $exception);
            $this->assertMessageMatchesTemplate($expectedMessage, $exception->getMessage());

            return;
        }

        $this->fail("Expected $exceptionClass");
    }
}
