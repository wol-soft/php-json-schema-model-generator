<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\Dependency\InvalidPropertyDependencyException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

#[ApplicableDrafts]
class PropertyDependencyTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * A property dependency constrains the objects which contain the property, whether or not
     * the property is declared in `properties`. The dependencies are only wired from the declared
     * (and the required) properties, so the dependency below is dropped silently and the object
     * is accepted although `billing_address` is missing.
     *
     * The test turns green by itself as soon as the dependency is enforced; the assertion of the
     * complete exception message belongs here then.
     */
    public function testPropertyDependencyOfAnUndeclaredPropertyIsEnforced(): void
    {
        $className = $this->generateClassFromFile('DependencyOnUndeclaredProperty.json');

        try {
            new $className(['credit_card' => 12345]);
        } catch (InvalidPropertyDependencyException) {
            return;
        }

        $this->markTestIncomplete(
            'Known gap: a dependency of a property which is neither declared nor required is dropped silently.',
        );
    }

    #[DataProvider('validPropertyDependencyDataProvider')]
    public function testValidPropertyDependency(array $propertyValue): void
    {
        $className = $this->generateClassFromFile('PropertyDependency.json');

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['credit_card'] ?? null, $object->getCreditCard());
        $this->assertSame($propertyValue['billing_address'] ?? null, $object->getBillingAddress());
    }

    public static function validPropertyDependencyDataProvider(): array
    {
        return [
            'No properties provided' => [[]],
            'Only dependant property provided' => [['billing_address' => '555 Debitors Lane']],
            'All properties provided' => [['credit_card' => 12345, 'billing_address' => '555 Debitors Lane']],
        ];
    }

    #[DataProvider('validMultiplePropertyDependenciesDataProvider')]
    public function testValidMultiplePropertyDependencies(array $propertyValue): void
    {
        $className = $this->generateClassFromFile('MultiplePropertyDependencies.json');

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['credit_card'] ?? null, $object->getCreditCard());
        $this->assertSame($propertyValue['billing_address'] ?? null, $object->getBillingAddress());
        $this->assertSame($propertyValue['name'] ?? null, $object->getName());
    }

    public static function validMultiplePropertyDependenciesDataProvider(): array
    {
        return [
            'No properties provided' => [[]],
            'Only dependant property provided 1' => [['billing_address' => '555 Debitors Lane']],
            'Only dependant property provided 2' => [['name' => 'John']],
            'Only dependant property provided 3' => [['billing_address' => '555 Debitors Lane', 'name' => 'John']],
            'All properties provided' => [
                ['credit_card' => 12345, 'billing_address' => '555 Debitors Lane', 'name' => 'John'],
            ],
        ];
    }

    #[DataProvider('validBidirectionalPropertyDependencyDataProvider')]
    public function testValidBidirectionalPropertyDependency(array $propertyValue): void
    {
        $className = $this->generateClassFromFile('BidirectionalPropertyDependency.json');

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['credit_card'] ?? null, $object->getCreditCard());
        $this->assertSame($propertyValue['billing_address'] ?? null, $object->getBillingAddress());
    }

    public static function validBidirectionalPropertyDependencyDataProvider(): array
    {
        return [
            'No properties provided' => [[]],
            'All properties provided' => [['credit_card' => 12345, 'billing_address' => '555 Debitors Lane']],
        ];
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testInvalidPropertyDependencyThrowsAnException(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('PropertyDependency.json', $configuration);

        try {
            new $className(['credit_card' => 12345]);
            $this->fail('Expected exception for missing property dependency');
        } catch (ErrorRegistryException | InvalidPropertyDependencyException $exception) {
            $this->assertSame(
                <<<ERROR
                Missing required attributes which are dependants of 'credit_card':
                  - 'billing_address'
                ERROR,
                $exception->getMessage(),
            );

            // collectErrors(true) wraps the dependency exception in an ErrorRegistryException.
            $innerException = $exception instanceof ErrorRegistryException
                ? $exception->getErrors()[0]
                : $exception;

            $this->assertInstanceOf(InvalidPropertyDependencyException::class, $innerException);
            $this->assertSame('/dependencies/credit_card', $innerException->getJsonPointer()->pointer);
        }
    }

    #[DataProvider('invalidMultiplePropertyDependenciesDataProvider')]
    public function testInvalidMultiplePropertyDependenciesThrowsAnException(
        GeneratorConfiguration $configuration,
        array $propertyValue,
        string $message,
    ): void {
        $this->expectValidationError($configuration, $message);

        $className = $this->generateClassFromFile('MultiplePropertyDependencies.json', $configuration);

        new $className($propertyValue);
    }

    public static function invalidMultiplePropertyDependenciesDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'no required attribute provided' => [
                    ['credit_card' => 12345],
                    <<<ERROR
                    Missing required attributes which are dependants of 'credit_card':
                      - 'billing_address'
                      - 'name'
                    ERROR,
                ],
                'only one required attribute provided 1' => [
                    ['credit_card' => 12345, 'billing_address' => '555 Debitors Lane'],
                    <<<ERROR
                    Missing required attributes which are dependants of 'credit_card':
                      - 'name'
                    ERROR,
                ],
                'only one required attribute provided 2' => [
                    ['credit_card' => 12345, 'name' => 'John'],
                    <<<ERROR
                    Missing required attributes which are dependants of 'credit_card':
                      - 'billing_address'
                    ERROR,
                ],
            ],
        );
    }

    #[DataProvider('invalidBidirectionalPropertyDependencyDataProvider')]
    public function testInvalidBidirectionalPropertyDependencyThrowsAnException(
        GeneratorConfiguration $configuration,
        array $propertyValue,
        string $message,
    ): void {
        $this->expectValidationError($configuration, $message);

        $className = $this->generateClassFromFile('BidirectionalPropertyDependency.json', $configuration);

        new $className($propertyValue);
    }

    public static function invalidBidirectionalPropertyDependencyDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                [
                    ['credit_card' => 12345],
                    <<<ERROR
                    Missing required attributes which are dependants of 'credit_card':
                      - 'billing_address'
                    ERROR,
                ],
                [
                    ['billing_address' => '555 Debitors Lane'],
                    <<<ERROR
                    Missing required attributes which are dependants of 'billing_address':
                      - 'credit_card'
                    ERROR,
                ],
            ],
        );
    }

    /**
     * The dependencies of a property are validated against the whole model data, not only against the values which
     * are part of the update: the setter and populate() of a property with a dependency must accept the update if
     * the dependant property is already stored in the model.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testSetterAndPopulateValidateTheDependencyAgainstTheStoredData(
        GeneratorConfiguration $configuration,
    ): void {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile('PropertyDependency.json', $configuration->setImmutable(false));

        $object = new $className(['billing_address' => '555 Debitors Lane']);

        $object->setCreditCard(12345);
        $this->assertSame(12345, $object->getCreditCard());

        $object->populate(['credit_card' => 6789]);
        $this->assertSame(6789, $object->getCreditCard());

        // The dependency is still enforced if the dependant property isn't stored.
        $object = new $className([]);

        try {
            $object->setCreditCard(12345);
            $this->fail('Expected exception for missing property dependency');
        } catch (ErrorRegistryException | InvalidPropertyDependencyException $exception) {
            $this->assertSame(
                <<<ERROR
                Missing required attributes which are dependants of 'credit_card':
                  - 'billing_address'
                ERROR,
                $exception->getMessage(),
            );
        }

        $this->assertNull($object->getCreditCard());
    }

    public static function compositionBranchDependencyProvider(): array
    {
        return [
            'oneOf, property dependency' => ['oneOf', '["extra"]', true],
            'oneOf, schema dependency' => ['oneOf', '{"required": ["extra"]}', true],
            'anyOf, property dependency' => ['anyOf', '["extra"]', false],
            'anyOf, schema dependency' => ['anyOf', '{"required": ["extra"]}', false],
        ];
    }

    /**
     * A dependency declared by a branch of a disjunctive composition only applies if the value is validated against
     * that branch: data matching another branch must be accepted although it violates the dependency.
     */
    #[DataProvider('compositionBranchDependencyProvider')]
    public function testDependencyOfACompositionBranchOnlyAppliesToTheBranch(
        string $composition,
        string $dependency,
        bool $matchingBothBranchesIsRejected,
    ): void {
        $className = $this->generateClassFromFileTemplate(
            'CompositionBranchDependency.json',
            [$composition, $dependency],
            (new GeneratorConfiguration())->setCollectErrors(false),
            false,
        );

        // only the first branch matches, the dependency of the second branch is violated
        $object = new $className(['kind' => 'k', 'note' => 'n']);
        $this->assertSame(['kind' => 'k', 'note' => 'n'], $object->meta()->rawInput());

        // only the second branch matches
        $object = new $className(['note' => 'n', 'extra' => 'e']);
        $this->assertSame(['note' => 'n', 'extra' => 'e'], $object->meta()->rawInput());

        $exception = $this->catchException(
            static fn() => new $className(['kind' => 'k', 'note' => 'n', 'extra' => 'e']),
        );

        if (!$matchingBothBranchesIsRejected) {
            $this->assertNull($exception);

            return;
        }

        $this->assertInstanceOf(OneOfException::class, $exception);
        $this->assertSame(
            "Invalid value for '$className' declined by composition constraint\n"
                . "  Requires to match one composition element but matched 2 elements\n"
                . "  - Composition element #1: Valid\n"
                . '  - Composition element #2: Valid',
            $exception->getMessage(),
        );
    }

    public static function allOfBranchDependencyProvider(): array
    {
        return [
            'property dependency' => [
                '["extra"]',
                "    * Missing required attributes which are dependants of 'note':\n      - 'extra'",
            ],
            'schema dependency' => [
                '{"required": ["extra"]}',
                "    * Invalid schema which is dependant on 'note':\n      - Missing required value for 'extra'",
            ],
        ];
    }

    /**
     * A dependency declared by an allOf branch is validated by the composition, like every other constraint of a
     * branch: a violation is reported once by the composition, not a second time by the property of the model.
     */
    #[DataProvider('allOfBranchDependencyProvider')]
    public function testViolatedDependencyOfAnAllOfBranchIsReportedOnce(
        string $dependency,
        string $branchError,
    ): void {
        $className = $this->generateClassFromFileTemplate(
            'CompositionBranchDependency.json',
            ['allOf', $dependency],
            (new GeneratorConfiguration())->setCollectErrors(true),
            false,
        );

        $exception = $this->catchException(static fn() => new $className(['kind' => 'k', 'note' => 'n']));

        $this->assertInstanceOf(ErrorRegistryException::class, $exception);
        $this->assertCount(1, $exception->getErrors());
        $this->assertInstanceOf(AllOfException::class, $exception->getErrors()[0]);
        $this->assertSame(
            "Invalid value for '$className' declined by composition constraint\n"
                . "  Requires to match all composition elements but matched 1 element\n"
                . "  - Composition element #1: Valid\n"
                . "  - Composition element #2: Failed\n"
                . $branchError,
            $exception->getMessage(),
        );

        $object = new $className(['kind' => 'k', 'note' => 'n', 'extra' => 'e']);
        $this->assertSame(['kind' => 'k', 'note' => 'n', 'extra' => 'e'], $object->meta()->rawInput());
    }
}
