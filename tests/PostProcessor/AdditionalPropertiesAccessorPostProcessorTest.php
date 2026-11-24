<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\PostProcessor;

use DateTime;
use Exception;
use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\Object\InvalidAdditionalPropertiesException;
use PHPModelGenerator\Exception\Object\InvalidPropertyNamesException;
use PHPModelGenerator\Exception\Object\MaxPropertiesException;
use PHPModelGenerator\Exception\Object\MinPropertiesException;
use PHPModelGenerator\Exception\Object\RegularPropertyAsAdditionalPropertyException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\Hook\SetterAfterValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\Hook\SetterBeforeValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\PostProcessor\AdditionalPropertiesAccessorPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Fixtures\RecordingLogger;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

#[ApplicableDrafts]
class AdditionalPropertiesAccessorPostProcessorTest extends AbstractPHPModelGeneratorTestCase
{
    private const BASE_LEVEL_REFERENCE_DECLARATION =
        '"$defs": {"base": {"type": "object", "properties": {"foo": {"type": "string"}}}}, "$ref": "#/$defs/base"';

    /**
     * [phase, property name] entries appended by the setter hooks of the generated model
     */
    public static array $hookLog = [];

    protected function addPostProcessor(bool $addForModelsWithoutAdditionalPropertiesDefinition): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator) use (
            $addForModelsWithoutAdditionalPropertiesDefinition,
        ): void {
            $generator->addPostProcessor(
                new AdditionalPropertiesAccessorPostProcessor($addForModelsWithoutAdditionalPropertiesDefinition),
            );
        };
    }

    #[DataProvider('additionalPropertiesAccessorPostProcessorConfigurationDataProvider')]
    public function testAdditionalPropertiesAccessorsAreNotGeneratedForAdditionalPropertiesFalse(
        bool $addForModelsWithoutAdditionalPropertiesDefinition,
    ): void {
        $this->addPostProcessor($addForModelsWithoutAdditionalPropertiesDefinition);

        $className = $this->generateClassFromFile('AdditionalPropertiesFalse.json');

        $object = new $className();

        $this->assertFalse(is_callable([$object, 'additionalProperties']));
    }

    #[DataProvider('additionalPropertiesAccessorPostProcessorConfigurationDataProvider')]
    public function testAdditionalPropertiesAccessorsAreNotGeneratedWhenAdditionalPropertiesAreDenied(
        bool $addForModelsWithoutAdditionalPropertiesDefinition,
    ): void {
        $this->addPostProcessor($addForModelsWithoutAdditionalPropertiesDefinition);

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesNotDefined.json',
            (new GeneratorConfiguration())->setDenyAdditionalProperties(true),
        );

        $object = new $className();

        $this->assertFalse(is_callable([$object, 'additionalProperties']));
    }

    #[DataProvider('additionalPropertiesAccessorPostProcessorConfigurationDataProvider')]
    public function testAdditionalPropertiesAccessorsDependOnConfigurationForAdditionalPropertiesNotDefined(
        bool $addForModelsWithoutAdditionalPropertiesDefinition,
    ): void {
        $this->addPostProcessor($addForModelsWithoutAdditionalPropertiesDefinition);

        $className = $this->generateClassFromFile('AdditionalPropertiesNotDefined.json');

        $object = new $className();

        $this->assertSame(
            $addForModelsWithoutAdditionalPropertiesDefinition,
            is_callable([$object, 'additionalProperties']),
        );

        if ($addForModelsWithoutAdditionalPropertiesDefinition) {
            $accessor = $object->additionalProperties();
            // No companion for untyped additional properties — check native PHP return types directly
            $returnType = $this->getReturnType($accessor, 'getAll');
            $this->assertSame('array', $returnType->getName());
            $this->assertFalse($returnType->allowsNull());

            $this->assertSame('mixed', $this->getReturnType($accessor, 'get')->getName());
        }
    }

    public function testAdditionalPropertiesSettersForMutableObjectsWithoutAdditionalPropertiesDefinition(): void
    {
        $this->addPostProcessor(true);
        $className = $this->generateClassFromFile(
            'AdditionalPropertiesNotDefined.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['property1' => 100]);
        $accessor = $object->additionalProperties();

        $this->assertTrue(is_callable([$accessor, 'set']));
        $this->assertTrue(is_callable([$accessor, 'remove']));

        $this->assertSame(100, $accessor->get('property1'));
        $this->assertEqualsCanonicalizing(['property1' => 100], $accessor->getAll());

        $accessor->set('property2', 200);
        $this->assertEqualsCanonicalizing(['property1' => 100, 'property2' => 200], $accessor->getAll());

        $accessor->set('property1', 10);
        $this->assertEqualsCanonicalizing(['property1' => 10, 'property2' => 200], $accessor->getAll());

        $accessor->remove('property1');
        $this->assertEqualsCanonicalizing(['property2' => 200], $accessor->getAll());
        $this->assertNull($accessor->get('property1'));
    }

    #[DataProvider('additionalPropertiesAccessorPostProcessorConfigurationDataProvider')]
    public function testAdditionalPropertiesAccessorsAreGeneratedForAdditionalProperties(
        bool $addForModelsWithoutAdditionalPropertiesDefinition,
    ): void {
        $this->addPostProcessor($addForModelsWithoutAdditionalPropertiesDefinition);

        $className = $this->generateClassFromFile(
            'AdditionalProperties.json',
            // make sure the deny additional properties setting doesn't affect specified additional properties
            (new GeneratorConfiguration())->setDenyAdditionalProperties(true),
        );

        $object = new $className(['property1' => 'Hello', 'property2' => 'World']);
        $accessor = $object->additionalProperties();

        $this->assertTrue(is_callable([$object, 'additionalProperties']));

        // immutable model — no set/remove on the accessor
        $this->assertFalse(is_callable([$accessor, 'set']));
        $this->assertFalse(is_callable([$accessor, 'remove']));

        $this->assertEqualsCanonicalizing(
            ['property1' => 'Hello', 'property2' => 'World'],
            $accessor->getAll(),
        );
        $this->assertSame('Hello', $accessor->get('property1'));
        $this->assertSame('World', $accessor->get('property2'));
        $this->assertNull($accessor->get('property3'));
    }

    /**
     * A base-level $ref transfers the additionalProperties validator of the referenced schema, so the internal post
     * processor declares the `_additionalProperties` field of the referencing class. The accessor post processor
     * declares it for a class whose own JSON lacks the keyword as well. The second declaration must be harmless: the
     * second registration of the property must not be reported, and the field must be declared so the additional
     * properties are stored without creating a dynamic property.
     */
    public function testAccessorOnAClassWithATransferredAdditionalPropertiesValidatorDeclaresTheFieldOnce(): void
    {
        $this->addPostProcessor(true);
        $logger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'BaseRefAdditionalProperties.json',
            (new GeneratorConfiguration())->setCollectErrors(false)->setLogger($logger),
        );

        $this->assertSame(
            [],
            array_values(array_filter(
                $logger->getEntries(),
                static fn(array $entry): bool => $entry['level'] === 'warning',
            )),
        );
        $this->assertTrue((new ReflectionClass($className))->hasProperty('_additionalProperties'));

        $deprecations = [];
        set_error_handler(static function (int $errorNumber, string $errorMessage) use (&$deprecations): bool {
            $deprecations[] = $errorMessage;

            return true;
        });

        try {
            $object = new $className(['zz' => 's']);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations);
        $this->assertSame('s', $object->additionalProperties()->get('zz'));

        // the transferred validator still validates the additional properties
        $this->expectException(InvalidAdditionalPropertiesException::class);
        $this->expectExceptionMessageMatches(<<<'MSG'
        /^Provided JSON for '\S+' contains invalid additional properties
          - invalid additional property 'zz'
            \* Invalid type for 'additional property': requires 'string', got 'integer'$/
        MSG);

        new $className(['zz' => 5]);
    }

    public static function additionalPropertiesAccessorPostProcessorConfigurationDataProvider(): array
    {
        return [
            'Add also for models without additional properties definition' => [true],
            'Add only for models with additional properties definition' => [false],
        ];
    }

    public function testAdditionalPropertiesModifiersAreGeneratedForMutableObjects(): void
    {
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'AdditionalProperties.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['property1' => '  Hello  ', 'property2' => 'World']);
        $accessor = $object->additionalProperties();

        $this->assertTrue(is_callable([$object, 'additionalProperties']));
        $this->assertTrue(is_callable([$accessor, 'set']));
        $this->assertTrue(is_callable([$accessor, 'remove']));

        // test adding a new additional property
        $accessor->set('property3', '  Good night  ');
        $this->assertEqualsCanonicalizing(
            ['property1' => 'Hello', 'property2' => 'World', 'property3' => 'Good night'],
            $accessor->getAll(),
        );
        $this->assertEqualsCanonicalizing(
            ['property1' => '  Hello  ', 'property2' => 'World', 'property3' => '  Good night  '],
            $object->meta()->rawInput(),
        );
        $this->assertSame('Good night', $accessor->get('property3'));

        // test removing an additional property
        $this->assertTrue($accessor->remove('property2'));
        $this->assertFalse($accessor->remove('property2'));
        $this->assertEqualsCanonicalizing(
            ['property1' => 'Hello', 'property3' => 'Good night'],
            $accessor->getAll(),
        );
        $this->assertEqualsCanonicalizing(
            ['property1' => '  Hello  ', 'property3' => '  Good night  '],
            $object->meta()->rawInput(),
        );

        // test update an existing additional property
        $accessor->set('property3', '  !Good night!  ');
        $this->assertEqualsCanonicalizing(
            ['property1' => 'Hello', 'property3' => '!Good night!'],
            $accessor->getAll(),
        );
        $this->assertEqualsCanonicalizing(
            ['property1' => '  Hello  ', 'property3' => '  !Good night!  '],
            $object->meta()->rawInput(),
        );
        $this->assertSame('!Good night!', $accessor->get('property3'));

        // test typing on the companion class methods
        $this->assertSame('string[]', $this->getReturnTypeAnnotation($accessor, 'getAll'));
        $returnType = $this->getReturnType($accessor, 'getAll');
        $this->assertSame('array', $returnType->getName());
        $this->assertFalse($returnType->allowsNull());

        $this->assertSame('string|null', $this->getReturnTypeAnnotation($accessor, 'get'));
        $returnType = $this->getReturnType($accessor, 'get');
        $this->assertSame('string', $returnType->getName());
        $this->assertTrue($returnType->allowsNull());

        $this->assertSame('string', $this->getParameterTypeAnnotation($accessor, 'set', 1));
        $paramType = $this->getParameterType($accessor, 'set', 1);
        $this->assertSame('string', $paramType->getName());
        $this->assertFalse($paramType->allowsNull());
    }

    /**
     * @param string $expectedMessageTemplate The expected message, '%s' is replaced with the class name
     */
    #[DataProvider('invalidAdditionalPropertyDataProvider')]
    public function testInvalidAdditionalPropertyThrowsAnException(
        string $expectedException,
        string $expectedMessageTemplate,
        string $action,
        array $items,
        string $expectedPointer,
    ): void {
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'AdditionalProperties.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className(['property1' => '  Hello  ', 'property2' => 'World']);
        $accessor = $object->additionalProperties();

        $exception = $this->catchException(static function () use ($accessor, $action, $items): void {
            foreach ($items as $property => $value) {
                $action === 'remove'
                    ? $accessor->remove($value)
                    : $accessor->set($property, $value);
            }
        });

        $this->assertInstanceOf($expectedException, $exception);
        $this->assertSame(sprintf($expectedMessageTemplate, $className), $exception->getMessage());
        $this->assertSame($expectedPointer, $exception->getJsonPointer()->pointer);
    }

    public static function invalidAdditionalPropertyDataProvider(): array
    {
        return [
            'regular object property' => [
                RegularPropertyAsAdditionalPropertyException::class,
                "Could not add regular property 'name' as an additional property of object '%s'",
                'add',
                ['name' => 'Hannes'],
                '/properties/name',
            ],
            'min properties violation' => [
                MinPropertiesException::class,
                "Provided object for '%s' must not contain less than 2 properties",
                'remove',
                ['property1'],
                '/minProperties',
            ],
            'max properties violation' => [
                MaxPropertiesException::class,
                "Provided object for '%s' must not contain more than 4 properties",
                'add',
                ['property3' => 'Bye', 'property4' => 'Ciao', 'property5' => 'fails'],
                '/maxProperties',
            ],
            'Invalid property name' => [
                InvalidPropertyNamesException::class,
                <<<'MSG'
                Provided JSON for '%s' contains properties with invalid names
                  - invalid property 'property name with spaces'
                    * Value for 'property name' does not match pattern '^[a-z0-9]*$'
                MSG,
                'add',
                ['property name with spaces' => 'should fail'],
                '/propertyNames',
            ],
            'Invalid property value' => [
                InvalidAdditionalPropertiesException::class,
                <<<'MSG'
                Provided JSON for '%s' contains invalid additional properties
                  - invalid additional property 'property2'
                    * Value for 'additional property' must not be longer than 15
                MSG,
                'add',
                ['property2' => 'My much too long property value will fail the validation'],
                '/additionalProperties',
            ],
        ];
    }

    public function testMinPropertiesExceptionExposesCount(): void
    {
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'AdditionalProperties.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className(['property1' => '  Hello  ', 'property2' => 'World']);

        try {
            $object->additionalProperties()->remove('property1');
            $this->fail('Expected MinPropertiesException was not thrown');
        } catch (MinPropertiesException $exception) {
            $this->assertSame(2, $exception->getMinProperties());
            $this->assertSame(1, $exception->getCount());
        }
    }

    public function testNameDeclaredByMultipleBranchesIsSettableAndValidatedByEveryDeclaration(): void
    {
        // 'name' is declared in two allOf branches (no root-level declaration) and merged into a single property. It
        // is an additional property: the accessor sets it, and the validators of both declarations apply.
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesMultiBranchProperty.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className(['name' => 'Hannes']);
        $accessor = $object->additionalProperties();

        $accessor->set('name', 'Other');
        $this->assertSame('Other', $object->getName());
        $this->assertSame(['name' => 'Other'], $accessor->getAll());

        // violates the minLength of the second declaration only: rejected like the constructor rejects the data
        $expectedException = $this->catchException(static fn() => new $className(['name' => '']));
        $this->assertInstanceOf(AllOfException::class, $expectedException);

        $exception = $this->catchException(static fn() => $accessor->set('name', ''));
        $this->assertInstanceOf(AllOfException::class, $exception);
        $this->assertSame($expectedException->getMessage(), $exception->getMessage());
        $this->assertSame(
            <<<MSG
            Invalid value for '{$className}' declined by composition constraint
              Requires to match all composition elements but matched 1 element
              - Composition element #1: Valid
              - Composition element #2: Failed
                * Value for 'name' must not be shorter than 1
            MSG,
            $exception->getMessage(),
        );

        $this->assertSame('Other', $object->getName());
        $this->assertSame(['name' => 'Other'], $accessor->getAll());
    }

    /**
     * A name declared by the root 'properties' keyword is not an additional property (the root additionalProperties
     * keyword doesn't apply to it), the accessor keeps refusing it also if a branch declares the name as well. The
     * exception reports the root declaration, not an arbitrary one of the merged property.
     */
    public function testRootPropertyAlsoDeclaredByABranchIsRefusedByTheAccessor(): void
    {
        $this->addPostProcessor(true);

        $className = $this->generateNonRootPropertyClass(
            '"properties": {"name": {"type": "string"}}, "allOf": [{"properties": {"name": {"minLength": 1}}}]',
        );

        $object = new $className(['name' => 'Hannes']);
        $exception = $this->catchException(static fn() => $object->additionalProperties()->set('name', 'Other'));

        $this->assertInstanceOf(RegularPropertyAsAdditionalPropertyException::class, $exception);
        $this->assertSame(
            "Could not add regular property 'name' as an additional property of object '$className'",
            $exception->getMessage(),
        );
        $this->assertSame('/properties/name', $exception->getJsonPointer()->pointer);
        $this->assertSame('Hannes', $object->getName());
    }

    public function testMinPropertiesIsEnforcedWhenRemovingAPatternPropertyKey(): void
    {
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'PatternPropertiesWithMinProperties.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        // 'a1' matches the ^a pattern, 'extra' is a plain additional property; total = 2 = minProperties.
        $object = new $className(['a1' => 'hello', 'extra' => 'world']);
        $accessor = $object->additionalProperties();

        // Removing the pattern-property key 'a1' would drop the count below minProperties=2 — must throw.
        $this->expectException(MinPropertiesException::class);
        $this->expectExceptionMessage("Provided object for '{$className}' must not contain less than 2 properties");

        $accessor->remove('a1');
    }

    /**
     * Direct-exception-mode counterpart to
     * UnevaluatedPropertiesAccessorPostProcessorTest::testRemoveCollectsMinPropertyAndUnevaluatedErrorsTogether:
     * the same removal both drops the count below `minProperties` and flips the `anyOf`
     * composition (branch 0 requires `p_foo`; removing it leaves only branch 1, which claims
     * nothing, orphaning `q_marker`). In collect-errors mode both violations land in one
     * registry; in direct-exception mode `RemoveAdditionalProperty.phptpl`'s
     * `minPropertyValidator` check runs first — before the unset, before composition
     * revalidation, before the post-composition unevaluatedProperties check — so only
     * `MinPropertiesException` ever surfaces, never the orphaned-key exception. The rejected
     * call's `catch` rolls `_rawModelDataInput` back, so the object's public behavior is
     * verified identical to before the failed removal, not just that an exception was thrown.
     */
    public function testRemoveThrowsFirstApplicableExceptionDirectlyAndRollsBackOnCompositionMinPropertiesClash(): void
    {
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'BranchFlipOnRemove.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        // Both anyOf branches succeed: branch 0 (kind: string, additionalProperties: integer,
        // minProperties: 3) claims p_foo and q_marker; branch 1 (kind: const "X") claims
        // nothing. q_marker is credited only through branch 0.
        $object = new $className(['kind' => 'X', 'p_foo' => 1, 'q_marker' => 7]);
        $accessor = $object->additionalProperties();

        try {
            $accessor->remove('p_foo');
            $this->fail('Expected MinPropertiesException, not an aggregated registry');
        } catch (MinPropertiesException $exception) {
            $this->assertSame(
                "Provided object for '{$className}' must not contain less than 3 properties",
                $exception->getMessage(),
            );
        }

        // Rollback discipline: raw input, the additionalProperties bucket, and an unrelated
        // setter all behave exactly as they would have if remove() had never been called.
        $this->assertSame(
            ['kind' => 'X', 'p_foo' => 1, 'q_marker' => 7],
            $object->meta()->rawInput(),
        );
        $this->assertSame(['q_marker' => 7], $accessor->getAll());
        $object->setKind('X');
        $this->assertSame('X', $object->getKind());
    }

    /**
     * A composition branch declaring `additionalProperties` is decided by keys it never names,
     * so the setter-side validation cache — which asks whether the mutated keys intersect the
     * branch's *declared* property names — must not be consulted for it. A dynamic key never
     * intersects that list, so a cached branch outcome would let `set()` store a value the
     * branch rejects.
     *
     * Both entry points must agree: constructing with the same pair is rejected, so setting it
     * has to be rejected too, and the rejected write must leave the raw model data untouched.
     *
     * The fixture declares `kind` twice on purpose. `additionalProperties` only exempts the
     * `properties` of the schema object it sits in, so without the branch's own `kind`
     * declaration the branch would treat the string `kind` as an additional property, fail its
     * `type: integer` claim, and reject the constructor call this test needs to succeed.
     */
    public function testSetIsRejectedByABranchAdditionalPropertiesClaimDespiteACachedBranchOutcome(): void
    {
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'BranchAdditionalPropertiesTypesExtras.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        // Construction populates the branch's cached outcome with "valid".
        $object = new $className(['kind' => 'a']);

        // The same state the constructor refuses below, reached through the accessor instead.
        try {
            $object->additionalProperties()->set('extra', 'not-an-integer');
            $this->fail('Expected the branch additionalProperties claim to reject the value');
        } catch (AllOfException $exception) {
            $this->assertMessageMatchesTemplate(
                <<<MSG
                Invalid value for '$className' declined by composition constraint
                  Requires to match all composition elements but matched 0 elements
                  - Composition element #1: Failed
                    * Provided JSON for '%class%' contains invalid additional properties
                      - invalid additional property 'extra'
                        * Invalid type for 'additional property': requires 'int', got 'string'
                MSG,
                $exception->getMessage(),
            );
        }

        $this->assertSame([], $object->additionalProperties()->getAll());
        $this->assertSame(['kind' => 'a'], $object->meta()->rawInput());

        $this->expectException(AllOfException::class);
        $this->expectExceptionMessage(
            <<<MSG
            Invalid value for '{$className}' declined by composition constraint
              Requires to match all composition elements but matched 0 elements
            MSG,
        );
        new $className(['kind' => 'a', 'extra' => 'not-an-integer']);
    }

    public static function removalBreakingDependencyProvider(): array
    {
        return [
            'property dependency' => ['["b"]', false],
            'property dependency (collect errors)' => ['["b"]', true],
            'schema dependency' => ['{"required": ["b"]}', false],
            'schema dependency (collect errors)' => ['{"required": ["b"]}', true],
        ];
    }

    /**
     * The dependency of the declared property a requires the additional property b. Removing b must be rejected like
     * the constructor rejects the remaining data, and leave the model unchanged.
     */
    #[DataProvider('removalBreakingDependencyProvider')]
    public function testRemovalBreakingADependencyIsRejectedLikeTheConstructor(
        string $dependency,
        bool $collectErrors,
    ): void {
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFileTemplate(
            'RemovalBreakingDependency.json',
            [$dependency],
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors($collectErrors),
            false,
        );

        $expectedException = $this->catchException(static fn() => new $className(['a' => 'x']));
        $this->assertNotNull($expectedException, 'The constructor must reject the remaining data');

        $object = new $className(['a' => 'x', 'b' => 'y']);
        $actualException = $this->catchException(static fn() => $object->additionalProperties()->remove('b'));

        $this->assertNotNull($actualException, 'The removal must be rejected');
        $this->assertSame($expectedException::class, $actualException::class);
        $this->assertSame($expectedException->getMessage(), $actualException->getMessage());
        $this->assertSame(['a' => 'x', 'b' => 'y'], $object->meta()->rawInput());
        $this->assertSame(['b' => 'y'], $object->additionalProperties()->getAll());

        // removing a key no dependency requires is accepted
        $object = new $className(['a' => 'x', 'b' => 'y', 'c' => 'z']);
        $this->assertTrue($object->additionalProperties()->remove('c'));
        $this->assertSame(['a' => 'x', 'b' => 'y'], $object->meta()->rawInput());

        // without the dependent property the dependency doesn't apply
        $object = new $className(['b' => 'y']);
        $this->assertTrue($object->additionalProperties()->remove('b'));
        $this->assertSame([], $object->meta()->rawInput());
    }

    /**
     * Typed properties of the model which are not declared by the root 'properties' keyword. The root
     * additionalProperties keyword applies to them, they are additional properties as well. Each entry: the schema
     * keywords declaring 'foo', the data these keywords require besides 'foo', and the part of that data which is an
     * additional property itself.
     */
    public static function optionalNonRootPropertyProvider(): array
    {
        return [
            // declared by the only allOf branch
            'allOf branch' => ['"allOf": [{"properties": {"foo": {"type": "string"}}}]', [], []],
            // declared by the only anyOf branch
            'anyOf branch' => ['"anyOf": [{"properties": {"foo": {"type": "string"}}}]', [], []],
            // declared by the only oneOf branch
            'oneOf branch' => ['"oneOf": [{"properties": {"foo": {"type": "string"}}}]', [], []],
            // declared by the then branch; 'kind' is declared by the if branch only, so it is additional as well
            'then branch' => [
                '"if": {"properties": {"kind": {"const": "a"}}, "required": ["kind"]}, '
                    . '"then": {"properties": {"foo": {"type": "string"}}}',
                ['kind' => 'a'],
                ['kind' => 'a'],
            ],
            // declared by the schema dependency of the root property 'a'
            'schema dependency' => [
                '"properties": {"a": {"type": "string"}}, '
                    . '"dependencies": {"a": {"properties": {"foo": {"type": "string"}}}}',
                ['a' => 'x'],
                [],
            ],
        ];
    }

    /**
     * Like optionalNonRootPropertyProvider, but 'foo' is listed in the root 'required' keyword.
     */
    public static function requiredNonRootPropertyProvider(): array
    {
        return [
            // listed in 'required' without any declaration
            'undeclared required name' => ['"required": ["foo"]', [], []],
            // listed in the root 'required', declared by an allOf branch
            'required name declared by an allOf branch' => [
                '"required": ["foo"], "allOf": [{"properties": {"foo": {"type": "string"}}}]',
                [],
                [],
            ],
        ];
    }

    public static function typedSetterOfNonRootPropertyProvider(): array
    {
        return self::combineDataProvider(
            array_merge(self::optionalNonRootPropertyProvider(), self::requiredNonRootPropertyProvider()),
            [
                // additional properties are stored without constraints
                'additionalProperties true' => [true],
                // additional properties are stored by the accessor post processor
                'additionalProperties not defined' => [false],
            ],
        );
    }

    /**
     * Without constraints for the additional properties the constructor stores the additional properties without
     * validating them. The typed setter of a non-root property must update the stored additional property as well:
     * the typed property and the stored additional property are two views of the same key.
     */
    #[DataProvider('typedSetterOfNonRootPropertyProvider')]
    public function testTypedSetterOfNonRootPropertyUpdatesTheStoredAdditionalProperty(
        string $declaration,
        array $requiredData,
        array $additionalRequiredData,
        bool $additionalPropertiesTrue,
    ): void {
        $this->addPostProcessor(true);

        $generatorConfiguration = (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false);
        $className = $additionalPropertiesTrue
            ? $this->generateClassFromFileTemplate(
                'NonRootProperty.json',
                [$declaration, 'true'],
                $generatorConfiguration,
                false,
            )
            : $this->generateClassFromFileTemplate(
                'NonRootPropertyWithoutAdditionalProperties.json',
                [$declaration],
                $generatorConfiguration,
                false,
            );

        $object = new $className($requiredData + ['foo' => 'ab']);
        $this->assertSame($additionalRequiredData + ['foo' => 'ab'], $object->additionalProperties()->getAll());

        $object->setFoo('xy');
        $this->assertSame('xy', $object->getFoo());
        $this->assertSame($additionalRequiredData + ['foo' => 'xy'], $object->additionalProperties()->getAll());
    }

    public static function nonRootPropertyProvider(): array
    {
        return array_merge(self::optionalNonRootPropertyProvider(), self::requiredNonRootPropertyProvider());
    }

    /**
     * A non-root property is an additional property, the accessor sets it like any other additional property and the
     * typed property reflects the new value.
     *
     * Refusing the accessor for every typed property (RegularPropertyAsAdditionalPropertyException) was rejected:
     * the accessor lists the property as additional property, and the spec validates it as one.
     */
    #[DataProvider('nonRootPropertyProvider')]
    public function testNonRootPropertyIsSettableThroughTheAdditionalPropertiesAccessor(
        string $declaration,
        array $requiredData,
        array $additionalRequiredData,
    ): void {
        $this->assertNonRootPropertyIsSettable(
            $this->generateNonRootPropertyClass($declaration),
            $requiredData,
            $additionalRequiredData,
        );
    }

    /**
     * A key removed through the accessor is no longer present in the model: the typed property doesn't keep the
     * removed value.
     */
    #[DataProvider('optionalNonRootPropertyProvider')]
    public function testRemovingAnOptionalNonRootPropertyThroughTheAdditionalPropertiesAccessorClearsTheProperty(
        string $declaration,
        array $requiredData,
        array $additionalRequiredData,
    ): void {
        $this->assertNonRootPropertyIsRemovable(
            $this->generateNonRootPropertyClass($declaration),
            $requiredData,
            $additionalRequiredData,
        );
    }

    /**
     * Removing a required key is rejected like the constructor rejects the remaining data, and leaves the model
     * unchanged.
     */
    #[DataProvider('requiredNonRootPropertyProvider')]
    public function testRemovingARequiredNonRootPropertyIsRejectedLikeTheConstructor(
        string $declaration,
        array $requiredData,
        array $additionalRequiredData,
    ): void {
        $className = $this->generateNonRootPropertyClass($declaration);
        $object = new $className($requiredData + ['foo' => 'ab']);

        $expectedException = $this->catchException(static fn() => new $className($requiredData));
        $this->assertNotNull($expectedException, 'The constructor must reject the remaining data');
        $this->assertSame("Missing required value for 'foo'", $expectedException->getMessage());

        $actualException = $this->catchException(static fn() => $object->additionalProperties()->remove('foo'));
        $this->assertNotNull($actualException, 'The removal must be rejected');
        $this->assertSame($expectedException::class, $actualException::class);
        $this->assertSame($expectedException->getMessage(), $actualException->getMessage());

        $this->assertSame('ab', $object->getFoo());
        $this->assertSame($additionalRequiredData + ['foo' => 'ab'], $object->additionalProperties()->getAll());
        $this->assertSame($requiredData + ['foo' => 'ab'], $object->meta()->rawInput());
    }

    /**
     * The properties of a schema referenced on base level are evaluated by the referenced schema, not by the
     * 'properties' keyword next to the root additionalProperties keyword. Draft 7 ignores the keywords next to a $ref.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testPropertyOfABaseLevelReferenceIsSettableThroughTheAdditionalPropertiesAccessor(): void
    {
        $this->assertNonRootPropertyIsSettable(
            $this->generateNonRootPropertyClass(self::BASE_LEVEL_REFERENCE_DECLARATION),
            [],
            [],
        );
    }

    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testRemovingAPropertyOfABaseLevelReferenceThroughTheAccessorClearsTheProperty(): void
    {
        $this->assertNonRootPropertyIsRemovable(
            $this->generateNonRootPropertyClass(self::BASE_LEVEL_REFERENCE_DECLARATION),
            [],
            [],
        );
    }

    public static function nonRootPropertyWithDefaultProvider(): array
    {
        return [
            // the composition applies the default of the allOf branch while it validates the remaining data
            'allOf branch' => ['"allOf": [{"properties": {"foo": {"type": "string", "default": "df"}}}]', []],
            'anyOf branch' => ['"anyOf": [{"properties": {"foo": {"type": "string", "default": "df"}}}]', []],
            'schema dependency' => [
                '"properties": {"a": {"type": "string"}}, '
                    . '"dependencies": {"a": {"properties": {"foo": {"type": "string", "default": "df"}}}}',
                ['a' => 'x'],
            ],
        ];
    }

    /**
     * A removed key is absent like in a model constructed without it: the typed property holds its default, not null
     * and not the removed value.
     */
    #[DataProvider('nonRootPropertyWithDefaultProvider')]
    public function testRemovingANonRootPropertyWithADefaultResetsThePropertyToTheDefault(
        string $declaration,
        array $requiredData,
    ): void {
        $className = $this->generateNonRootPropertyClass($declaration);

        $this->assertSame('df', (new $className($requiredData))->getFoo());

        $object = new $className($requiredData + ['foo' => 'ab']);
        $this->assertSame('ab', $object->getFoo());

        $this->assertTrue($object->additionalProperties()->remove('foo'));
        $this->assertSame('df', $object->getFoo());
        $this->assertSame([], $object->additionalProperties()->getAll());
        $this->assertSame($requiredData, $object->meta()->rawInput());
    }

    public static function readOnlyNonRootPropertyProvider(): array
    {
        return [
            'allOf branch' => ['"allOf": [{"properties": {"foo": {"type": "string", "readOnly": true}}}]', []],
            'schema dependency' => [
                '"properties": {"a": {"type": "string"}}, '
                    . '"dependencies": {"a": {"properties": {"foo": {"type": "string", "readOnly": true}}}}',
                ['a' => 'x'],
            ],
        ];
    }

    /**
     * A readOnly property has no setter. The accessor would bypass readOnly if it set or removed the property, so it
     * refuses both and leaves the model unchanged.
     */
    #[DataProvider('readOnlyNonRootPropertyProvider')]
    public function testReadOnlyNonRootPropertyIsRefusedByTheAccessor(string $declaration, array $requiredData): void
    {
        $className = $this->generateNonRootPropertyClass($declaration);
        $object = new $className($requiredData + ['foo' => 'ab']);
        $accessor = $object->additionalProperties();

        $exception = $this->catchException(static fn() => $accessor->set('foo', 'zz'));
        $this->assertInstanceOf(RegularPropertyAsAdditionalPropertyException::class, $exception);
        $this->assertSame(
            "Could not add regular property 'foo' as an additional property of object '$className'",
            $exception->getMessage(),
        );

        $exception = $this->catchException(static fn() => $accessor->remove('foo'));
        $this->assertInstanceOf(RegularPropertyAsAdditionalPropertyException::class, $exception);

        $this->assertSame('ab', $object->getFoo());
        $this->assertSame($requiredData + ['foo' => 'ab'], $object->meta()->rawInput());
    }

    /**
     * The setter hooks of the typed property fire when the accessor sets the property (like populate() fires them
     * for an updated property), the before hook also for a rejected update. Removing a key fires none, like removing
     * any other additional property.
     */
    public function testSetterHooksOfANonRootPropertyFireWhenSettingThroughTheAccessor(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $modelGenerator): void {
            $modelGenerator
                ->addPostProcessor(new AdditionalPropertiesAccessorPostProcessor(true))
                ->addPostProcessor(new class () extends PostProcessor {
                    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
                    {
                        $schema->addSchemaHook(new class () implements SetterBeforeValidationHookInterface {
                            public function getCode(PropertyInterface $property, bool $batchUpdate = false): string
                            {
                                return AdditionalPropertiesAccessorPostProcessorTest::hookLogCode(
                                    'before',
                                    $property,
                                );
                            }
                        });
                        $schema->addSchemaHook(new class () implements SetterAfterValidationHookInterface {
                            public function getCode(PropertyInterface $property, bool $batchUpdate = false): string
                            {
                                return AdditionalPropertiesAccessorPostProcessorTest::hookLogCode(
                                    'after',
                                    $property,
                                );
                            }
                        });
                    }
                });
        };

        $className = $this->generateClassFromFileTemplate(
            'NonRootProperty.json',
            ['"allOf": [{"properties": {"foo": {"type": "string"}}}]', '{"maxLength": 3}'],
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
            false,
        );

        $object = new $className(['foo' => 'ab']);
        $accessor = $object->additionalProperties();
        self::$hookLog = [];

        $accessor->set('foo', 'zz');
        $this->assertSame([['before', 'foo'], ['after', 'foo']], self::loggedHooksOf('foo'));

        self::$hookLog = [];
        $this->catchException(static fn() => $accessor->set('foo', 'abcd'));
        $this->assertSame([['before', 'foo']], self::loggedHooksOf('foo'));

        self::$hookLog = [];
        $accessor->remove('foo');
        $this->assertSame([], self::loggedHooksOf('foo'));
    }

    /**
     * The code of a setter hook which appends [phase, property name] to the log of the test class
     */
    public static function hookLogCode(string $phase, PropertyInterface $property): string
    {
        return sprintf(
            '%s::$hookLog[] = [%s, %s];',
            '\\' . self::class,
            var_export($phase, true),
            var_export($property->getName(), true),
        );
    }

    private static function loggedHooksOf(string $propertyName): array
    {
        return array_values(array_filter(
            self::$hookLog,
            static fn(array $entry): bool => $entry[1] === $propertyName,
        ));
    }

    private function assertNonRootPropertyIsRemovable(
        string $className,
        array $requiredData,
        array $additionalRequiredData,
    ): void {
        $object = new $className($requiredData + ['foo' => 'ab']);

        $this->assertTrue($object->additionalProperties()->remove('foo'));
        $this->assertNull($object->getFoo());
        $this->assertSame($additionalRequiredData, $object->additionalProperties()->getAll());
        $this->assertSame($requiredData, $object->meta()->rawInput());

        // the model without the removed key is valid data for the constructor
        $this->assertNull((new $className($object->meta()->rawInput()))->getFoo());
    }

    private function generateNonRootPropertyClass(string $declaration): string
    {
        $this->addPostProcessor(true);

        return $this->generateClassFromFileTemplate(
            'NonRootProperty.json',
            [$declaration, '{"maxLength": 3}'],
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
            false,
        );
    }

    /**
     * Sets 'foo' through the typed setter and through the accessor, the latter both valid and invalid
     */
    private function assertNonRootPropertyIsSettable(
        string $className,
        array $requiredData,
        array $additionalRequiredData,
    ): void {
        $object = new $className($requiredData + ['foo' => 'ab']);
        $accessor = $object->additionalProperties();

        // the typed setter updates the stored additional property
        $object->setFoo('xy');
        $this->assertSame($additionalRequiredData + ['foo' => 'xy'], $accessor->getAll());

        // the accessor updates the typed property
        $accessor->set('foo', 'zz');
        $this->assertSame('zz', $object->getFoo());
        $this->assertSame($additionalRequiredData + ['foo' => 'zz'], $accessor->getAll());
        $this->assertSame($requiredData + ['foo' => 'zz'], $object->meta()->rawInput());

        // a value violating the additionalProperties keyword is rejected and leaves the model unchanged
        $exception = $this->catchException(static fn() => $accessor->set('foo', 'abcd'));
        $this->assertInstanceOf(InvalidAdditionalPropertiesException::class, $exception);
        $this->assertSame(
            <<<MSG
            Provided JSON for '{$className}' contains invalid additional properties
              - invalid additional property 'foo'
                * Value for 'additional property' must not be longer than 3
            MSG,
            $exception->getMessage(),
        );

        $this->assertSame('zz', $object->getFoo());
        $this->assertSame($additionalRequiredData + ['foo' => 'zz'], $accessor->getAll());
        $this->assertSame($requiredData + ['foo' => 'zz'], $object->meta()->rawInput());
    }

    public function testSetterSchemaHooksAreResolvedInSetAdditionalProperties(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $modelGenerator): void {
            $modelGenerator
                ->addPostProcessor(new AdditionalPropertiesAccessorPostProcessor())
                ->addPostProcessor(new class () extends PostProcessor {
                    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
                    {
                        $schema->addSchemaHook(new class () implements SetterBeforeValidationHookInterface {
                            public function getCode(PropertyInterface $property, bool $batchUpdate = false): string
                            {
                                return 'throw new \Exception("SetterBeforeValidationHook");';
                            }
                        });
                    }
                });
        };

        $className = $this->generateClassFromFile(
            'AdditionalProperties.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['property1' => 'Hello', 'property2' => 'World']);
        $accessor = $object->additionalProperties();
        $accessor->set('property1', 'Hello');
        $this->assertSame('Hello', $accessor->get('property1'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('SetterBeforeValidationHook');
        $accessor->set('property1', 'Goodbye');
    }

    #[DataProvider('implicitNullDataProvider')]
    public function testAdditionalPropertiesAreSerialized(bool $implicitNull): void
    {
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesTransformingFilter.json',
            (new GeneratorConfiguration())->setSerialization(true)->setImmutable(false),
            false,
            $implicitNull,
        );

        $object = new $className(['name' => 'Late autumn', 'start' => '2020-10-10']);
        $accessor = $object->additionalProperties();

        $this->assertInstanceOf(DateTime::class, $accessor->get('start'));
        $this->assertInstanceOf(DateTime::class, $accessor->getAll()['start']);

        $accessor->set('end', '2020-12-12');
        $this->assertInstanceOf(DateTime::class, $accessor->get('end'));
        $this->assertInstanceOf(DateTime::class, $accessor->getAll()['end']);

        $this->assertEqualsCanonicalizing(
            ['name' => 'Late autumn', 'start' => '20201010', 'end' => '20201212'],
            $object->toArray(),
        );

        // test adding a transformed value
        $accessor->set('now', new DateTime());
        $this->assertInstanceOf(DateTime::class, $accessor->get('now'));

        // test typing on the companion class methods
        $this->assertSame('(DateTime|null)[]', $this->getReturnTypeAnnotation($accessor, 'getAll'));
        $returnType = $this->getReturnType($accessor, 'getAll');
        $this->assertSame('array', $returnType->getName());
        $this->assertFalse($returnType->allowsNull());

        $this->assertSame('DateTime|null', $this->getReturnTypeAnnotation($accessor, 'get'));
        $returnType = $this->getReturnType($accessor, 'get');
        $this->assertSame('DateTime', $returnType->getName());
        $this->assertTrue($returnType->allowsNull());

        $this->assertSame(
            'string|DateTime|null',
            $this->getParameterTypeAnnotation($accessor, 'set', 1),
        );
        $this->assertEqualsCanonicalizing(
            ['string', 'DateTime', 'null'],
            $this->getParameterTypeNames($accessor, 'set', 1),
        );
    }

    public function testAdditionalPropertiesAreSerializedWithoutAdditionalPropertiesAccessorPostProcessor(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesTransformingFilter.json',
            (new GeneratorConfiguration())->setSerialization(true),
        );

        $object = new $className(['name' => 'Late autumn', 'start' => '2020-10-10']);
        $this->assertEqualsCanonicalizing(['name' => 'Late autumn', 'start' => '20201010'], $object->toArray());

        $object->populate(['end' => '20201212']);
        $this->assertEqualsCanonicalizing(
            ['name' => 'Late autumn', 'start' => '20201010', 'end' => '20201212'],
            $object->toArray(),
        );
    }

    public function testAdditionalPropertiesAreNotSerializedWhenNotDefinedWithoutExplicitAccessorMethods(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesNotDefined.json',
            (new GeneratorConfiguration())->setSerialization(true),
        );

        $object = new $className(['a' => 1, 'b' => 2]);
        $this->assertSame([], $object->toArray());

        $object->populate(['a' => 3, 'c' => 4]);
        $this->assertSame([], $object->toArray());
    }

    public function testAdditionalPropertiesAreSerializedWhenNotDefinedWithExplicitAccessorMethods(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator
                ->addPostProcessor(new PopulatePostProcessor())
                ->addPostProcessor(new AdditionalPropertiesAccessorPostProcessor(true));
        };

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesNotDefined.json',
            (new GeneratorConfiguration())->setSerialization(true),
        );

        $object = new $className(['a' => 1, 'b' => 2]);
        $this->assertEqualsCanonicalizing(['a' => 1, 'b' => 2], $object->toArray());

        $object->populate(['a' => 3, 'c' => 4]);
        $this->assertEqualsCanonicalizing(['a' => 3, 'b' => 2, 'c' => 4], $object->toArray());
    }

    public function testMultiTypeAdditionalProperties(): void
    {
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesMultiType.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className(['property1' => 'Hello', 'property2' => null]);
        $accessor = $object->additionalProperties();

        $this->assertSame('Hello', $accessor->get('property1'));
        $this->assertNull($accessor->get('property2'));

        $accessor->set('property1', null);
        $this->assertNull($accessor->get('property1'));
        $accessor->set('property1', 5);
        $this->assertSame(5, $accessor->get('property1'));
        $accessor->set('property1', 'Goodbye');
        $this->assertSame('Goodbye', $accessor->get('property1'));

        // test typing on the companion class methods
        $this->assertSame(
            '(string|int|null)[]',
            $this->getReturnTypeAnnotation($accessor, 'getAll'),
        );
        $returnType = $this->getReturnType($accessor, 'getAll');
        $this->assertSame('array', $returnType->getName());
        $this->assertFalse($returnType->allowsNull());

        $this->assertSame(
            'string|int|null',
            $this->getReturnTypeAnnotation($accessor, 'get'),
        );
        $this->assertEqualsCanonicalizing(
            ['string', 'int', 'null'],
            $this->getReturnTypeNames($accessor, 'get'),
        );

        $this->assertSame(
            'string|int|null',
            $this->getParameterTypeAnnotation($accessor, 'set', 1),
        );
        $this->assertEqualsCanonicalizing(
            ['string', 'int', 'null'],
            $this->getParameterTypeNames($accessor, 'set', 1),
        );
    }

    public function testComposedAdditionalProperties(): void
    {
        $this->addPostProcessor(true);

        $className = $this->generateClassFromFile(
            'AdditionalPropertiesComposition.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['property1' => 'Hello', 'property2' => 12345]);
        $accessor = $object->additionalProperties();

        $this->assertSame('Hello', $accessor->get('property1'));
        $this->assertSame(12345, $accessor->get('property2'));

        $accessor->set('property1', 5);
        $this->assertSame(5, $accessor->get('property1'));
        $accessor->set('property1', 'Goodbye');
        $this->assertSame('Goodbye', $accessor->get('property1'));

        // test typing on the companion class methods
        $this->assertSame(
            '(string|int)[]',
            $this->getReturnTypeAnnotation($accessor, 'getAll'),
        );
        $returnType = $this->getReturnType($accessor, 'getAll');
        $this->assertSame('array', $returnType->getName());
        $this->assertFalse($returnType->allowsNull());

        $this->assertSame(
            'string|int|null',
            $this->getReturnTypeAnnotation($accessor, 'get'),
        );
        $this->assertEqualsCanonicalizing(
            ['string', 'int', 'null'],
            $this->getReturnTypeNames($accessor, 'get'),
        );

        $this->assertSame(
            'string|int',
            $this->getParameterTypeAnnotation($accessor, 'set', 1),
        );
        $this->assertEqualsCanonicalizing(
            ['string', 'int'],
            $this->getParameterTypeNames($accessor, 'set', 1),
        );
    }
}
