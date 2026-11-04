<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ComposedValue\OneOfException;
use PHPModelGenerator\Exception\Dependency\InvalidPropertyDependencyException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Object\AdditionalPropertiesException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Fixtures\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;

class PropertyDependencyTest extends AbstractPHPModelGeneratorTestCase
{
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

    /**
     * A `dependencies` trigger that is declared neither in `properties` nor `required` was
     * silently dropped by PropertiesValidatorFactory instead of being enforced, because the
     * dependency-attaching code only ran inside the loop over declared `properties` entries.
     *
     * Also asserts that both the trigger and each array-form dependency target are synthesised
     * onto the generated class with mixed type hints when absent from the schema's `properties`
     * block. If a target were already declared in `properties` its declared type would apply
     * instead; this fixture exercises the fully-undeclared case.
     */
    public function testDependencyTriggerNotDeclaredInPropertiesOrRequiredIsEnforced(): void
    {
        $className = $this->generateClassFromFile('UndeclaredTriggerPropertyDependency.json');

        // Valid: no trigger present, dependency not enforced; both synthesised properties readable
        $object = new $className([]);
        $this->assertNull($object->getCreditCard());
        $this->assertNull($object->getBillingAddress());

        // Valid: trigger present and dependency satisfied; both accessors return correct values
        $object = new $className(['credit_card' => 12345, 'billing_address' => '555 Debitors Lane']);
        $this->assertSame(12345, $object->getCreditCard());
        $this->assertSame('555 Debitors Lane', $object->getBillingAddress());

        // Invalid: trigger present but required dependent property absent
        $this->expectException(InvalidPropertyDependencyException::class);
        $this->expectExceptionMessage(
            "Missing required attributes which are dependants of 'credit_card':\n  - 'billing_address'",
        );

        new $className(['credit_card' => 12345]);
    }

    /**
     * When array-form dependency targets are already declared elsewhere — directly in `properties`
     * or through a composition branch — the synthesised trigger carries a mixed type hint while
     * each target inherits its type from its prior declaration rather than being re-synthesised
     * as mixed. Covers root-property narrowing (billing_address typed as string) and composition
     * narrowing (name typed as string via allOf).
     */
    public function testArrayFormDependencyTargetsInheritDeclaredTypesWhenAlreadyPresent(): void
    {
        $className = $this->generateClassFromFile('NarrowedTargetPropertyDependency.json');

        // Valid: no trigger; all properties readable with their declared types
        $object = new $className([]);
        $this->assertNull($object->getCreditCard());
        $this->assertNull($object->getBillingAddress());
        $this->assertNull($object->getName());

        // Valid: trigger present and both targets satisfied
        $object = new $className(['credit_card' => 'any', 'billing_address' => 'addr', 'name' => 'John']);
        $this->assertSame('any', $object->getCreditCard());
        $this->assertSame('addr', $object->getBillingAddress());
        $this->assertSame('John', $object->getName());

        // Invalid: trigger present but both targets absent
        $this->expectException(InvalidPropertyDependencyException::class);
        $this->expectExceptionMessage(
            "Missing required attributes which are dependants of 'credit_card':\n  - 'billing_address'\n  - 'name'",
        );

        new $className(['credit_card' => 'any']);
    }

    /**
     * When an array-form dependency trigger is declared in `properties` but its target is not
     * declared anywhere in the schema, the target must be synthesised as a mixed-typed property
     * so its value is readable via the generated accessor. The dependency enforcement already
     * works today (PropertyDependencyValidator is attached because the trigger is found); the
     * defect is the missing getter for the undeclared target.
     */
    public function testArrayFormUndeclaredDependencyTargetIsSynthesisedAsMixed(): void
    {
        $className = $this->generateClassFromFile('DeclaredTriggerUndeclaredTargetPropertyDependency.json');

        // Valid: no trigger; synthesised target accessor returns null
        $object = new $className([]);
        $this->assertNull($object->getCreditCard());
        $this->assertNull($object->getBillingAddress());

        // Valid: trigger present and target satisfied; both values readable
        $object = new $className(['credit_card' => 12345, 'billing_address' => '555 Debitors Lane']);
        $this->assertSame(12345, $object->getCreditCard());
        $this->assertSame('555 Debitors Lane', $object->getBillingAddress());

        // Invalid: trigger present but target absent
        $this->expectException(InvalidPropertyDependencyException::class);
        $this->expectExceptionMessage(
            "Missing required attributes which are dependants of 'credit_card':\n  - 'billing_address'",
        );

        new $className(['credit_card' => 12345]);
    }

