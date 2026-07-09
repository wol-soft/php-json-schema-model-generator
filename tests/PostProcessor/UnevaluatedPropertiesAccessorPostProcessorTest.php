<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\PostProcessor;

use DateTime;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Object\RegularPropertyAsUnevaluatedPropertyException;
use PHPModelGenerator\Exception\Object\UnevaluatedPropertiesException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\AdditionalPropertiesAccessorPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PatternPropertiesAccessorPostProcessor;
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

        try {
            $object->unevaluatedProperties()->set('name', 42);
            $this->fail('Expected RegularPropertyAsUnevaluatedPropertyException');
        } catch (RegularPropertyAsUnevaluatedPropertyException $exception) {
            $this->assertSame(
                "Couldn't add regular property name as unevaluated property to object {$className}",
                $exception->getMessage(),
            );
            // `name` is declared directly in `properties`, so the pointer identifies the
            // property's declaration site — resolved via the property's #[JsonPointer]
            // attribute rather than recomputed from schema paths.
            $this->assertSame('/properties/name', $exception->getJsonPointer()->pointer);
        }
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
     * A non-transforming filter (here `trim`) leaves the declared PHP type untouched — the
     * companion's get/getAll/set signatures must remain the schema-declared `string` rather
     * than widening to include the filter's raw input type. The filter still runs at both
     * construction time (extras collected into the backing field) and mutator time (set()
     * routes through the shim which re-invokes the filter).
     */
    public function testNonTransformingFilterKeepsCompanionTypesAtSchemaType(): void
    {
        $this->addPostProcessor();

        $className = $this->generateClassFromFile(
            'TypedExtrasWithFilter.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        // Construction collects the extras and runs `trim` on each value before storing them.
        $object = new $className(['name' => 'Alice', 'greeting' => '  hello  ']);
        $accessor = $object->unevaluatedProperties();

        $this->assertSame('hello', $accessor->get('greeting'));
        $this->assertSame(['greeting' => 'hello'], $accessor->getAll());

        // set() routes through the shim, filter runs again on the new value.
        $accessor->set('farewell', '  bye  ');
        $this->assertSame('bye', $accessor->get('farewell'));

        // Companion signatures — non-transforming filter must not widen the types.
        $this->assertSame('string[]', $this->getReturnTypeAnnotation($accessor, 'getAll'));
        $getAllReturn = $this->getReturnType($accessor, 'getAll');
        $this->assertSame('array', $getAllReturn->getName());
        $this->assertFalse($getAllReturn->allowsNull());

        $this->assertSame('string|null', $this->getReturnTypeAnnotation($accessor, 'get'));
        $getReturn = $this->getReturnType($accessor, 'get');
        $this->assertSame('string', $getReturn->getName());
        $this->assertTrue($getReturn->allowsNull());

        $this->assertSame('string', $this->getParameterTypeAnnotation($accessor, 'set', 1));
        $this->assertSame(['string'], $this->getParameterTypeNames($accessor, 'set', 1));
    }

    /**
     * A transforming filter (here `dateTime`) turns the raw input string into a DateTime
     * instance at collection time. The companion's get/getAll must therefore return the
     * *transformed* type, and set() must accept either the raw input type OR the transformed
     * type — mirroring the AdditionalPropertiesAccessorPostProcessor contract so a caller can
     * pass a DateTime directly instead of round-tripping through a formatted string. The full
     * round-trip serializes back to the raw format the filter's outputFormat declared.
     */
    public function testTransformingFilterNarrowsCompanionToTransformedType(): void
    {
        $this->addPostProcessor();

        $className = $this->generateClassFromFile(
            'TypedExtrasWithTransformingFilter.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setSerialization(true),
        );

        $object = new $className(['name' => 'Late autumn', 'start' => '2020-10-10']);
        $accessor = $object->unevaluatedProperties();

        // Extras collected at construction time are transformed to DateTime instances.
        $this->assertInstanceOf(DateTime::class, $accessor->get('start'));
        $this->assertInstanceOf(DateTime::class, $accessor->getAll()['start']);

        // set() with a raw string routes through the filter and stores a DateTime.
        $accessor->set('end', '2020-12-12');
        $this->assertInstanceOf(DateTime::class, $accessor->get('end'));

        // set() with an already-transformed value must also be accepted — the type-check on the
        // unevaluated subschema's synthetic property is pass-through-wired around the
        // transforming filter, so DateTime bypasses the pre-transform `is_string` guard.
        $accessor->set('now', new DateTime());
        $this->assertInstanceOf(DateTime::class, $accessor->get('now'));

        // Serialization applies the filter's outputFormat and turns each DateTime back into
        // the raw representation the filter accepts.
        $this->assertEqualsCanonicalizing(
            ['name' => 'Late autumn', 'start' => '20201010', 'end' => '20201212'],
            array_intersect_key(
                $object->toArray(),
                ['name' => true, 'start' => true, 'end' => true],
            ),
        );

        // Companion signatures — transforming filter must widen types on both read and write.
        $this->assertSame('(DateTime|null)[]', $this->getReturnTypeAnnotation($accessor, 'getAll'));
        $getAllReturn = $this->getReturnType($accessor, 'getAll');
        $this->assertSame('array', $getAllReturn->getName());
        $this->assertFalse($getAllReturn->allowsNull());

        $this->assertSame('DateTime|null', $this->getReturnTypeAnnotation($accessor, 'get'));
        $getReturn = $this->getReturnType($accessor, 'get');
        $this->assertSame('DateTime', $getReturn->getName());
        $this->assertTrue($getReturn->allowsNull());

        $this->assertSame(
            'string|DateTime|null',
            $this->getParameterTypeAnnotation($accessor, 'set', 1),
        );
        $this->assertEqualsCanonicalizing(
            ['string', 'DateTime', 'null'],
            $this->getParameterTypeNames($accessor, 'set', 1),
        );
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

        try {
            // `branchOwned` is declared only inside the allOf branch, not on the outer schema.
            // The accumulator would normally credit it to the branch — setting it via the
            // unevaluated accessor must still throw.
            $object->unevaluatedProperties()->set('branchOwned', 42);
            $this->fail('Expected RegularPropertyAsUnevaluatedPropertyException');
        } catch (RegularPropertyAsUnevaluatedPropertyException $exception) {
            $this->assertSame(
                "Couldn't add regular property branchOwned as unevaluated property to object {$className}",
                $exception->getMessage(),
            );
            // The composition-branch harvest resolves the pointer to the declaration site
            // *inside* the allOf branch (index 0), proving the harvest walks branch schemas
            // rather than just recording the property name.
            $this->assertSame('/allOf/0/properties/branchOwned', $exception->getJsonPointer()->pointer);
        }
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
     * Schema without `additionalProperties` + outer `unevaluatedProperties: false`. The user
     * opts the additionalProperties accessor in via `addForModelsWithoutAdditionalPropertiesDefinition`
     * and tries to `$model->additionalProperties()->set(...)` a key no sibling claims. The
     * shim's cross-state revalidation runs the post-composition phase against a candidate
     * raw input view; the enclosing unevaluatedProperties: false rejects the new key, and
     * `_additionalProperties` rolls back to its pre-call state.
     */
    public function testAdditionalAccessorSetIsRejectedWhenUnevaluatedFalseOrphansTheKey(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(
                new AdditionalPropertiesAccessorPostProcessor(
                    addForModelsWithoutAdditionalPropertiesDefinition: true,
                ),
            );
            $generator->addPostProcessor(new UnevaluatedPropertiesAccessorPostProcessor());
        };

        $className = $this->generateClassFromFile(
            'NoAdditionalUnevaluatedFalse.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        // Construction with an orphan key must already be rejected by unevaluatedProperties:
        // false — the accessor's set() path mirrors this rejection at runtime, so the two
        // entry points (constructor and accessor) report the same orphan via the same
        // exception class.
        try {
            new $className(['name' => 'Alice', 'foo' => 'orphan']);
            $this->fail('Expected construction to reject the orphan key');
        } catch (UnevaluatedPropertiesException $constructorException) {
            $this->assertSame(
                "Provided JSON for {$className} contains not allowed unevaluated properties [foo]",
                $constructorException->getMessage(),
            );
            $this->assertSame(['foo'], $constructorException->getUnevaluatedProperties());
        }

        $object = new $className(['name' => 'Alice']);

        $this->assertTrue(method_exists($object, 'additionalProperties'));
        // unevaluatedProperties: false → no accessor (nothing to expose; pure assertion).
        $this->assertFalse(method_exists($object, 'unevaluatedProperties'));

        $additionalAccessor = $object->additionalProperties();

        try {
            $additionalAccessor->set('foo', 'orphan');
            $this->fail('Expected unevaluatedProperties: false to reject the orphan key');
        } catch (UnevaluatedPropertiesException $setterException) {
            $this->assertSame(
                "Provided JSON for {$className} contains not allowed unevaluated properties [foo]",
                $setterException->getMessage(),
            );
            $this->assertSame(['foo'], $setterException->getUnevaluatedProperties());
        }

        // Rollback discipline: neither the additional-properties bucket nor the raw input
        // records the failed set.
        $this->assertSame([], $additionalAccessor->getAll());
        $this->assertSame(['name' => 'Alice'], $object->meta()->rawInput());
    }

    /**
     * `additionalProperties: true` + sibling `unevaluatedProperties: {type: integer}` is a
     * dead-code cell in the §4.1 matrix: additionalProperties claims every extra at runtime,
     * so the unevaluated validator and accessor are both suppressed at codegen. The user can
     * therefore call `$model->additionalProperties()->set(...)` with a value of any type —
     * the unevaluated schema's `type: integer` constraint never runs, and a string value
     * lands unobserved.
     *
     * The factory also emits a generation-time warning via the standard `echo` channel so the
     * developer gets a hint that the unevaluatedProperties keyword cannot affect validation at
     * this schema level. The assertion below pins both the warning text and the offending
     * class name.
     */
    public function testAdditionalAccessorSetUnderSuppressedUnevaluatedIsPermitted(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new AdditionalPropertiesAccessorPostProcessor());
            $generator->addPostProcessor(new UnevaluatedPropertiesAccessorPostProcessor());
        };

        $this->expectOutputRegex(
            '/Warning: unevaluatedProperties on \S+ is dead code — sibling additionalProperties: '
            . 'true accepts every extra without crediting the unevaluated accumulator/',
        );

        $className = $this->generateClassFromFile(
            'AdditionalTrueWithUnevaluatedSchema.json',
            (new GeneratorConfiguration())->setImmutable(false)->setOutputEnabled(true),
        );

        $object = new $className(['name' => 'Alice']);

        // No unevaluated accessor — the dead-cell suppression applied at codegen.
        $this->assertFalse(method_exists($object, 'unevaluatedProperties'));

        // additionalProperties.set() lands a string even though the suppressed
        // unevaluatedProperties: {type: integer} would have rejected it.
        $object->additionalProperties()->set('foo', 'hello');
        $this->assertSame('hello', $object->additionalProperties()->get('foo'));
        $this->assertSame(['name' => 'Alice', 'foo' => 'hello'], $object->meta()->rawInput());
    }

    /**
     * Pattern-matched keys land in `_patternProperties`; truly-unevaluated keys land in
     * `_unevaluatedProperties`. The two buckets are non-overlapping by construction — the
     * pattern keyword runs before the unevaluated check and the unevaluated rebuild credits
     * pattern-matched keys as already-evaluated.
     *
     * The shim's runtime guard rejects keys matching a local `patternProperties` pattern when
     * the user tries to route them through `unevaluatedProperties()->set()` — they belong to a
     * different contract (the pattern's type schema) and must go through the pattern accessor.
     *
     * The fixture's pattern is `^s/~` — deliberately containing both RFC 6901 reserved
     * characters. When the guard emits the offending pointer, `/` must escape to `~1` and
     * `~` to `~0`, with `~1` applied first so a raw `/` doesn't collide with the intermediate
     * `~0`. The pattern's pointer segment therefore becomes `^s~1~0`, giving the assertion
     * proof that both replacement rules and their ordering fire.
     */
    public function testPatternAndUnevaluatedAccessorsExposeNonOverlappingBuckets(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PatternPropertiesAccessorPostProcessor());
            $generator->addPostProcessor(new UnevaluatedPropertiesAccessorPostProcessor());
        };

        $className = $this->generateClassFromFile(
            'PatternAndUnevaluatedCoexist.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['name' => 'Alice', 's/~1' => 'pattern-value', 'count' => 42]);

        // pattern-matching key landed in _patternProperties, not in _unevaluatedProperties.
        // The pattern accessor's `get()` returns the full key→value map for the pattern.
        $patternAccessor = $object->patternProperties();
        $this->assertSame(['s/~1' => 'pattern-value'], $patternAccessor->get('^s/~'));

        // non-matching key landed in _unevaluatedProperties only.
        $unevaluatedAccessor = $object->unevaluatedProperties();
        $this->assertSame(['count' => 42], $unevaluatedAccessor->getAll());
        $this->assertNull($unevaluatedAccessor->get('s/~1'));

        // Attempting to route a pattern-matching key through the unevaluated accessor is rejected
        // by the shim guard.
        try {
            $unevaluatedAccessor->set('s/~2', 99);
            $this->fail('Expected RegularPropertyAsUnevaluatedPropertyException');
        } catch (RegularPropertyAsUnevaluatedPropertyException $exception) {
            $this->assertSame(
                "Couldn't add regular property s/~2 as unevaluated property to object {$className}",
                $exception->getMessage(),
            );
            $this->assertSame(
                '/patternProperties/^s~1~0',
                $exception->getJsonPointer()->pointer,
            );
        }
    }


    /**
     * `remove()` walks both the backing _unevaluatedProperties array and the raw model data so
     * a subsequent `getAll()` reports the post-removal state and `meta()->rawInput()` no longer
     * carries the removed key. A second `remove()` on the same key is a no-op and returns
     * false.
     */
    public function testRemoveDeletesKeyFromBackingFieldAndRawInput(): void
    {
        $this->addPostProcessor();
        $className = $this->generateClassFromFile(
            'TypedExtras.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['name' => 'Alice', 'count' => 5, 'limit' => 7]);
        $accessor = $object->unevaluatedProperties();

        $this->assertTrue($accessor->remove('count'));

        // The removed key is gone from both views; the surviving key is intact.
        $this->assertSame(['limit' => 7], $accessor->getAll());
        $this->assertNull($accessor->get('count'));
        $this->assertSame(['name' => 'Alice', 'limit' => 7], $object->meta()->rawInput());

        // Second removal of the same key is a no-op.
        $this->assertFalse($accessor->remove('count'));
        $this->assertSame(['limit' => 7], $accessor->getAll());
    }

    /**
     * A key claimed via the unevaluated accessor stays in `_unevaluatedProperties` even after
     * a later mutation makes a composition branch claim the same key. The accumulator-rebuild
     * model treats the bucket as a write-once view from the accessor's perspective: keys move
     * in via `set()` and `remove()`, never via background reshuffles when an enclosing branch
     * starts/stops covering them.
     *
     * The shim guard rejects keys statically declared inside any composition branch's
     * `properties` or `patternProperties`, so the sibling cover in this test comes via a
     * branch's `additionalProperties: {type: integer}` — a *dynamic* claim that the guard
     * cannot anticipate at codegen.
     *
     * Sequence on the same generated class:
     *   - construct `{kind: "X"}` → branch 0 succeeds, no extras, _unevaluatedProperties empty
     *   - accessor.set('foo', 5) → routes through the shim (guard only sees `kind` in
     *     composition branches, never `foo`); foo lands in _unevaluatedProperties
     *   - setKind('Y') → branch 0 fails, branch 1 succeeds and its additionalProperties claims
     *     `foo` for the accumulator; the unevaluated validator's foreach finds no leftover
     *     keys to validate and therefore does not touch _unevaluatedProperties
     *   - assert: `foo` is still in _unevaluatedProperties, exposed by getAll()
     *
     * Documents the corner the plan calls out: claimed-via-unevaluated values are not
     * retroactively reshuffled when a later mutation changes which sibling covers them.
     */
    public function testClaimedViaUnevaluatedKeyPersistsAfterSiblingLaterCovers(): void
    {
        $this->addPostProcessor();
        $className = $this->generateClassFromFile(
            'KindDiscriminatorTypedExtras.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className(['kind' => 'X']);
        $accessor = $object->unevaluatedProperties();

        $accessor->set('foo', 5);
        $this->assertSame(['foo' => 5], $accessor->getAll());

        // Flip the discriminator: branch 1 now succeeds (kind=Y + foo:5 satisfies its required
        // list) and its `properties` declaration credits `foo` to the accumulator. The
        // unevaluated validator finds no leftover keys.
        $object->setKind('Y');

        // Still surfaced via the unevaluated accessor — the key is not silently moved into a
        // sibling-managed bucket on revalidation.
        $this->assertSame(['foo' => 5], $accessor->getAll());
        $this->assertSame(5, $accessor->get('foo'));
        $this->assertSame(['kind' => 'Y', 'foo' => 5], $object->meta()->rawInput());
    }

    /**
     * Collect-errors mode for `_setAdditionalProperty`: the shim shares a single
     * `_errorRegistry` across the base-validator and post-composition phases and throws once
     * at the end, so errors from both phases land in the same registry. A patternProperties
     * type failure (base phase) combined with an unevaluatedProperties orphan (post-composition
     * phase) must both surface.
     *
     * Schema: `properties: {name}` + `patternProperties: {"^x_": {"type": "integer"}}` +
     * `unevaluatedProperties: false`; additional accessor enabled via
     * `addForModelsWithoutAdditionalPropertiesDefinition`.
     *
     * Setting `x_foo` to a string:
     *   - base phase: pattern matches, value fails `type: integer` → InvalidPatternPropertiesException
     *     in the registry. PatternProperties.phptpl rolls `_patternProperties` back so the
     *     storage no longer records `x_foo` for this call's value.
     *   - post-composition phase: collectUnevaluatedKeys correlates the pattern claim against
     *     `_patternProperties` to honour per-key validity (decision 0.5); the rolled-back
     *     storage means `x_foo` is *not* credited as evaluated, and unevaluatedProperties: false
     *     rejects → UnevaluatedPropertiesException in the registry.
     *
     * Both exceptions are then surfaced through a single ErrorRegistryException.
     */
    public function testSetCollectsPatternAndUnevaluatedErrorsTogether(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(
                new AdditionalPropertiesAccessorPostProcessor(
                    addForModelsWithoutAdditionalPropertiesDefinition: true,
                ),
            );
        };

        $className = $this->generateClassFromFile(
            'PatternWithUnevaluatedFalse.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(true),
        );

        $object = new $className(['name' => 'Alice', 'x_foo' => 5]);
        $additionalAccessor = $object->additionalProperties();

        try {
            $additionalAccessor->set('x_foo', 'bad-string');
            $this->fail('Expected ErrorRegistryException with pattern + unevaluated errors');
        } catch (ErrorRegistryException $registry) {
            $this->assertSame(
                <<<MSG
                Provided JSON for {$className} contains invalid pattern properties.
                  - invalid property 'x_foo' matching pattern '^x_'
                    * Invalid type for pattern property. Requires int, got string
                Provided JSON for {$className} contains not allowed unevaluated properties [x_foo]
                MSG,
                $registry->getMessage(),
            );
        }

        // Rollback discipline: _patternProperties restored to the pre-call value;
        // _rawModelDataInput unchanged.
        $this->assertSame(['name' => 'Alice', 'x_foo' => 5], $object->meta()->rawInput());
    }

    /**
     * Collect-errors mode for `_removeAdditionalProperty`: the shim's `minPropertyValidator`
     * inline check, the composition revalidation (so `_compositionEvaluations` reflects the
     * post-removal state), and the post-composition revalidation share a single
     * `_errorRegistry` and a single throw at the end. Removing a key can flip a composition
     * branch and orphan a key that the previous branch had claimed; without composition
     * revalidation the cache stays stale and the unevaluated check misses the orphan.
     *
     * Schema: `properties: {kind}` + `patternProperties: {"^p_": {"type": "integer"}}` +
     * `minProperties: 3` + an anyOf where branch 0 requires `p_foo` and claims `q_marker` and
     * branch 1 succeeds whenever `kind` is `"X"` and claims nothing. With construction
     * `{kind: "X", p_foo: 1, q_marker: 7}`, both anyOf branches succeed and `q_marker` is
     * credited by branch 0's declared-property list; the model is valid.
     *
     * Removing `p_foo`:
     *   - minPropertyValidator: candidate count drops to 2 < 3 → MinPropertiesException.
     *   - composition revalidation on candidate: branch 0 fails (missing p_foo); branch 1
     *     still succeeds (kind=X); anyOf as a whole still passes — but `q_marker` is no
     *     longer in any successful branch's claimed set.
     *   - post-composition unevaluatedProperties: false: `q_marker` is now an orphan →
     *     UnevaluatedPropertiesException.
     *
     * Both errors land in the registry. On the throw, the model state is rolled back so the
     * caller still sees the pre-removal raw input and the pre-removal pattern-bucket.
     */
    public function testRemoveCollectsMinPropertyAndUnevaluatedErrorsTogether(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(
                new AdditionalPropertiesAccessorPostProcessor(
                    addForModelsWithoutAdditionalPropertiesDefinition: true,
                ),
            );
        };

        $className = $this->generateClassFromFile(
            'BranchFlipOnRemove.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(true),
        );

        $object = new $className(['kind' => 'X', 'p_foo' => 1, 'q_marker' => 7]);
        $additionalAccessor = $object->additionalProperties();

        try {
            $additionalAccessor->remove('p_foo');
            $this->fail('Expected ErrorRegistryException with min-property + unevaluated errors');
        } catch (ErrorRegistryException $registry) {
            $this->assertSame(
                <<<MSG
                Provided object for {$className} must not contain less than 3 properties, 2 properties provided
                Provided JSON for {$className} contains not allowed unevaluated properties [q_marker]
                MSG,
                $registry->getMessage(),
            );
        }

        // The rejected removal must roll the model state back to its pre-call values.
        $this->assertSame(
            ['kind' => 'X', 'p_foo' => 1, 'q_marker' => 7],
            $object->meta()->rawInput(),
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
