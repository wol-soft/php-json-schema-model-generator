<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Exception;

use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\Exception\Arrays\InvalidTupleException;
use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\ComposedValue\InvalidComposedValueException;
use PHPModelGenerator\Exception\Dependency\InvalidSchemaDependencyException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\JSONModelValidationException;
use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\AdditionalPropertiesAccessorPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\UnevaluatedPropertiesAccessorPostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * The instance pointer of an exception (ValidationException::getInstancePointer()) locates the value which was
 * rejected inside the provided data. The tests render the complete tree of the exceptions of a failed validation
 * (the class name of the exception and its pointer, one line per exception, nested exceptions indented) and compare
 * it with the expected tree. The error registry of the collected errors is transparent.
 *
 * The pointers of the nested exceptions are resolved by the exception classes of the production library. The
 * generated code marks the exceptions of the validators of a generated class itself (the name of the class is a label
 * for the message text and no location, see RenderHelper::validationError()).
 */
class InstancePointerTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * The keys of the invalid tuples are 1-based (the message reports the first tuple item as "invalid tuple #1"),
     * a pointer addresses the 0-based index of the array.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testTuplePositionsAreReportedAsZeroBasedPointers(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('Tuples.json', $configuration);

        $exception = $this->getValidationException($className, ['pair' => [['a', 'x'], 'b']]);
        $tupleException = $exception instanceof ErrorRegistryException ? $exception->getErrors()[0] : $exception;

        $this->assertInstanceOf(InvalidTupleException::class, $tupleException);
        $this->assertSame([1], array_keys($tupleException->getInvalidTuples()));
        $this->assertStringContainsString('invalid tuple #1', $tupleException->getMessage());

        $this->assertExceptionTree(
            <<<'TREE'
            InvalidTupleException [/pair]
              InvalidTupleException [/pair/0]
                InvalidTypeException [/pair/0/1]
            TREE,
            $exception,
        );

        $this->assertExceptionTree(
            <<<'TREE'
            InvalidTupleException [/pair]
              InvalidTypeException [/pair/1]
            TREE,
            $this->getValidationException($className, ['pair' => [['a', 1], 5]]),
        );
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testAdditionalTupleItemsAreReportedAtTheirIndexBelowTheArray(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('Tuples.json', $configuration);

        $this->assertExceptionTree(
            <<<'TREE'
            InvalidAdditionalTupleItemsException [/extra]
              InvalidTypeException [/extra/3]
            TREE,
            $this->getValidationException($className, ['extra' => ['a', 1, 2, 'x']]),
        );
    }

    /**
     * The validators of the class of an object property run inside the generated class and receive the name of the
     * class as property name. The name is a label for the message text and no segment of the pointer: the exceptions
     * are located at the object property.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testValidatorsOfTheClassOfAnObjectPropertyAreLocatedAtTheProperty(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('ObjectLevelValidators.json', $configuration);

        $this->assertExceptionTree(
            <<<'TREE'
            NestedObjectException [/min]
              MinPropertiesException [/min]
            TREE,
            $this->getValidationException($className, ['min' => ['a' => 'x']]),
        );

        $this->assertExceptionTree(
            <<<'TREE'
            NestedObjectException [/max]
              MaxPropertiesException [/max]
            TREE,
            $this->getValidationException($className, ['max' => ['a' => 'x', 'z' => 1]]),
        );

        $this->assertExceptionTree(
            <<<'TREE'
            NestedObjectException [/closed]
              AdditionalPropertiesException [/closed]
            TREE,
            $this->getValidationException($className, ['closed' => ['a' => 'x', 'z' => 1]]),
        );

        $this->assertExceptionTree(
            <<<'TREE'
            NestedObjectException [/notObject]
              NotException [/notObject]
            TREE,
            $this->getValidationException($className, ['notObject' => ['a' => 'x']]),
        );

        // the errors of the branch are located inside of the object, below the composition
        $this->assertExceptionTree(
            <<<'TREE'
            NestedObjectException [/allOfObject]
              AllOfException [/allOfObject]
                InvalidTypeException [/allOfObject/b]
            TREE,
            $this->getValidationException($className, ['allOfObject' => ['b' => 's']]),
        );

        $this->assertExceptionTree(
            $configuration->collectErrors()
                ? <<<'TREE'
                NestedObjectException [/conditional]
                  ConditionalException [/conditional]
                    RequiredValueException [/conditional/b]
                    InvalidTypeException [/conditional/b]
                TREE
                : <<<'TREE'
                NestedObjectException [/conditional]
                  ConditionalException [/conditional]
                    RequiredValueException [/conditional/b]
                TREE,
            $this->getValidationException($className, ['conditional' => ['a' => 'x']]),
        );
    }

    /**
     * The validators which are located at the object by their own (the pointers are resolved by the exceptions
     * themselves) keep their pointers.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testValidatorsOfTheValuesOfAnObjectPropertyAreLocatedAtTheValue(
        GeneratorConfiguration $configuration,
    ): void {
        $className = $this->generateClassFromFile('ObjectLevelValidators.json', $configuration);

        $this->assertExceptionTree(
            <<<'TREE'
            NestedObjectException [/additionalSchema]
              InvalidAdditionalPropertiesException [/additionalSchema]
                InvalidTypeException [/additionalSchema/z]
            TREE,
            $this->getValidationException($className, ['additionalSchema' => ['z' => 'x']]),
        );

        $this->assertExceptionTree(
            <<<'TREE'
            NestedObjectException [/names]
              InvalidPropertyNamesException [/names]
                MaxLengthException [/names/abc]
            TREE,
            $this->getValidationException($className, ['names' => ['abc' => 1]]),
        );

        $this->assertExceptionTree(
            <<<'TREE'
            NestedObjectException [/pattern]
              InvalidPatternPropertiesException [/pattern]
                InvalidTypeException [/pattern/ab]
            TREE,
            $this->getValidationException($className, ['pattern' => ['ab' => 'x']]),
        );
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testValidatorsOfTheRootClassAreLocatedAtTheRootOfTheInstance(
        GeneratorConfiguration $configuration,
    ): void {
        $this->assertExceptionTree(
            <<<'TREE'
            MinPropertiesException []
            TREE,
            $this->getValidationException(
                $this->generateClassFromFile('RootMinProperties.json', $configuration),
                ['a' => 'x'],
            ),
        );

        $this->assertExceptionTree(
            <<<'TREE'
            AllOfException []
              InvalidTypeException [/b]
            TREE,
            $this->getValidationException(
                $this->generateClassFromFile('RootAllOf.json', $configuration),
                ['b' => 's'],
            ),
        );
    }

    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('validationMethodDataProvider')]
    public function testUnevaluatedPropertiesFalseOfTheClassOfAnObjectPropertyIsLocatedAtTheProperty(
        GeneratorConfiguration $configuration,
    ): void {
        $this->assertExceptionTree(
            <<<'TREE'
            NestedObjectException [/strict]
              UnevaluatedPropertiesException [/strict]
            TREE,
            $this->getValidationException(
                $this->generateClassFromFile('UnevaluatedPropertiesFalse.json', $configuration),
                ['strict' => ['a' => 'x', 'z' => 1]],
            ),
        );
    }

    /**
     * Removing a property through the accessor of the additional properties validates the minProperties of the
     * object again with the state after the removal. The object is the root of the instance.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testMinPropertiesOfTheRemovalOfAnAdditionalPropertyIsLocatedAtTheRootOfTheInstance(
        GeneratorConfiguration $configuration,
    ): void {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new AdditionalPropertiesAccessorPostProcessor());
        };

        $className = $this->generateClassFromFile(
            'MinPropertiesWithAdditionalProperties.json',
            (clone $configuration)->setImmutable(false),
        );
        $object = new $className(['name' => 'x', 'a' => 1]);

        $this->assertExceptionTree(
            <<<'TREE'
            MinPropertiesException []
            TREE,
            $this->catchValidationException(static fn() => $object->additionalProperties()->remove('a')),
        );
    }

    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('validationMethodDataProvider')]
    public function testMinPropertiesOfTheRemovalOfAnUnevaluatedPropertyIsLocatedAtTheRootOfTheInstance(
        GeneratorConfiguration $configuration,
    ): void {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new UnevaluatedPropertiesAccessorPostProcessor());
        };

        $className = $this->generateClassFromFile(
            'MinPropertiesWithUnevaluatedProperties.json',
            (clone $configuration)->setImmutable(false),
        );
        $object = new $className(['name' => 'x', 'a' => 1]);

        $this->assertExceptionTree(
            <<<'TREE'
            MinPropertiesException []
            TREE,
            $this->catchValidationException(static fn() => $object->unevaluatedProperties()->remove('a')),
        );
    }

    /**
     * The validators of the properties of the object which trigger a dependency are located at the property (the
     * dependency is declared for the property), the errors of the dependency schema inside of the object. The class
     * name marker must not be applied to them.
     */
    #[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
    #[DataProvider('validationMethodDataProvider')]
    public function testDependenciesAreLocatedInsideOfTheObject(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('Dependencies.json', $configuration);

        $this->assertExceptionTree(
            <<<'TREE'
            NestedObjectException [/schemaDependency]
              InvalidSchemaDependencyException [/schemaDependency/a]
                RequiredValueException [/schemaDependency/b]
            TREE,
            $this->getValidationException($className, ['schemaDependency' => ['a' => 1]]),
        );

        $this->assertExceptionTree(
            <<<'TREE'
            NestedObjectException [/propertyDependency]
              InvalidPropertyDependencyException [/propertyDependency/a]
            TREE,
            $this->getValidationException($className, ['propertyDependency' => ['a' => 1]]),
        );
    }

    /**
     * The validators of a property (here a string and an array) receive the real name of the property: the
     * exceptions of the composition and of its branches are located at the property, the items of an array below it.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testCompositionsOfAPropertyAreLocatedAtTheProperty(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('PropertyLevelCompositions.json', $configuration);

        $this->assertExceptionTree(
            <<<'TREE'
            AnyOfException [/name]
              MinLengthException [/name]
              PatternException [/name]
            TREE,
            $this->getValidationException($className, ['name' => 'ab']),
        );

        $this->assertExceptionTree(
            <<<'TREE'
            AnyOfException [/tags]
              InvalidItemException [/tags]
                InvalidTypeException [/tags/0]
                InvalidTypeException [/tags/1]
              MinItemsException [/tags]
            TREE,
            $this->getValidationException($className, ['tags' => ['a', 'b']]),
        );
    }

    private function getValidationException(string $className, array $input): JSONModelValidationException
    {
        return $this->catchValidationException(static fn() => new $className($input));
    }

    private function catchValidationException(callable $action): JSONModelValidationException
    {
        try {
            $action();
        } catch (JSONModelValidationException $exception) {
            return $exception;
        }

        $this->fail('Expected a validation exception');
    }

    private function assertExceptionTree(string $expectedTree, JSONModelValidationException $exception): void
    {
        $this->assertSame($expectedTree, implode("\n", $this->renderExceptionTree($exception, 0)));
    }

    /**
     * @return string[] One line per exception
     */
    private function renderExceptionTree(Throwable $exception, int $depth): array
    {
        if ($exception instanceof ErrorRegistryException) {
            return $this->renderExceptionTrees($exception->getErrors(), $depth);
        }

        $lines = [
            str_repeat('  ', $depth)
                . substr(strrchr($exception::class, '\\'), 1)
                . ' [' . ($exception instanceof ValidationException ? $exception->getInstancePointer()->pointer : '-')
                . ']',
        ];

        return array_merge($lines, $this->renderExceptionTrees($this->getNestedExceptions($exception), $depth + 1));
    }

    /**
     * @param Throwable[] $exceptions
     *
     * @return string[]
     */
    private function renderExceptionTrees(array $exceptions, int $depth): array
    {
        return array_merge(...array_map(
            fn(Throwable $exception): array => $this->renderExceptionTree($exception, $depth),
            $exceptions,
        ));
    }

    /**
     * @return Throwable[]
     */
    private function getNestedExceptions(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof InvalidTupleException => array_merge(...array_values($exception->getInvalidTuples())),
            $exception instanceof InvalidItemException => array_merge(...array_values($exception->getInvalidItems())),
            // the exceptions of additional, pattern and unevaluated properties and of the property names
            method_exists($exception, 'getNestedExceptions')
                => array_merge(...array_values($exception->getNestedExceptions())),
            $exception instanceof NestedObjectException => [$exception->getNestedException()],
            $exception instanceof InvalidSchemaDependencyException => [$exception->getDependencyException()],
            $exception instanceof InvalidComposedValueException => array_merge(...array_map(
                static fn(ErrorRegistryException $registry): array => $registry->getErrors(),
                $exception->getCompositionErrorCollection(),
            )),
            $exception instanceof ConditionalException => array_values(array_filter([
                $exception->getIfException(),
                $exception->getThenException(),
                $exception->getElseException(),
            ])),
            default => [],
        };
    }
}
