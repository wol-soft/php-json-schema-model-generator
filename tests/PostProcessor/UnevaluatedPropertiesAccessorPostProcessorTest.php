<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\PostProcessor;

use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Exception\Object\RegularPropertyAsUnevaluatedPropertyException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\AdditionalPropertiesAccessorPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\UnevaluatedPropertiesAccessorPostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Exercises UnevaluatedPropertiesAccessorPostProcessor: the unevaluatedProperties() accessor
 * method, its companion-class-typed get/set/getAll path, the runtime guards inside
 * _setUnevaluatedProperty, and the emission policy that suppresses the accessor when
 * unevaluatedProperties cannot reach any key (additionalProperties: false / {schema}).
 */
#[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
class UnevaluatedPropertiesAccessorPostProcessorTest extends AbstractPHPModelGeneratorTestCase
{
    private function addPostProcessor(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new UnevaluatedPropertiesAccessorPostProcessor());
        };
    }

    /**
     * The typed accessor companion narrows get/set/getAll to the declared schema type.
     * Construction collects matching extras into the backing field; getAll() returns them;
     * get() returns one; immutable mode omits the mutators.
     */
    public function testTypedAccessorRoundTripsCollectedExtras(): void
    {
        $this->addPostProcessor();

        $className = $this->generateClassFromFile(
            'TypedExtras.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        // Construction collects matching extras into _unevaluatedProperties.
        $object = new $className(['name' => 'Alice', 'count' => 42, 'limit' => 7]);
        $accessor = $object->unevaluatedProperties();

        $this->assertSame(['count' => 42, 'limit' => 7], $accessor->getAll());
        $this->assertSame(42, $accessor->get('count'));
        $this->assertSame(7, $accessor->get('limit'));
        $this->assertNull($accessor->get('missing'));

        // Mutator path: set() routes through _setUnevaluatedProperty which runs validation +
        // stores the value, and remove() takes the key out of both _unevaluatedProperties and
        // _rawModelDataInput.
        $accessor->set('extra', 99);
        $this->assertSame(99, $accessor->get('extra'));
        $this->assertSame(
            ['name' => 'Alice', 'count' => 42, 'limit' => 7, 'extra' => 99],
            $object->meta()->rawInput(),
        );

        $this->assertTrue($accessor->remove('extra'));
        $this->assertNull($accessor->get('extra'));
        $this->assertFalse($accessor->remove('does-not-exist'));

        // Same accessor instance is reused across calls (it's cached on the model).
        $this->assertSame($accessor, $object->unevaluatedProperties());
    }

    /**
     * The unevaluatedProperties subschema's runtime validators run on the shim and reject
     * values that satisfy the PHP type signature but fail a constraint expressed only in
     * the JSON Schema (here: maximum). The accessor's _unevaluatedProperties backing array
     * and the raw model input must be untouched after a rejected set().
     */
    public function testSetRejectsValuesViolatingNonTypeConstraints(): void
    {
        $this->addPostProcessor();
        // Direct-exception mode (not error-collection) so the inner exception type is exact.
        $className = $this->generateClassFromFile(
            'TypedExtrasWithRange.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className(['name' => 'Alice', 'count' => 1]);
        $accessor = $object->unevaluatedProperties();

        try {
            // 999 satisfies the PHP `int` signature but violates `maximum: 100` in the schema.
            $accessor->set('bad', 999);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $exception) {
            // Direct-exception mode: the unevaluated subschema's maximum validator fires first
            // and the exception bubbles up with a message identifying the offending property.
            $this->assertStringContainsString('unevaluated property', $exception->getMessage());
        }

        // Backing field unchanged after rollback inside the unevaluatedProperties template.
        $this->assertSame(['count' => 1], $accessor->getAll());
        // Raw input did not record the partial mutation either.
        $this->assertSame(['name' => 'Alice', 'count' => 1], $object->meta()->rawInput());
    }

    /**
     * Keys that match a declared property name must be routed through the named setter and
     * not the unevaluated accessor. The shim's runtime guard rejects them with a dedicated
     * exception.
     */
    public function testSetUnevaluatedPropertyRejectsDeclaredPropertyKey(): void
    {
        $this->addPostProcessor();
        $className = $this->generateClassFromFile(
            'TypedExtras.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['name' => 'Alice']);

        $this->expectException(RegularPropertyAsUnevaluatedPropertyException::class);
        $object->unevaluatedProperties()->set('name', 42);
    }

    /**
     * The typed companion is a stand-alone class generated next to the model — it mirrors the
     * production-library accessor's shape with narrowed signatures but does not extend it.
     * (Matches the pattern used by AdditionalPropertiesAccessorPostProcessor.) Its short name
     * follows {ModelName}UnevaluatedProperties.
     */
    public function testCompanionClassNamingFollowsModelClassName(): void
    {
        $this->addPostProcessor();
        $className = $this->generateClassFromFile(
            'TypedExtras.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className();
        $accessor = $object->unevaluatedProperties();

        $reflection = new \ReflectionClass($accessor);
        $this->assertSame($className . 'UnevaluatedProperties', $reflection->getName());
        $this->assertTrue($reflection->hasMethod('get'));
        $this->assertTrue($reflection->hasMethod('set'));
        $this->assertTrue($reflection->hasMethod('getAll'));
        $this->assertTrue($reflection->hasMethod('remove'));
    }

    /**
     * Immutable models receive only the read side of the accessor. When the schema is typed,
     * the companion class is still emitted but omits the mutator methods entirely.
     */
    public function testImmutableAccessorOmitsMutators(): void
    {
        $this->addPostProcessor();
        $className = $this->generateClassFromFile(
            'TypedExtras.json',
            (new GeneratorConfiguration())->setImmutable(true),
        );

        $object = new $className(['name' => 'Alice', 'count' => 5]);
        $accessor = $object->unevaluatedProperties();

        $this->assertSame(5, $accessor->get('count'));
        $this->assertSame(['count' => 5], $accessor->getAll());

        // Immutable companion has no set/remove methods at all.
        $this->assertFalse(method_exists($accessor, 'set'));
        $this->assertFalse(method_exists($accessor, 'remove'));
    }

    /**
     * Composition branches contribute their declared `properties` keys to the evaluated set
     * at runtime. The shim must reject those keys for the same reason it rejects locally-
     * declared ones: routing them through the unevaluated accessor would bypass the branch's
     * own type validation and silently store a value that belongs to the branch's contract.
     */
    public function testSetRejectsKeysDeclaredInCompositionBranch(): void
    {
        $this->addPostProcessor();
        $className = $this->generateClassFromFile(
            'AllOfBranchOwnsProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className();

        $this->expectException(RegularPropertyAsUnevaluatedPropertyException::class);
        // `branchOwned` is declared only inside the allOf branch, not on the outer schema.
        // The accumulator would normally credit it to the branch — setting it via the
        // unevaluated accessor must still throw.
        $object->unevaluatedProperties()->set('branchOwned', 42);
    }

    /**
     * Collect-errors mode: a failed set() leaves the error registry populated. The shim's
     * registry reset isolates each setter call so a caller that catches the failure can
     * continue using the model — without the reset, every subsequent setter would throw
     * stale errors from a prior call instead of the actual current-call errors.
     */
    public function testSetterInCollectErrorsModeIsolatesEachCall(): void
    {
        $this->addPostProcessor();
        $className = $this->generateClassFromFile(
            'TypedExtrasWithRange.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(true),
        );

        $object = new $className(['name' => 'Alice']);
        $accessor = $object->unevaluatedProperties();

        try {
            $accessor->set('bad', 999); // exceeds maximum: 100
            $this->fail('First set() was expected to throw');
        } catch (\PHPModelGenerator\Exception\ErrorRegistryException) {
            // expected — collect-errors mode wraps validation failures in this exception
        }

        // The second call's value is valid — it must succeed even though the first call's
        // errors are still stored on the registry field.
        $accessor->set('ok', 50);
        $this->assertSame(50, $accessor->get('ok'));
    }

    /**
     * Serialization round-trip: the _unevaluatedProperties backing field is flattened back
     * into toArray()/toJSON() output by SerializableTrait::_serializeUnevaluatedProperties.
     * Keys claimed at construction time and keys added via set() must both appear in the
     * serialized representation.
     */
    public function testSerializationFlattensUnevaluatedPropertiesIntoOutput(): void
    {
        $this->addPostProcessor();
        $className = $this->generateClassFromFile(
            'TypedExtras.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setSerialization(true),
        );

        $object = new $className(['name' => 'Alice', 'count' => 1, 'limit' => 7]);
        $object->unevaluatedProperties()->set('extra', 99);

        $this->assertEqualsCanonicalizing(
            ['name' => 'Alice', 'count' => 1, 'limit' => 7, 'extra' => 99],
            $object->toArray(),
        );

        $decoded = json_decode($object->toJSON(), true);
        $this->assertEqualsCanonicalizing(
            ['name' => 'Alice', 'count' => 1, 'limit' => 7, 'extra' => 99],
            $decoded,
        );
    }

    /**
     * `unevaluatedProperties: false` produces a NoUnevaluatedPropertiesValidator with no
     * backing storage to expose, so the accessor method must not be emitted at all.
     */
    public function testUnevaluatedFalseSuppressesAccessorEmission(): void
    {
        $this->addPostProcessor();
        $className = $this->generateClassFromFile('UnevaluatedFalse.json');

        $object = new $className(['name' => 'Alice']);

        $this->assertFalse(method_exists($object, 'unevaluatedProperties'));
    }

    /**
     * Configures both accessor post processors and the serialization post processor on the
     * generator so the same code path the user would hit when enabling both accessors
     * simultaneously is exercised.
     */
    private function addBothAccessorPostProcessors(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new AdditionalPropertiesAccessorPostProcessor());
            $generator->addPostProcessor(new UnevaluatedPropertiesAccessorPostProcessor());
        };
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     *           [schemaFile, expectAdditionalAccessor, expectUnevaluatedAccessor]
     */
    public static function bothAccessorsEmissionMatrix(): array
    {
        return [
            // additionalProperties absent, unevaluatedProperties is a typed schema —
            // unevaluated owns the bucket; additional accessor needs an explicit opt-in for
            // schemas without additionalProperties so it is NOT emitted by default.
            'additional absent, unevaluated typed' => [
                'TypedExtras.json',
                false,
                true,
            ],
            // additionalProperties: true is treated as "claim every extra without validation"
            // → additional accessor emits; unevaluated has nothing left to claim and is skipped
            // at the factory level (no UnevaluatedPropertiesValidator emitted).
            'additional true, unevaluated typed' => [
                'AdditionalTrueWithUnevaluatedSchema.json',
                true,
                false,
            ],
            // additionalProperties: {schema} validates and claims every extra; unevaluated
            // suppressed for the same reason as the true case.
            'additional typed schema, unevaluated typed' => [
                'AdditionalSchemaWithUnevaluatedSchema.json',
                true,
                false,
            ],
            // additionalProperties: false rejects every extra at validation time — neither
            // accessor has a bucket to expose; both are suppressed.
            'additional false, unevaluated typed' => [
                'AdditionalFalseWithUnevaluatedSchema.json',
                false,
                false,
            ],
        ];
    }

    /**
     * When both accessor post processors are configured at the same time, each one's emission
     * policy must independently respect the schema shape. The expected combination depends on
     * which keyword owns the bucket of extras.
     */
    #[DataProvider('bothAccessorsEmissionMatrix')]
    public function testCoexistenceEmissionPolicy(
        string $schemaFile,
        bool $expectAdditionalAccessor,
        bool $expectUnevaluatedAccessor,
    ): void {
        $this->addBothAccessorPostProcessors();
        $className = $this->generateClassFromFile($schemaFile);

        $this->assertSame(
            $expectAdditionalAccessor,
            method_exists($className, 'additionalProperties'),
            'additionalProperties() accessor emission did not match the expected shape',
        );
        $this->assertSame(
            $expectUnevaluatedAccessor,
            method_exists($className, 'unevaluatedProperties'),
            'unevaluatedProperties() accessor emission did not match the expected shape',
        );
    }

    /**
     * Runtime coexistence: when additionalProperties is configured AND a typed unevaluated
     * schema is declared, additionalProperties wins at the factory level — extras land in
     * _additionalProperties via the additional accessor, never in _unevaluatedProperties.
     * Both serializations round-trip through `toArray()` correctly.
     */
    public function testAdditionalTrueClaimsExtrasAndUnevaluatedBucketRemainsEmpty(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new AdditionalPropertiesAccessorPostProcessor());
            $generator->addPostProcessor(new UnevaluatedPropertiesAccessorPostProcessor());
        };

        $className = $this->generateClassFromFile(
            'AdditionalTrueWithUnevaluatedSchema.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['name' => 'Alice', 'extra' => 'hello']);

        $additionalAccessor = $object->additionalProperties();
        $this->assertSame(['extra' => 'hello'], $additionalAccessor->getAll());

        // Confirm the unevaluatedProperties accessor was not emitted (additionalProperties: true
        // suppresses it because additional claims every extra at runtime).
        $this->assertFalse(method_exists($object, 'unevaluatedProperties'));

        // Serialization flattens the additional bucket back into the output.
        $this->assertEqualsCanonicalizing(
            ['name' => 'Alice', 'extra' => 'hello'],
            $object->toArray(),
        );
    }

    /**
     * Runtime coexistence: additionalProperties: false rejects every extra at construction
     * time even though the user enabled both accessor post processors. The unevaluated
     * accessor is suppressed at codegen because its bucket would be unreachable.
     */
    public function testAdditionalFalseRejectsExtrasAndSuppressesUnevaluatedAccessor(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new AdditionalPropertiesAccessorPostProcessor());
            $generator->addPostProcessor(new UnevaluatedPropertiesAccessorPostProcessor());
        };

        $className = $this->generateClassFromFile(
            'AdditionalFalseWithUnevaluatedSchema.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        // Constructing with no extras is fine.
        $object = new $className(['name' => 'Alice']);
        $this->assertFalse(method_exists($object, 'additionalProperties'));
        $this->assertFalse(method_exists($object, 'unevaluatedProperties'));

        // Constructing with an extra throws on the additionalProperties: false validator.
        $this->expectException(\PHPModelGenerator\Exception\ValidationException::class);
        new $className(['name' => 'Alice', 'extra' => 42]);
    }
}