    /**
     * With `additionalProperties: false` an undeclared trigger can never be part of the input (the key is
     * rejected as an additional property), so its dependency is vacuous. Generating it would add a public
     * accessor pair for a key the model always rejects: the dependency is skipped and a warning is logged.
     */
    public function testUndeclaredTriggerIsSkippedWithWarningWhenAdditionalPropertiesIsFalse(): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'UndeclaredTriggerNoAdditionalProperties.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setLogger($recordingLogger),
        );

        $this->assertSame(
            1,
            $this->countUnreachableDependencyWarnings(
                $recordingLogger,
                'credit_card',
                'additionalProperties: false rejects the undeclared key',
            ),
        );
        $this->assertCount(1, $this->getWarnings($recordingLogger));

        // no accessors are generated for the unreachable trigger and its target
        $this->assertFalse(method_exists($className, 'getCreditCard'));
        $this->assertFalse(method_exists($className, 'getBillingAddress'));

        $object = new $className(['name' => 'John']);
        $this->assertSame('John', $object->getName());

        // the key is rejected by additionalProperties, not by a dependency
        $this->assertInstantiationFailsWithMessage(
            $className,
            ['credit_card' => 12345],
            AdditionalPropertiesException::class,
            "Provided JSON for '%class%' contains not allowed additional properties ['credit_card']",
        );
    }

    /**
     * The `denyAdditionalProperties()` configuration flips a missing `additionalProperties` to false, so an
     * undeclared trigger is unreachable exactly as with an explicit `additionalProperties: false`.
     */
    public function testUndeclaredTriggerIsSkippedWithWarningWhenAdditionalPropertiesAreDeniedByConfiguration(): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'UndeclaredTriggerPropertyDependency.json',
            (new GeneratorConfiguration())
                ->setDenyAdditionalProperties(true)
                ->setCollectErrors(false)
                ->setLogger($recordingLogger),
        );

        $this->assertSame(
            1,
            $this->countUnreachableDependencyWarnings(
                $recordingLogger,
                'credit_card',
                'denyAdditionalProperties() rejects the undeclared key',
            ),
        );
        $this->assertCount(1, $this->getWarnings($recordingLogger));

        $this->assertFalse(method_exists($className, 'getCreditCard'));
        $this->assertFalse(method_exists($className, 'getBillingAddress'));

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['credit_card' => 12345],
            AdditionalPropertiesException::class,
            "Provided JSON for '%class%' contains not allowed additional properties ['credit_card']",
        );
    }

    /**
     * A trigger matching a `patternProperties` regex is a legal key under `additionalProperties: false`, so
     * its dependency can fire and must neither be skipped nor warned about.
     */
    #[DataProvider('validationMethodDataProvider')]
    public function testUndeclaredTriggerMatchingPatternPropertiesIsEnforced(
        GeneratorConfiguration $configuration,
    ): void {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'UndeclaredTriggerPatternProperties.json',
            $configuration->setLogger($recordingLogger),
        );

        $this->assertSame([], $this->getWarnings($recordingLogger));

        $object = new $className([]);
        $this->assertNull($object->getXCard());

        $object = new $className(['x_card' => '1234', 'x_address' => '555 Debitors Lane']);
        $this->assertSame('1234', $object->getXCard());
        $this->assertSame('555 Debitors Lane', $object->getXAddress());

        $this->expectValidationError(
            $configuration,
            "Missing required attributes which are dependants of 'x_card':\n  - 'x_address'",
        );

        new $className(['x_card' => '1234']);
    }

    /**
     * `additionalProperties: {schema}` and an absent `additionalProperties` accept the undeclared trigger, so
     * the dependency is enforced and no warning is logged.
     */
    #[DataProvider('reachableUndeclaredTriggerFileProvider')]
    public function testUndeclaredTriggerIsEnforcedWithoutWarningWhenTheKeyIsAccepted(string $file): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            $file,
            (new GeneratorConfiguration())->setCollectErrors(false)->setLogger($recordingLogger),
        );

        $this->assertSame([], $this->getWarnings($recordingLogger));

        $object = new $className(['credit_card' => '1234', 'billing_address' => '555 Debitors Lane']);
        $this->assertSame('1234', $object->getCreditCard());

        $this->expectException(InvalidPropertyDependencyException::class);
        $this->expectExceptionMessage(
            "Missing required attributes which are dependants of 'credit_card':\n  - 'billing_address'",
        );

        new $className(['credit_card' => '1234']);
    }

    public static function reachableUndeclaredTriggerFileProvider(): array
    {
        return [
            'additionalProperties with a schema' => ['UndeclaredTriggerAdditionalPropertiesSchema.json'],
            'additionalProperties absent' => ['UndeclaredTriggerPropertyDependency.json'],
        ];
    }

    /**
     * Regression guard for the skip: a trigger declared in `properties` stays enforced under
     * `additionalProperties: false`, without a warning.
     */
    public function testDeclaredTriggerIsEnforcedWithoutWarningWhenAdditionalPropertiesIsFalse(): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'DeclaredTriggerNoAdditionalProperties.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setLogger($recordingLogger),
        );

        $this->assertSame([], $this->getWarnings($recordingLogger));

        $this->expectException(InvalidPropertyDependencyException::class);
        $this->expectExceptionMessage(
            "Missing required attributes which are dependants of 'credit_card':\n  - 'billing_address'",
        );

        new $className(['credit_card' => 12345]);
    }

    /**
     * Skipping is decided per dependency: the unreachable one is skipped and warned about, the one on a
     * declared property keeps being enforced.
     */
    public function testOnlyTheUnreachableDependencyIsSkipped(): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'PartiallyUnreachableDependencies.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setLogger($recordingLogger),
        );

        $this->assertSame(
            1,
            $this->countUnreachableDependencyWarnings(
                $recordingLogger,
                'credit_card',
                'additionalProperties: false rejects the undeclared key',
            ),
        );
        $this->assertCount(1, $this->getWarnings($recordingLogger));
        $this->assertFalse(method_exists($className, 'getCreditCard'));

        $this->expectException(InvalidPropertyDependencyException::class);
        $this->expectExceptionMessage(
            "Missing required attributes which are dependants of 'name':\n  - 'nickname'",
        );

        new $className(['name' => 'John']);
    }

    /**
     * A dependency on a key which the branch itself neither declares nor requires is enforced as part of the
     * branch, for both dependency forms and both error modes.
     */
    #[DataProvider('undeclaredTriggerInAllOfBranchProvider')]
    public function testUndeclaredTriggerInAnAllOfBranchIsEnforced(
        GeneratorConfiguration $configuration,
        string $dependency,
        string $branchError,
    ): void {
        $className = $this->generateClassFromFileTemplate(
            'UndeclaredTriggerInAllOfBranch.json',
            [$dependency],
            $configuration,
            false,
        );

        $object = new $className(['note' => 'n', 'extra' => 'e']);
        $this->assertSame(['note' => 'n', 'extra' => 'e'], $object->meta()->rawInput());

        $object = new $className([]);
        $this->assertSame([], $object->meta()->rawInput());

        $exception = $this->catchException(static fn() => new $className(['note' => 'n']));

        $this->assertInstanceOf(
            $configuration->collectErrors() ? ErrorRegistryException::class : AllOfException::class,
            $exception,
        );
        $this->assertStringContainsString($branchError, $exception->getMessage());
    }

    public static function undeclaredTriggerInAllOfBranchProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'property dependency' => [
                    '["extra"]',
                    "    * Missing required attributes which are dependants of 'note':\n      - 'extra'",
                ],
                'schema dependency' => [
                    '{"required": ["extra"]}',
                    "    * Invalid schema which is dependant on 'note':\n      - Missing required value for 'extra'",
                ],
            ],
        );
    }

    private function getWarnings(RecordingLogger $recordingLogger): array
    {
        return array_values(
            array_filter(
                $recordingLogger->getEntries(),
                static fn(array $entry): bool => $entry['level'] === 'warning',
            ),
        );
    }

    private function countUnreachableDependencyWarnings(
        RecordingLogger $recordingLogger,
        string $trigger,
        string $reason,
    ): int {
        return count(
            array_filter(
                $this->getWarnings($recordingLogger),
                static fn(array $entry): bool => $entry['message']
                        === "Dependencies on '{trigger}' in class '{class}' can never apply: {reason}"
                    && ($entry['context']['trigger'] ?? null) === $trigger
                    && ($entry['context']['reason'] ?? null) === $reason
                    && is_string($entry['context']['class'] ?? null),
            ),
        );
    }
}
