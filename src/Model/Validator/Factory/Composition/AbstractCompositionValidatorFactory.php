<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Composition;

use Closure;
use PHPModelGenerator\Draft\Draft;
use PHPModelGenerator\Draft\Producer\ExclusiveProducer;
use PHPModelGenerator\Exception\Generic\DeniedPropertyException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\BaseProperty;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\MultiTypeCheckValidator;
use PHPModelGenerator\Model\Validator\TypeCheckInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\IntToFloatCastDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\PropertyDecoratorInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\PropertyTransferDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\ClearTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\CompositionTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\Filter\CompositionCompatibilityChecker;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\BranchNameResolver;
use PHPModelGenerator\Utils\TypeCheck;
use PHPModelGenerator\Utils\TypeConverter;
use PHPModelGenerator\Utils\TypeIntersection;
use PHPModelGenerator\Utils\TypeNameFormatter;

abstract class AbstractCompositionValidatorFactory extends AbstractValidatorFactory
{
    /**
     * Keywords that constrain validation but are registered on a Type via addModifier() rather
     * than addValidator() (TypeCheckModifier for 'type', ConstModifier for 'const' - see
     * Draft_07::getDefinition()), so Draft::getTypesForKeyword() cannot see them: Type::$modifiers
     * only exposes addValidator() entries as keyword => factory pairs; addModifier() entries are
     * appended with plain numeric keys. Without this list, isVacuousSchema() would incorrectly
     * treat a schema containing only e.g. {"const": "foo"} as vacuous.
     */
    private const array MODIFIER_ONLY_VALIDATION_KEYWORDS = ['type', 'const'];

    /**
     * The name handed to the property factory for the branches of a composition. The class name
     * generation derives the class name of a branch which has no explicit name (title, $id, ...)
     * from it.
     *
     * The root property of a schema is named like the class of the schema. Passing that name on
     * would make the name of a branch class contain the name of its parent class, and the branch
     * classes nested one level deeper would again contain the name of the branch class: the length
     * of the class names (and file names) would grow with every level of nesting until the file name
     * exceeds the limit of the file system. Renaming the root property was rejected: its name is
     * part of the exception messages of the object level validators. Only the name used for the
     * branches is therefore replaced; the composition and the exception messages keep the name of
     * the root property.
     *
     * The replacement is the key of the property or definition the schema lives in, taken from the
     * JSON pointer (see BranchNameResolver), so the branches of a nested object are named like the
     * branches of a composition declared directly on that property. Using a fixed name for every
     * branch was rejected: it discards the property name which makes the class names readable.
     * A property inside of a schema keeps its own name, the length of a property name is bounded
     * by the schema.
     */
    protected function getBranchPropertyName(PropertyInterface $property): string
    {
        return $property instanceof BaseProperty
            ? BranchNameResolver::resolve($property->getJsonSchema()->getPointer())
            : $property->getName();
    }

    /**
     * Emit a generation-time warning for always-unsatisfiable composition schemas.
     */
    protected function warnIfAlwaysFalse(
        SchemaProcessor $schemaProcessor,
        PropertyInterface $property,
        string $reason,
    ): void {
        $schemaProcessor->getGeneratorConfiguration()->getLogger()->warning(
            "Always-unsatisfiable schema for property '{property}': {reason}",
            ['property' => $property->getName(), 'reason' => $reason],
        );
    }

    /**
     * Reject a composition keyword whose value is not a list of branch schemas before anything
     * downstream indexes into it.
     *
     * Both defects are otherwise only noticed as a raw PHP error deep inside the pipeline, which
     * the "reject invalid schemas at generation time" contract rules out:
     *
     * - a keyword given as a JSON object rather than an array (`{"allOf": {"first": {...}}}`)
     *   yields string branch keys, and every branch-numbering site computes `$index + 1` on them
     *   ("Unsupported operand types: string + int");
     * - a branch that is not a schema at all (`{"allOf": ["notASchema"]}`) is indexed into as an
     *   array by the branch processing that follows, and a branch given as a JSON array rather
     *   than an object (`{"allOf": [[1, 2]]}`) yields integer keyword names there.
     *
     * Only the list-shaped keywords (allOf/anyOf/oneOf) call this. `not` and `if`/`then`/`else`
     * take a single branch schema rather than an array, so neither defect applies to them.
     *
     * @throws SchemaException
     */
    protected function assertCompositionBranchesAreWellFormed(
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $branches = $propertySchema->getJson()[$this->key];

        if (!is_array($branches) || !array_is_list($branches)) {
            throw new SchemaException(
                sprintf(
                    "Composition keyword '%s' for property '%s' in file %s must be a list of branch schemas",
                    $this->key,
                    $property->getName(),
                    $property->getJsonSchema()->getFile(),
                ),
                $property->getJsonSchema(),
            );
        }

        foreach ($branches as $index => $branch) {
            if (!$this->isBooleanOrSchemaObject($branch)) {
                throw new SchemaException(
                    sprintf(
                        "Branch #%d of composition keyword '%s' for property '%s' in file %s must be a schema"
                            . ' or a boolean, got %s',
                        $index + 1,
                        $this->key,
                        $property->getName(),
                        $property->getJsonSchema()->getFile(),
                        gettype($branch),
                    ),
                    $property->getJsonSchema(),
                );
            }
        }
    }

    /**
     * Reject a single-schema composition keyword whose value is neither a schema nor a boolean.
     *
     * The array-shaped counterpart of assertCompositionBranchesAreWellFormed(), for the keywords
     * that take one branch instead of a list: `not`, and `if`/`then`/`else`. A scalar there is
     * indexed into as an array by the type inheritance that follows, which surfaces as a raw
     * TypeError from JsonSchema rather than a schema error naming the offending keyword; a JSON
     * array rather than an object yields integer keyword names and a TypeError of its own.
     *
     * @param string[] $keywords
     *
     * @throws SchemaException
     */
    protected function assertSingleBranchSchemasAreWellFormed(
        PropertyInterface $property,
        JsonSchema $propertySchema,
        array $keywords,
    ): void {
        $json = $propertySchema->getJson();

        foreach ($keywords as $keyword) {
            if (!array_key_exists($keyword, $json) || $this->isBooleanOrSchemaObject($json[$keyword])) {
                continue;
            }

            throw new SchemaException(
                sprintf(
                    "Composition keyword '%s' for property '%s' in file %s must be a schema or a boolean, got %s",
                    $keyword,
                    $property->getName(),
                    $property->getJsonSchema()->getFile(),
                    gettype($json[$keyword]),
                ),
                $property->getJsonSchema(),
            );
        }
    }

    /**
     * Emit a warning when the composition array for the current keyword is empty.
     */
    protected function warnIfEmpty(
        SchemaProcessor $schemaProcessor,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        if (empty($propertySchema->getJson()[$this->key])) {
            $schemaProcessor->getGeneratorConfiguration()->getLogger()->warning(
                "Empty composition for '{property}' may lead to unexpected results",
                ['property' => $property->getName()],
            );
        }
    }

    /**
     * Emit a generation-time warning when a composition branch carries no validation/assertion
     * keyword at all and therefore matches any value. This is purely informational and does not
     * change generated behavior - a vacuous branch keeps its full (odd but spec-correct) effect on
     * the composition regardless of this warning.
     *
     * @param array<string, mixed>                    $branchJson      The branch as the author wrote it, without
     *                                                                 a type the branch inherited
     * @param Closure(string): (array|bool|null)|null $referencePeeker Resolves a `$ref` to the raw JSON of its
     *                                                                 target, see isVacuousSchema()
     */
    private function warnIfVacuousBranch(
        SchemaProcessor $schemaProcessor,
        PropertyInterface $property,
        int $oneBasedBranchIndex,
        array $branchJson,
        Draft $draft,
        ?Closure $referencePeeker = null,
    ): void {
        if (!$this->isVacuousSchema($branchJson, $draft, $referencePeeker)) {
            return;
        }

        $schemaProcessor->getGeneratorConfiguration()->getLogger()->warning(
            "Composition branch #{index} for '{property}' carries no validation keyword and"
                . ' matches any value',
            ['index' => $oneBasedBranchIndex, 'property' => $property->getName()],
        );
    }

    /**
     * Whether a schema matches any value because none of its keys constrains one.
     *
     * A key constrains when it is an actually-registered validation keyword - driven by the Draft
     * itself rather than a hardcoded list of "known safe" keywords, so an unrecognized or
     * misspelled key is correctly treated as non-constraining (the same as a genuine annotation
     * keyword) instead of being mistaken for a real constraint merely because it wasn't
     * anticipated.
     *
     * A `$ref` constrains nothing by itself, it forwards to its target: the schema is as vacuous
     * as the target is (a boolean target is vacuous if it is `true`). The keywords next to the
     * `$ref` only count in the drafts which apply them together with the reference - the same
     * sibling policy PropertyFactory and ObjectShapeResolver follow. The target is read from the
     * source through $referencePeeker. Judging the property built for the branch instead was
     * rejected: for a `$ref` with sibling keywords (which includes the type the enclosing schema
     * passes down) that property is a merge target which carries the siblings only, so every
     * such branch looked vacuous no matter what it references.
     *
     * Whenever the target can't be determined (no peeker, unresolvable or cyclic reference) the
     * schema is not claimed to be vacuous: the warning is only emitted for a provable case.
     *
     * @param array<string, mixed>|bool               $schemaJson
     * @param Closure(string): (array|bool|null)|null $referencePeeker
     * @param string[]                                $visitedReferences `$ref` strings on the current path
     */
    private function isVacuousSchema(
        array | bool $schemaJson,
        Draft $draft,
        ?Closure $referencePeeker,
        array $visitedReferences = [],
    ): bool {
        if (is_bool($schemaJson)) {
            return $schemaJson;
        }

        $siblingJson = $schemaJson;

        if (array_key_exists('$ref', $schemaJson)) {
            $reference = $schemaJson['$ref'];
            unset($siblingJson['$ref']);

            if (!is_string($reference) || $referencePeeker === null || in_array($reference, $visitedReferences, true)) {
                return false;
            }

            $targetJson = $referencePeeker($reference);

            if (
                $targetJson === null
                || !$this->isVacuousSchema($targetJson, $draft, $referencePeeker, [...$visitedReferences, $reference])
            ) {
                return false;
            }

            if ($draft->getProducerForKeyword('$ref') instanceof ExclusiveProducer) {
                return true;
            }
        }

        foreach (array_keys($siblingJson) as $keyword) {
            if (
                in_array($keyword, self::MODIFIER_ONLY_VALIDATION_KEYWORDS, true)
                || $draft->getTypesForKeyword($keyword) !== []
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Report a branch which inherited the type of its parent (see inheritPropertyType() and
     * inheritIfPropertyType()) while its own 'enum' or 'const' lists no value of that type. The
     * branch can never be satisfied: a value would have to be of the inherited type and one of the
     * listed values at once.
     *
     * An allOf requires every branch to hold for the same value, so a branch like this makes the
     * whole composition unsatisfiable and is rejected like the contradicting branch types
     * assertNoAllOfConflict() rejects. For every other composition the branch is merely dead - the
     * schema stays satisfiable through another branch of an anyOf/oneOf, a `not` of a dead branch
     * holds for every value and an `if` which never matches selects `else` - so the generation
     * proceeds with a warning.
     *
     * The inheritance doesn't create the contradiction: the parent's type is enforced on the very
     * same value anyway (at a schema root the instance is always an object, a typed property has its
     * own type check), so the branch is dead as written and injecting the type only changes the
     * message it fails with. For the same reason removing the injection would not make the allOf
     * satisfiable and is no substitute for the rejection.
     *
     * Deliberately limited to 'enum'/'const': the values they list are fixed and enumerable, so
     * "no value has the inherited type" is a mechanical proof. Broadening the trigger to "any branch
     * which cannot have the inherited type" was rejected, as proving that in general requires a
     * full satisfiability analysis of arbitrary keyword combinations. The check also isn't deferred
     * to assertNoAllOfConflict(), which runs on the resolved branches: by then the listed values
     * only exist inside an EnumValidator, whereas the raw 'enum'/'const' is at hand where the type
     * is injected.
     *
     * @param string               $branchLabel  Rendered into the warning as-is, so it arrives
     *                                           pre-formatted: '#2' for a positional branch of an
     *                                           allOf/anyOf/oneOf (1-based, matching both the
     *                                           vacuous-branch warning and the runtime
     *                                           "Composition element #N" numbering), or a quoted
     *                                           keyword such as 'then' for the shapes whose
     *                                           branches are named rather than numbered.
     * @param array<string, mixed> $branchJson   The branch after the 'type' injection was applied.
     * @param int|null             $branchIndex  Zero-based position of the branch within the
     *                                           composition keyword; only allOf, which rejects the
     *                                           branch instead of warning, is addressed by position.
     *
     * @throws SchemaException when the composition is an allOf
     */
    private function reportBranchWhichCannotSatisfyInheritedType(
        SchemaProcessor $schemaProcessor,
        PropertyInterface $property,
        JsonSchema $propertySchema,
        string $branchLabel,
        array $branchJson,
        ?int $branchIndex = null,
    ): void {
        if (!$this->enumOrConstExcludesType($branchJson)) {
            return;
        }

        if ($this->key === 'allOf') {
            throw new SchemaException(
                sprintf(
                    "Composition branch %s for '%s' in file %s is forced to type %s by the inherited parent type,"
                        . " but its 'enum'/'const' contains no value of that type: the allOf composition can never"
                        . ' be satisfied',
                    $branchLabel,
                    $property->getName(),
                    $propertySchema->getFile(),
                    TypeNameFormatter::fromJsonTypes($branchJson['type']),
                ),
                $propertySchema->navigate("$this->key/$branchIndex"),
            );
        }

        $schemaProcessor->getGeneratorConfiguration()->getLogger()->warning(
            "Composition branch {branch} for '{property}' is forced to type {type} by the inherited parent type,"
                . " but its 'enum'/'const' contains no value of that type - the branch can never be satisfied",
            [
                'branch' => $branchLabel,
                'property' => $property->getName(),
                'type' => TypeNameFormatter::fromJsonTypes($branchJson['type']),
            ],
        );
    }

    /**
     * Whether the 'enum' or the 'const' of a branch lists no value which the branch's 'type' admits.
     *
     * Both keywords are looked at on their own: a branch declaring both has to satisfy both, so one
     * of them excluding the type is enough, even if the other one lists a matching value. An empty
     * 'enum' is not judged here - it is a defect of its own, reported by EnumValidatorFactory with a
     * message about the empty enum which this check must not pre-empt.
     *
     * @param array<string, mixed> $branchJson
     */
    private function enumOrConstExcludesType(array $branchJson): bool
    {
        $candidateLists = [];

        if (isset($branchJson['enum']) && is_array($branchJson['enum']) && $branchJson['enum'] !== []) {
            $candidateLists[] = $branchJson['enum'];
        }

        if (array_key_exists('const', $branchJson)) {
            $candidateLists[] = [$branchJson['const']];
        }

        $admittedTypes = (array) $branchJson['type'];

        foreach ($candidateLists as $candidateValues) {
            $hasAdmittedValue = false;

            foreach ($candidateValues as $candidateValue) {
                foreach ($admittedTypes as $admittedType) {
                    if (self::typeAdmitsValue($admittedType, $candidateValue)) {
                        $hasAdmittedValue = true;
                        break 2;
                    }
                }
            }

            if (!$hasAdmittedValue) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a value decoded from a JSON schema is a valid instance of a JSON Schema type.
     *
     * The answer errs on the side of "admitted" wherever the decoded value is ambiguous or the type
     * is unknown, as a wrong "excluded" rejects a schema that can be satisfied:
     *
     * - json_decode() turns a JSON object and a JSON array into a PHP array. A list is an array, a
     *   non-list is an object, and the empty array may be either of them (the same carve-out
     *   TypeCheck::buildNegatedJsonSchemaTypeCheck() applies).
     * - A number without a fractional part is an integer in JSON Schema, so 2.0 is admitted by
     *   'integer'. Testing for is_int() only was rejected: it would reject a schema the
     *   specification considers satisfiable.
     */
    private static function typeAdmitsValue(mixed $typeName, mixed $value): bool
    {
        return match ($typeName) {
            'string' => is_string($value),
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && (!array_is_list($value) || $value === []),
            default => true,
        };
    }


    /**
     * Returns true when composition processing should be skipped for this property.
     *
     * For non-root object-typed properties, composition keywords are processed inside
     * the nested schema by processSchema (with the type=base path). Adding a composition
     * validator at the parent level would duplicate validation and inject a _Merged_ type
     * hint that overrides the correct nested-class type.
     */
    protected function shouldSkip(PropertyInterface $property, JsonSchema $propertySchema): bool
    {
        return !($property instanceof BaseProperty)
            && ($propertySchema->getJson()['type'] ?? '') === 'object';
    }

    /**
     * Check the (post-type-inheritance) composition branches for filter keywords.
     *
     * Must be called AFTER inheritPropertyType() in each modify() method. A branch that
     * inherits "object" from the parent is genuinely object-typed: PropertyFactory routes
     * it through processSchema, producing a nested class whose properties are processed
     * independently and are not subject to ComposedItem $value reset. branchContainsFilter()
     * correctly skips the properties scan for such branches.
     *
     * For "not", the value is a single branch schema (not an array); all other keywords
     * use an array of branches.
     *
     * TODO: filters inside composition branches cannot be correctly applied
     * (ComposedItem.phptpl resets $value to $originalModelData after each branch).
     * Proper per-branch filter chaining is deferred to a follow-up topic.
     *
     * @throws SchemaException
     */
    protected function checkForFilterInBranches(
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $json = $propertySchema->getJson();

        if ($this->key === 'not') {
            $branch = $json['not'] ?? null;
            if (
                is_array($branch)
                && CompositionCompatibilityChecker::branchContainsFilter($branch)
            ) {
                throw new SchemaException(
                    sprintf(
                        'A filter keyword inside a not composition branch is not supported'
                            . ' for property %s in file %s.',
                        $property->getName(),
                        $property->getJsonSchema()->getFile(),
                    ),
                    $property->getJsonSchema(),
                );
            }
            return;
        }

        foreach ($json[$this->key] ?? [] as $index => $compositionElement) {
            if (
                is_array($compositionElement)
                && CompositionCompatibilityChecker::branchContainsFilter($compositionElement)
            ) {
                throw new SchemaException(
                    sprintf(
                        'A filter keyword inside a %s composition branch is not supported'
                            . ' for property %s in file %s (branch #%d).',
                        $this->key,
                        $property->getName(),
                        $property->getJsonSchema()->getFile(),
                        $index + 1,
                    ),
                    $property->getJsonSchema(),
                );
            }
        }
    }

    /**
     * Build composition sub-properties for the current keyword's branches.
     *
     * @param bool             $merged                   Whether to suppress
     *                                                    CompositionTypeHintDecorators for
     *                                                    object branches.
     * @param array<int, true> $injectedTypeBranchIndices Branch indices whose 'type' was
     *                                                    inherited rather than author-declared
     *                                                    (see inheritPropertyType()) - excluded
     *                                                    from the vacuous-branch check below.
     *
     * @return CompositionPropertyDecorator[]
     *
     * @throws SchemaException
     */
    protected function getCompositionProperties(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
        bool $merged,
        array $injectedTypeBranchIndices = [],
    ): array {
        $propertyFactory = new PropertyFactory();
        $compositionProperties = [];
        $ownerSchema = $propertySchema->getJson()['propertySchema'];
        $json = $ownerSchema->getJson();
        $draft = $schemaProcessor->getGeneratorConfiguration()->getBuiltDraft($ownerSchema);

        $property->addTypeHintDecorator(new ClearTypeHintDecorator());

        foreach ($json[$this->key] as $index => $compositionElement) {
            if ($compositionElement === false) {
                $compositionProperties[] = $this->createAlwaysFalseBranchProperty(
                    $schemaProcessor,
                    $schema,
                    $property,
                    $ownerSchema,
                );
                continue;
            }

            if ($compositionElement === true) {
                $trueBranchProperty = $this->createAlwaysTrueBranchProperty(
                    $schemaProcessor,
                    $schema,
                    $property,
                    $ownerSchema,
                );
                $compositionProperties[] = $trueBranchProperty;

                // A literal `true` branch is modeled as an empty-json branch (see
                // createAlwaysTrueBranchProperty()) - byte-identical to the empty-`{}` branch shape
                // handled below, so it is routed through the same warnIfVacuousBranch() check rather
                // than a second, parallel warning call. `false` deliberately does NOT get this
                // treatment: it is the opposite of vacuous (rejects everything) and already has its
                // own diagnostic via warnIfAlwaysFalse().
                if ($trueBranchProperty->isResolved()) {
                    $this->warnIfVacuousBranch(
                        $schemaProcessor,
                        $property,
                        $index + 1,
                        $trueBranchProperty->getJsonSchema()->getJson(),
                        $draft,
                    );
                }

                continue;
            }

            // The branches validate the very value of their owner: if the owner asserts an object, so do they. This
            // includes a branch which is a reference, where the type injected by inheritPropertyType() is ignored or
            // merged into a separate property and says nothing about the definition behind the reference.
            $compositionSchema = $ownerSchema
                ->navigate("$this->key/$index")
                ->withObjectAssertedByContext($ownerSchema->assertsObject());

            $compositionProperty = new CompositionPropertyDecorator(
                $property->getName(),
                $compositionSchema,
                $propertyFactory->create(
                    $schemaProcessor,
                    $schema,
                    $this->getBranchPropertyName($property),
                    $compositionSchema,
                    $property->isRequired(),
                ),
                $draft,
            );

            // The branch is judged as the author wrote it, not by the property which was built for
            // it: for a `$ref` that property is the referenced target in the drafts which ignore
            // siblings, but a merge target holding the sibling keywords only in the drafts which
            // apply them (which includes the type passed down by the enclosing schema) - see
            // isVacuousSchema(). The property isn't touched, so a branch which is still awaiting
            // its resolution (part of a recursive $ref chain) can be judged as well.
            $branchJson = $compositionSchema->getJson();

            // An inherited 'type' isn't an author-declared constraint - strip it so
            // vacuousness is judged on what the branch actually declares.
            if (isset($injectedTypeBranchIndices[$index])) {
                unset($branchJson['type']);
            }

            $this->warnIfVacuousBranch(
                $schemaProcessor,
                $property,
                $index + 1,
                $branchJson,
                $draft,
                static fn(string $reference): array | bool | null => $schema->getSchemaDictionary()->peekReference(
                    $reference,
                    $schemaProcessor,
                    $compositionSchema->getBaseId(),
                ),
            );

            // The InstanceOfValidator-for-empty-object exclusion for this
            // branch is handled by CompositionPropertyDecorator::getOrderedValidators() at render
            // time, not here — see that method's docblock for why it must not be a destructive,
            // schema-processing-time filterValidators() call.
            $compositionProperty->onResolve(function () use ($compositionProperty, $property, $merged): void {
                if (!($merged && $compositionProperty->getNestedSchema())) {
                    $property->addTypeHintDecorator(new CompositionTypeHintDecorator($compositionProperty));
                }
            });

            $compositionProperties[] = $compositionProperty;
        }

        return $compositionProperties;
    }

    /**
     * Create a composition branch for a boolean `false` schema element.
     *
     * The branch of a property always fails when the property key is present in $modelData, so
     * absent optional properties are not denied. The branch of a composition of the schema itself
     * always fails: the validated object is always present. Checking the presence there was
     * rejected: the check looked for a key named like the class, the false branch matched any
     * object without such a key. Used for false branches in allOf/anyOf/oneOf compositions.
     */
    protected function createAlwaysFalseBranchProperty(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $parentSchema,
    ): CompositionPropertyDecorator {
        $propertyFactory = new PropertyFactory();
        $branchSchema = $parentSchema->withJson([]);

        $branchProperty = new CompositionPropertyDecorator(
            $property->getName(),
            $branchSchema,
            $propertyFactory->create(
                $schemaProcessor,
                $schema,
                $property->getName(),
                $branchSchema,
                $property->isRequired(),
            ),
            $schemaProcessor->getGeneratorConfiguration()->getBuiltDraft($branchSchema),
        );

        $presenceCheck = $property instanceof BaseProperty
            ? 'true'
            : "array_key_exists('" . addslashes($property->getName()) . "', \$modelData)";

        // The InstanceOfValidator-for-empty-object exclusion for this branch is handled by
        // CompositionPropertyDecorator::getOrderedValidators() at render time.
        $branchProperty->onResolve(
            function () use ($branchProperty, $presenceCheck): void {
                $branchProperty->addValidator(
                    new PropertyValidator(
                        $branchProperty,
                        $presenceCheck,
                        DeniedPropertyException::class,
                    ),
                );
            },
        );

        return $branchProperty;
    }

    /**
     * Create a composition branch for a boolean `true` schema element.
     *
     * The branch always succeeds (no validators) and is marked as an always-true branch so that
     * type inference excludes it from type narrowing. Used for true branches in allOf/anyOf/oneOf.
     */
    protected function createAlwaysTrueBranchProperty(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $parentSchema,
    ): CompositionPropertyDecorator {
        $propertyFactory = new PropertyFactory();
        $branchSchema = $parentSchema->withJson([]);

        $branchProperty = new CompositionPropertyDecorator(
            $property->getName(),
            $branchSchema,
            $propertyFactory->create(
                $schemaProcessor,
                $schema,
                $property->getName(),
                $branchSchema,
                $property->isRequired(),
            ),
            $schemaProcessor->getGeneratorConfiguration()->getBuiltDraft($branchSchema),
        );

        $branchProperty->markAsAlwaysTrueBranch();

        // No validator added — true schema always succeeds.
        // No type hint decorator — true schema contributes no type constraint.
        // The InstanceOfValidator-for-empty-object exclusion for this branch is handled by
        // CompositionPropertyDecorator::getOrderedValidators() at render time.

        return $branchProperty;
    }

    /**
     * Inherit a parent-level type into composition branches that declare no type.
     *
     * @return array{0: JsonSchema, 1: array<int, true>} The (possibly type-injected) schema, and
     *         the zero-based indices of branches whose 'type' this call injected rather than
     *         found already declared - consumed by getCompositionProperties()'s vacuous-branch
     *         check. Populated for 'not' too (index 0, once the caller wraps its single schema
     *         into an array). Always empty for 'if', which never reaches
     *         getCompositionProperties().
     */
    protected function inheritPropertyType(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): array {
        $injectedTypeBranchIndices = [];

        $json = $propertySchema->getJson();

        if (!isset($json['type'])) {
            return [$propertySchema, $injectedTypeBranchIndices];
        }

        switch ($this->key) {
            case 'not':
                if (
                    !isset($json[$this->key]['type'])
                    && $this->canInheritPropertyType($schemaProcessor, $schema, $propertySchema, $this->key)
                ) {
                    $json[$this->key]['type'] = $json['type'];
                    $injectedTypeBranchIndices[0] = true;

                    $this->reportBranchWhichCannotSatisfyInheritedType(
                        $schemaProcessor,
                        $property,
                        $propertySchema,
                        "'not'",
                        $json[$this->key],
                    );
                }
                break;
            case 'if':
                return [
                    $this->inheritIfPropertyType(
                        $schemaProcessor,
                        $schema,
                        $property,
                        $propertySchema->withJson($json),
                    ),
                    $injectedTypeBranchIndices,
                ];
            default:
                foreach ($json[$this->key] as $index => &$composedElement) {
                    if (
                        !is_bool($composedElement)
                        && !isset($composedElement['type'])
                        && $this->canInheritPropertyType(
                            $schemaProcessor,
                            $schema,
                            $propertySchema,
                            "$this->key/$index",
                        )
                    ) {
                        $composedElement['type'] = $json['type'];
                        $injectedTypeBranchIndices[$index] = true;

                        $this->reportBranchWhichCannotSatisfyInheritedType(
                            $schemaProcessor,
                            $property,
                            $propertySchema,
                            '#' . ($index + 1),
                            $composedElement,
                            $index,
                        );
                    }
                }
        }

        return [$propertySchema->withJson($json), $injectedTypeBranchIndices];
    }

    /**
     * Inherit the parent type into all branches of an if/then/else composition.
     */
    protected function inheritIfPropertyType(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): JsonSchema {
        $json = $propertySchema->getJson();

        foreach (['if', 'then', 'else'] as $keyword) {
            if (!isset($json[$keyword]) || is_bool($json[$keyword])) {
                continue;
            }

            if (
                !isset($json[$keyword]['type'])
                && $this->canInheritPropertyType($schemaProcessor, $schema, $propertySchema, $keyword)
            ) {
                $json[$keyword]['type'] = $json['type'];

                $this->reportBranchWhichCannotSatisfyInheritedType(
                    $schemaProcessor,
                    $property,
                    $propertySchema,
                    "'$keyword'",
                    $json[$keyword],
                );
            }
        }

        return $propertySchema->withJson($json);
    }

    /**
     * Whether the declared type of $propertySchema may be passed down into the untyped branch at
     * $branchPointer. It may not for a `$ref` branch whose referenced definition declares a type
     * disjoint from the declared one: in the drafts which apply the siblings of a `$ref` the
     * passed down type would be merged with the referenced type and fail the generation with a
     * conflict between the reference and a sibling `type` the author never wrote. Left as
     * authored, the branch is dead and reported by assertBranchTypesAreSatisfiable() like an
     * inline branch with a disjoint type (a warning for anyOf / oneOf, a conflict for allOf).
     *
     * Not passing the declared type into any `$ref` branch was rejected: a reference to an untyped
     * definition with type-specific keywords (e.g. `maxLength`) relies on the inherited type for
     * them to apply. A reference to a reference is followed down to the first definition which
     * declares a type, as the merge of the siblings conflicts with that type just the same. Each
     * reference is resolved against the base of the branch, which is only right as long as the
     * chain stays in the document of the branch: after a reference into another document the chain
     * is not followed further. Such a chain, a reference which cannot be resolved yet
     * (peekReference() returns null) and a cyclic chain keep the inherited type, as before.
     */
    private function canInheritPropertyType(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        JsonSchema $propertySchema,
        string $branchPointer,
    ): bool {
        $branchSchema = $propertySchema->navigate($branchPointer);
        $target = $branchSchema->getJson();
        $visitedReferences = [];

        while (!isset($target['type']) && is_string($target['$ref'] ?? null)) {
            $leftTheDocument = $visitedReferences !== [] && !str_starts_with(array_key_last($visitedReferences), '#');

            if ($leftTheDocument || isset($visitedReferences[$target['$ref']])) {
                return true;
            }

            $visitedReferences[$target['$ref']] = true;
            $target = $schema->getSchemaDictionary()->peekReference(
                $target['$ref'],
                $schemaProcessor,
                $branchSchema->getBaseId(),
            );

            if (!is_array($target)) {
                return true;
            }
        }

        if ($visitedReferences === [] || !isset($target['type'])) {
            return true;
        }

        return TypeIntersection::compute(
            self::normalizeTypeNames($target['type']),
            self::normalizeTypeNames($propertySchema->getJson()['type']),
        ) !== [];
    }

    /**
     * After all composition branches resolve, derive the parent property's type from the
     * branch types and apply it. Skips when any branch has a nested schema (object merging
     * is handled elsewhere).
     *
     * Conflicting branch types are not detected here: assertBranchTypesAreSatisfiable() owns
     * every generation-time type conflict of a composition and runs before this method, whether
     * the type transfer proceeds or is skipped. Asserting the allOf conflict from here as well was
     * rejected: the question "are these branches in conflict" would be answered from two places,
     * and a post processor recomputing the type (EnumPostProcessor) has nothing to assert, as the
     * schema was checked when it was processed.
     *
     * allOf: intersect all typed branch types — only values satisfying every branch simultaneously
     * are valid, so the PHP type is the intersection. Branches with no declared type impose no
     * constraint and are excluded from the intersection. An empty intersection (contradictory
     * branch types) was already rejected by assertBranchTypesAreSatisfiable().
     *
     * anyOf / oneOf: union of all typed branch types — at least one branch must pass, so the PHP
     * type is the union. An untyped branch accepts every value, making the composition satisfied
     * by any input; the property's type hint is removed (remains mixed) in that case. A branch
     * that resolves to an object (nested schema) alongside a scalar-typed sibling branch is not a
     * conflict here: unlike allOf, anyOf/oneOf allow a value to satisfy either shape, so no check
     * is needed.
     *
     * Also callable from outside the factory (e.g. EnumPostProcessor) after a post processor has
     * mutated branch types and needs the parent's native type recomputed from the updated branches.
     *
     * @param bool $isAllOf true for allOf, false for anyOf/oneOf.
     * @param CompositionPropertyDecorator[] $compositionProperties
     */
    public static function transferPropertyType(
        PropertyInterface $property,
        array $compositionProperties,
        bool $isAllOf,
    ): void {
        foreach ($compositionProperties as $compositionProperty) {
            if ($compositionProperty->getNestedSchema() !== null) {
                return;
            }
        }

        // For anyOf/oneOf: a true branch always satisfies the composition for any value,
        // so the property type cannot be narrowed — leave it untyped.
        // For allOf: exclude true branches from type computation; they contribute no constraint.
        $activeBranches = array_values(array_filter(
            $compositionProperties,
            static fn(CompositionPropertyDecorator $compositionProperty): bool =>
                !$compositionProperty->isAlwaysTrueBranch(),
        ));

        if (!$isAllOf && count($activeBranches) < count($compositionProperties)) {
            return;
        }

        $hasBranchWithRequiredProperty = array_filter(
            $activeBranches,
            static fn(CompositionPropertyDecorator $branch): bool => $branch->isRequired(),
        ) !== [];

        $hasBranchWithOptionalProperty = $isAllOf
            ? !$hasBranchWithRequiredProperty
            : array_filter(
                $activeBranches,
                static fn(CompositionPropertyDecorator $branch): bool => !$branch->isRequired(),
            ) !== [];

        if ($isAllOf) {
            self::transferAllOfType($property, $compositionProperties, $hasBranchWithOptionalProperty);
            return;
        }

        self::transferAnyOfOneOfType($property, $compositionProperties, $hasBranchWithOptionalProperty);
    }

    /**
     * The single entry point for the generation-time type conflicts of a composition, called once
     * every branch is resolved and before transferPropertyType(). For allOf, the branches are
     * checked against each other first (see assertNoAllOfConflict()). Then the `type` of every
     * branch is checked against the `type` declared on the property the composition belongs to.
     * The property validates its own declared type independently of the composition, so a branch
     * type which does not overlap it can never match:
     *
     * - allOf: every branch must hold, so one disjoint branch makes the schema unsatisfiable and
     *   is rejected.
     * - anyOf / oneOf: a disjoint branch is dead but another branch may still match, so it is
     *   reported with a warning. Only when every branch is dead no value can be valid and the
     *   schema is rejected. Rejecting every dead branch was considered and dropped: a partly
     *   irrelevant branch is valid JSON Schema.
     *
     * The declared type is read from the `type` keyword of the property schema, not from
     * PropertyInterface::getType(): the latter may already have been replaced by a filter's output
     * type or by an earlier composition, while a branch `type` always validates the raw input.
     * Comparing against the output type of a filter was rejected - a `{type: integer}` branch under
     * a string-to-int filter must validate the raw input, so matching it against the output type
     * would let the string '50' pass a `type: integer` check after the transformation.
     *
     * Implicit null widens the declared type to "T | null" for exactly the properties it applies
     * to: implicit null enabled and the property not required. For any other property a `null`
     * branch stays a contradiction.
     *
     * Branches without a `type` keyword (and always-true branches) constrain no type and never
     * conflict. A branch which inherited the property type (see inheritPropertyType()) overlaps
     * the declared type by construction.
     *
     * A composition which belongs to a branch of an anyOf, oneOf, not, if, then or else only makes
     * that branch dead (or the not always hold) while the schema stays satisfiable, so its
     * conflicts are reported with a warning for each disjoint branch instead of failing the
     * generation - see isInsideNonAllOfBranch(). Failing the generation there was rejected:
     * `{"type": "string", "not": {"allOf": [{"type": "integer"}]}}` accepts every string. The
     * conflict of allOf branches with each other keeps failing the generation in every position.
     *
     * @param CompositionPropertyDecorator[] $compositionProperties
     *
     * @throws SchemaException when an allOf branch is disjoint from the declared type, or when no
     *                         anyOf / oneOf branch overlaps it, unless the composition belongs to
     *                         a branch of another composition.
     */
    protected static function assertBranchTypesAreSatisfiable(
        GeneratorConfiguration $generatorConfiguration,
        PropertyInterface $property,
        array $compositionProperties,
        string $keyword,
    ): void {
        // Branches contradicting each other is the more specific diagnostic and has to win over a
        // conflict with the declared type: the declared type of a schema which becomes a class is
        // the internal object marker, so e.g. an object branch next to a string branch conflicts
        // with the declared type as well, but the cause is the two branches.
        if ($keyword === 'allOf') {
            self::assertNoAllOfConflict($property, $compositionProperties);
        }

        $declaredTypeNames = self::getDeclaredTypeNames($property);

        if ($declaredTypeNames === null) {
            return;
        }

        $effectiveTypeNames = $declaredTypeNames;
        if (
            !($property instanceof BaseProperty)
            && !$property->isRequired()
            && $generatorConfiguration->isImplicitNullAllowed()
        ) {
            $effectiveTypeNames[] = 'null';
        }

        $disjointBranches = [];
        foreach ($compositionProperties as $index => $compositionProperty) {
            $branchTypeNames = self::getBranchTypeNames($compositionProperty);

            if ($branchTypeNames !== null && TypeIntersection::compute($effectiveTypeNames, $branchTypeNames) === []) {
                $disjointBranches[$index] = $compositionProperty;
            }
        }

        if ($disjointBranches === []) {
            return;
        }

        // The messages name the types as the author wrote them (`number`, not the PHP type `float`),
        // formatted like the other composition diagnostics.
        $declaredType = TypeNameFormatter::fromJsonTypes($property->getJsonSchema()->getJson()['type']);
        $isFatal = !self::isInsideNonAllOfBranch($property->getJsonSchema()->getPointer());

        if ($isFatal && $keyword === 'allOf') {
            throw new SchemaException(
                sprintf(
                    "Property '%s' is declared with type %s which conflicts with the type %s of allOf branch #%d"
                        . ' (file %s): allOf requires all constraints to hold simultaneously,'
                        . ' making this schema unsatisfiable',
                    $property->getName(),
                    $declaredType,
                    self::formatBranchType(reset($disjointBranches)),
                    key($disjointBranches) + 1,
                    $property->getJsonSchema()->getFile(),
                ),
                $property->getJsonSchema(),
            );
        }

        if ($isFatal && count($disjointBranches) === count($compositionProperties)) {
            throw new SchemaException(
                sprintf(
                    "Property '%s' is declared with type %s which conflicts with the types %s of all %s branches"
                        . ' (file %s): %s requires at least one branch to be satisfied,'
                        . ' making this schema unsatisfiable',
                    $property->getName(),
                    $declaredType,
                    implode(', ', array_map(self::formatBranchType(...), $disjointBranches)),
                    $keyword,
                    $property->getJsonSchema()->getFile(),
                    $keyword,
                ),
                $property->getJsonSchema(),
            );
        }

        foreach ($disjointBranches as $index => $disjointBranch) {
            $generatorConfiguration->getLogger()->warning(
                "{keyword} branch #{branch} for property '{property}' declares the type {branchType}"
                    . ' which does not overlap the declared type {declaredType} and can never be satisfied',
                [
                    'keyword' => $keyword,
                    'branch' => $index + 1,
                    'property' => $property->getName(),
                    'branchType' => self::formatBranchType($disjointBranch),
                    'declaredType' => $declaredType,
                ],
            );
        }
    }

    private static function formatBranchType(CompositionPropertyDecorator $branch): string
    {
        return TypeNameFormatter::fromJsonTypes($branch->getJsonSchema()->getJson()['type']);
    }

    /**
     * Whether the schema at $pointer is a branch of an anyOf, oneOf, not, if, then or else, directly
     * or through allOf branches, below the nearest property or definition. A value which fails such
     * a branch can still be valid. The pointer is walked from the start and the keyword slots are
     * consumed pairwise, like BranchNameResolver does, so a property named like a composition
     * keyword (`/properties/anyOf/allOf/0`) is not mistaken for the keyword. An allOf branch alone
     * doesn't count: whatever can't satisfy an allOf branch can't satisfy the allOf either.
     */
    private static function isInsideNonAllOfBranch(string $pointer): bool
    {
        $segments = array_values(array_filter(explode('/', $pointer), 'strlen'));
        $segmentCount = count($segments);
        $insideNonAllOfBranch = false;

        for ($index = 0; $index < $segmentCount; $index++) {
            $segment = $segments[$index];

            if (in_array($segment, ['allOf', 'anyOf', 'oneOf', 'not', 'if', 'then', 'else'], true)) {
                $insideNonAllOfBranch = $insideNonAllOfBranch || $segment !== 'allOf';

                if ($index + 1 < $segmentCount && is_numeric($segments[$index + 1])) {
                    $index++;
                }

                continue;
            }

            // Any other keyword leads to another value (a property, an item, a definition, ...);
            // the keywords followed by a user chosen key consume that key as well.
            $insideNonAllOfBranch = false;

            $keyedKeywords = [
                'properties',
                'definitions',
                '$defs',
                'patternProperties',
                'dependencies',
                'dependentSchemas',
            ];

            if (in_array($segment, $keyedKeywords, true)) {
                $index++;
            }
        }

        return $insideNonAllOfBranch;
    }

    /**
     * The generator-internal type names of the `type` keyword of the property schema, or null when
     * the property declares no type.
     *
     * The internal 'base' dispatch type replaces the `type` of every schema which becomes a
     * generated class (the schema root and every nested object schema), erasing what the author
     * declared. Such a schema always describes an object, so it is read as 'object'. Skipping the
     * check for these schemas was rejected: the schema root is exactly where a contradicting branch
     * used to surface as an unrelated "No nested schema for composed property" failure.
     *
     * @return string[]|null
     */
    private static function getDeclaredTypeNames(PropertyInterface $property): ?array
    {
        return self::normalizeTypeNames($property->getJsonSchema()->getJson()['type'] ?? null);
    }

    /**
     * The generator-internal type names a branch declares in its own `type` keyword, or null for a
     * branch without one (including an always-true branch). The type is read from the JSON like
     * branchPermitsObjectValues() does, because PropertyInterface::getType() is null for an
     * explicit `{type: null}` branch, which would make it indistinguishable from an untyped one.
     *
     * Only the resolved schema is consulted: it is the branch schema itself for an inline branch
     * and the target for a `$ref` branch. Reading the branch schema first was rejected: keywords
     * next to a `$ref` are ignored by the drafts which suppress a reference's siblings, so a
     * `{"$ref": ..., "type": "string"}` branch would be read as a string branch although it is the
     * referenced object.
     *
     * @return string[]|null
     */
    private static function getBranchTypeNames(CompositionPropertyDecorator $branch): ?array
    {
        return self::normalizeTypeNames($branch->getJsonSchema()->getJson()['type'] ?? null);
    }

    /**
     * @param string|string[]|null $jsonType the value of a `type` keyword
     *
     * @return string[]|null
     */
    private static function normalizeTypeNames(string|array|null $jsonType): ?array
    {
        if ($jsonType === null) {
            return null;
        }

        return array_values(array_unique(array_map(
            static fn(string $typeName): string => $typeName === 'base'
                ? 'object'
                : TypeConverter::jsonSchemaToPHP($typeName),
            (array) $jsonType,
        )));
    }

    /**
     * Narrows the primitive non-null type names derived from the branches to the non-null names the
     * property declares itself, so the declared type takes part in the type hint instead of being
     * replaced by the branch types.
     *
     * Class names are passed through untouched. They are produced from the declared type, not
     * declared by it: the enum post processor rewrites the branches of a `type: string` property to
     * enum classes, and the property then returns such an enum object. Intersecting the class names
     * with the declared `string` would drop them, leaving a `?string` type hint on a getter which
     * returns an enum and throws a TypeError.
     *
     * @param string[] $nonNullNames
     *
     * @return string[]
     */
    private static function narrowToDeclaredType(PropertyInterface $property, array $nonNullNames): array
    {
        $declaredNonNullNames = array_values(array_filter(
            self::getDeclaredTypeNames($property) ?? [],
            static fn(string $typeName): bool => $typeName !== 'null',
        ));

        if ($declaredNonNullNames === []) {
            return $nonNullNames;
        }

        $primitiveNames = array_values(array_filter($nonNullNames, TypeCheck::isPrimitive(...)));
        $classNames = array_values(array_diff($nonNullNames, $primitiveNames));

        return array_merge(TypeIntersection::compute($primitiveNames, $declaredNonNullNames), $classNames);
    }

    /**
     * Retypes the `number` properties among $property and its composition branches once the
     * composition narrowed the type of $property to include `int`.
     *
     * A `number` property casts an int input to float and checks for a float before any other
     * validation runs, and the draft registers its range keywords (minimum, multipleOf, ...) behind
     * an `is_float` guard. Under a type which accepts `int` all of it is wrong: a `{type: integer}`
     * branch would see 42.0 and reject the value 42 which the narrowed type accepts, the float
     * check would reject the int that is left once the cast is gone, and the `is_float` guard never
     * holds for an int, so the range keywords would silently stop applying. The cast is therefore
     * removed and the draft's `integer` type modifiers are run, which build the type check and the
     * range keywords for an int, like PropertyMerger does when it narrows float to int:
     *
     * - Narrowed to `int` only (allOf with an integer branch, or anyOf / oneOf whose branches all
     *   admit int only): everything the draft's `number` type added is removed, the property
     *   becomes an integer property. The branches are retyped together with the property: a
     *   `number` branch hands its cast value on as the value of the property, so leaving its cast
     *   in place would store a float in a property whose type hint says `int`, and its range
     *   keywords would stop applying just like the property's.
     * - Narrowed to `int | float` (anyOf / oneOf with an integer and a number branch): the float
     *   check becomes an int | float check and the `number` range keywords stay next to the
     *   `integer` ones, so both an int and a float are checked. Only the property is changed: every
     *   anyOf / oneOf branch validates its own copy of the value, so a number branch may keep
     *   casting it, and the property stores the value of the first branch which matched.
     *
     * Rejected alternatives:
     * - Dropping the type check without a replacement: an anyOf narrowed to int by an `integer` and
     *   a `string` branch would then accept strings, since the string branch no longer meets a
     *   declared-type check.
     * - Swapping only the cast and the type check: leaves the range keywords behind their
     *   `is_float` guard, so `{type: number, minimum: 10, allOf: [{type: integer}]}` accepts 5.
     * - Widening the number guards of the draft to `is_int || is_float`: changes the generated code
     *   of every number property, and a custom draft would still carry the float-only guards.
     * - Hard-coding the range keywords: duplicates the draft registration and misses the keywords a
     *   custom draft registers for `number`.
     * - Keeping the cast of an `int | float` property and casting after the composition instead:
     *   needs a post-validation step the model template doesn't have, and the range keywords of the
     *   property would still have to be rebuilt for the raw int.
     *
     * Only a property which carries the cast is changed: a plain `number` property or branch, or a
     * multi-type property or branch whose `number` variant carries it (see
     * retypeMultiTypeNumberVariant()). The narrowed type is read from $property, so this runs
     * after transferPropertyType().
     *
     * @param CompositionPropertyDecorator[] $compositionProperties
     *
     * @throws SchemaException
     */
    protected static function retypeNumberPropertiesForNarrowedType(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        array $compositionProperties,
    ): void {
        $typeNames = $property->getType()?->getNames() ?? [];

        if (!in_array('int', $typeNames, true)) {
            return;
        }

        $keepFloat = in_array('float', $typeNames, true);

        foreach ($keepFloat ? [$property] : [$property, ...$compositionProperties] as $candidate) {
            $numberVariantTransfers = array_filter(
                $candidate->getDecorators(),
                static fn(PropertyDecoratorInterface $decorator): bool =>
                    $decorator instanceof PropertyTransferDecorator
                    && self::carriesIntToFloatCast($decorator->getProperty()),
            );

            if (!self::carriesIntToFloatCast($candidate) && $numberVariantTransfers === []) {
                continue;
            }

            // A branch is retyped on its own copy: a `$ref` branch is the shared property of the
            // referenced definition, retyping it in place would retype every other reference to it.
            if ($candidate instanceof CompositionPropertyDecorator) {
                $candidate->detachWrappedProperty();
            }

            if (self::carriesIntToFloatCast($candidate)) {
                self::retypeNumberProperty($schemaProcessor, $schema, $candidate, $keepFloat);
                continue;
            }

            foreach (self::detachNumberVariants($candidate) as $numberVariant) {
                self::retypeMultiTypeNumberVariant($schemaProcessor, $schema, $candidate, $numberVariant, $keepFloat);
            }
        }
    }

    /**
     * Replaces the transfer of every number variant of the multi-type $property by a transfer of a
     * copy of the variant, keeping the order of the decorators. The variant is shared with every
     * copy of the multi-type property (e.g. the definition a detached `$ref` branch was copied from),
     * so removing its cast in place would remove it there as well.
     *
     * @return PropertyInterface[] the copied number variants
     */
    private static function detachNumberVariants(PropertyInterface $property): array
    {
        $decorators = $property->getDecorators();
        $numberVariants = [];

        $property->filterDecorators(static fn(): bool => false);

        foreach ($decorators as $decorator) {
            if (
                $decorator instanceof PropertyTransferDecorator
                && self::carriesIntToFloatCast($decorator->getProperty())
            ) {
                $numberVariant = clone $decorator->getProperty();
                $numberVariants[] = $numberVariant;
                $decorator = new PropertyTransferDecorator($numberVariant);
            }

            $property->addDecorator($decorator);
        }

        return $numberVariants;
    }

    /**
     * Types a `number` property as int | float when one of its `if`, `then`, `else` or `not`
     * schemas declares `integer`, and retypes it like an anyOf / oneOf with an integer and a
     * number branch (see retypeNumberPropertiesForNarrowedType()). These keywords don't narrow the
     * type of the property, but they validate the same value: casting an int to float before them
     * made a `{"type": "integer"}` schema reject every int (`then`) or accept it (`not`), as the
     * generated integer type check accepts an int only. Not casting gives the int to the schema;
     * the property is typed int | float as it stores the int it got.
     *
     * Only a `type` the schema declares itself counts: a schema which inherits the `number` of the
     * property keeps the float property. Accepting a float without a fractional part in the
     * integer type check instead (JSON Schema counts 1.0 as an integer) was rejected here: it
     * changes every integer property, and an int property would then have to store the float as
     * an int.
     *
     * @param CompositionPropertyDecorator[] $compositionProperties
     *
     * @throws SchemaException
     */
    protected static function retypeNumberPropertyForIntegerSchemas(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        array $compositionProperties,
    ): void {
        $declaredTypeNames = self::getDeclaredTypeNames($property) ?? [];
        $type = $property->getType();

        if (
            !in_array('float', $declaredTypeNames, true)
            || in_array('int', $declaredTypeNames, true)
            || $type === null
            || $type->getNames() !== ($property->getType(true)?->getNames() ?? [])
        ) {
            return;
        }

        $hasIntegerSchema = array_filter(
            $compositionProperties,
            static fn(CompositionPropertyDecorator $branch): bool =>
                in_array('int', self::getBranchTypeNames($branch) ?? [], true),
        ) !== [];

        if (!$hasIntegerSchema) {
            return;
        }

        $typeNames = array_merge(['int'], $type->getNames());
        $property->setType(
            new PropertyType($typeNames, $type->isNullable()),
            new PropertyType($typeNames, $type->isNullable()),
        );

        self::retypeNumberPropertiesForNarrowedType($schemaProcessor, $schema, $property, []);
    }

    private static function carriesIntToFloatCast(PropertyInterface $property): bool
    {
        return array_filter(
            $property->getDecorators(),
            static fn(PropertyDecoratorInterface $decorator): bool => $decorator instanceof IntToFloatCastDecorator,
        ) !== [];
    }

    /**
     * @param bool $keepFloat true to retype to `int | float`, false to retype to `int`
     *
     * @throws SchemaException
     */
    private static function retypeNumberProperty(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $candidate,
        bool $keepFloat,
    ): void {
        $numberKeywords = $keepFloat ? [] : self::getNumberKeywords($schemaProcessor, $candidate);

        self::removeIntToFloatCast($candidate);
        $candidate->filterValidators(
            static fn(Validator $validator): bool =>
                !($validator->getValidator() instanceof TypeCheckInterface)
                && !in_array($validator->getSourceKey(), $numberKeywords, true),
        );

        if ($keepFloat) {
            // The integer type check modifier skips a property whose type check already accepts
            // an int, so it adds nothing next to this one.
            $candidate->addValidator(
                (new MultiTypeCheckValidator(
                    ['int', 'float'],
                    $candidate,
                    $schemaProcessor->getGeneratorConfiguration()->isImplicitNullAllowed()
                        && !$candidate->isRequired(),
                ))->withJsonPointer($candidate->getJsonSchema()->getPointer() . '/type'),
                2,
            );
        }

        self::applyIntegerTypeModifiers($schemaProcessor, $schema, $candidate);
    }

    /**
     * A multi-type property (`"type": ["number", ...]`) carries no cast itself: the cast sits on
     * its number variant, which the multi-type property reaches through a
     * PropertyTransferDecorator, while the validators of every variant were copied onto the
     * multi-type property, and one MultiTypeCheckValidator checks all declared types. The cast is
     * removed from the variant, and the type check is rebuilt with `int` in place of `float` (or
     * next to it, for `int | float`), keeping the other declared types. For `int` only, the copied
     * number validators are removed from the multi-type property as well. Replacing the check with
     * an int-only check was rejected: `["number", "string"]` narrowed by an `integer` and a
     * `string` branch has to keep accepting strings. The integer type modifiers then build the
     * range keywords for an int; their type check modifier adds nothing, as the rebuilt check
     * already accepts `int`.
     *
     * @param bool $keepFloat true to retype the variant to `int | float`, false to retype it to `int`
     *
     * @throws SchemaException
     */
    private static function retypeMultiTypeNumberVariant(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $multiTypeProperty,
        PropertyInterface $numberVariant,
        bool $keepFloat,
    ): void {
        $numberKeywords = $keepFloat ? [] : self::getNumberKeywords($schemaProcessor, $multiTypeProperty);
        $replacementTypeNames = $keepFloat ? ['int', 'float'] : ['int'];
        $multiTypeChecks = [];

        foreach ($multiTypeProperty->getValidators() as $validator) {
            if ($validator->getValidator() instanceof MultiTypeCheckValidator) {
                $multiTypeChecks[] = $validator;
            }
        }

        self::removeIntToFloatCast($numberVariant);
        $multiTypeProperty->filterValidators(
            static fn(Validator $validator): bool =>
                !($validator->getValidator() instanceof MultiTypeCheckValidator)
                && !in_array($validator->getSourceKey(), $numberKeywords, true),
        );

        foreach ($multiTypeChecks as $multiTypeCheck) {
            $multiTypeProperty->addValidator(
                $multiTypeCheck->getValidator()->withTypes(array_values(array_unique(array_merge(...array_map(
                    static fn(string $typeName): array => $typeName === 'float' ? $replacementTypeNames : [$typeName],
                    $multiTypeCheck->getValidator()->getTypes(),
                ))))),
                $multiTypeCheck->getPriority(),
            );
        }

        self::applyIntegerTypeModifiers($schemaProcessor, $schema, $multiTypeProperty);
    }

    /**
     * The keywords the draft registers validators for on the `number` type
     *
     * @return string[]
     */
    private static function getNumberKeywords(SchemaProcessor $schemaProcessor, PropertyInterface $property): array
    {
        $builtDraft = $schemaProcessor->getGeneratorConfiguration()->getBuiltDraft($property->getJsonSchema());

        foreach ($builtDraft->getCoveredTypes('number') as $coveredType) {
            if ($coveredType->getType() === 'number') {
                return array_values(array_filter(array_keys($coveredType->getModifiers()), is_string(...)));
            }
        }

        return [];
    }

    private static function removeIntToFloatCast(PropertyInterface $property): void
    {
        $property->filterDecorators(
            static fn(PropertyDecoratorInterface $decorator): bool => !($decorator instanceof IntToFloatCastDecorator),
        );
    }

    /**
     * @throws SchemaException
     */
    private static function applyIntegerTypeModifiers(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
    ): void {
        $propertySchema = $property->getJsonSchema();

        (new PropertyFactory())->applyTypeModifiers(
            $schemaProcessor,
            $schema,
            $property,
            $propertySchema->withJson(array_merge($propertySchema->getJson(), ['type' => 'integer'])),
        );
    }

    /**
     * Single entry point for "do these allOf branches contradict each other". allOf requires
     * every branch to hold simultaneously for the same value, so a contradiction can arise in two
     * distinct ways, depending on whether any branch resolved to a nested schema:
     *
     * - At least one branch has a nested schema: an object-ASSERTING branch (nested schema plus a
     *   non-null getType()) can never be satisfied at the same time as an explicit scalar-typed
     *   sibling branch — see assertNoObjectScalarTypeConflict(). A branch with a nested schema
     *   that does NOT assert object-ness (an object-describing branch, vacuously satisfied by
     *   non-objects) does not conflict with anything and is handled inside that check too.
     * - No branch has a nested schema: every typed branch's declared type must have a non-empty
     *   intersection with every other typed branch's — see assertScalarTypeIntersectionIsNonEmpty().
     *
     * These two cases are mutually exclusive, decided purely by whether any branch in
     * $compositionProperties carries a nested schema, so exactly one of the two checks below ever
     * runs for a given call. Unifying them behind one method - rather than reaching each
     * independently from a different call site, as before - keeps "are these allOf branches in
     * conflict" a single assertion topic with a single entry point.
     *
     * @param CompositionPropertyDecorator[] $compositionProperties
     *
     * @throws SchemaException when allOf branches declare conflicting types, including an
     *                          object-shaped branch conflicting with a scalar-typed branch.
     */
    private static function assertNoAllOfConflict(
        PropertyInterface $property,
        array $compositionProperties,
    ): void {
        foreach ($compositionProperties as $compositionProperty) {
            if ($compositionProperty->getNestedSchema() !== null) {
                self::assertNoObjectScalarTypeConflict($property, $compositionProperties);
                return;
            }
        }

        self::assertScalarTypeIntersectionIsNonEmpty($property, $compositionProperties);
    }

    /**
     * A branch that genuinely ASSERTS object-ness always requires the value to be an object.
     * allOf requires every branch to hold simultaneously for the same value, so any sibling
     * branch with an explicit scalar type (string, integer, number, boolean, array, or null) can
     * never be satisfied at the same time as an object-asserting branch — the schema is
     * unsatisfiable.
     *
     * A resolved nested schema alone does not mean a branch asserts object-ness: a guarded
     * (object-describing) branch - e.g. a bare `properties`/`required` shape with no `type` -
     * also gets a nested schema (PropertyFactory::createObjectProperty()), but is vacuously
     * satisfied by non-object values, so it does not conflict with a scalar sibling. The guarded
     * wiring PropertyFactory::createObjectProperty() applies for such a branch (an ObjectModifier
     * built with asserting: false) resets its type to null, while PropertyFactory::wireObjectProperty()
     * types an asserting branch as 'object' - getType() is therefore the signal that distinguishes
     * the two, not getNestedSchema() alone.
     *
     * This case is invisible to assertScalarTypeIntersectionIsNonEmpty()'s type intersection,
     * which only inspects branches with a scalar getType() and is only ever reached (via
     * assertNoAllOfConflict()) when no branch has a nested schema. Without this check the
     * conflict previously went undetected: at the schema root it instead surfaced as a confusing
     * generic "No nested schema for composed property" crash (the scalar branch has no nested
     * schema, which SchemaProcessor::transferComposedPropertiesToSchema() requires
     * unconditionally), and nested inside a property it produced no generation-time diagnostic at
     * all — only an allOf validator that rejects every possible input at runtime.
     *
     * @param CompositionPropertyDecorator[] $compositionProperties
     *
     * @throws SchemaException when a scalar-typed branch coexists with an object-asserting branch.
     */
    private static function assertNoObjectScalarTypeConflict(
        PropertyInterface $property,
        array $compositionProperties,
    ): void {
        $hasAssertingObjectBranch = array_filter(
            $compositionProperties,
            static fn(CompositionPropertyDecorator $compositionProperty): bool =>
                $compositionProperty->getNestedSchema() !== null && $compositionProperty->getType() !== null,
        ) !== [];

        if (!$hasAssertingObjectBranch) {
            return;
        }

        $hasConflictingScalarBranch = array_filter(
            $compositionProperties,
            static fn(CompositionPropertyDecorator $compositionProperty): bool =>
                $compositionProperty->getNestedSchema() === null
                && $compositionProperty->getType() !== null
                && !$compositionProperty->isAlwaysTrueBranch()
                && !self::branchPermitsObjectValues($compositionProperty),
        ) !== [];

        if ($hasConflictingScalarBranch) {
            self::throwConflictingAllOfTypesException($property);
        }
    }

    /**
     * Whether $branch's own declared `type` lists "object" among the types it accepts.
     *
     * A branch reaching assertNoObjectScalarTypeConflict()'s scalar filter has no nested schema,
     * which for a single-typed branch does mean it is scalar - but a MULTI-type declaration such
     * as `["object", "null"]` has none either: PropertyFactory::createMultiTypeProperty() sets the
     * nested schema on the object SUB-property it builds, and that sub-property is reachable only
     * through a PropertyTransferDecorator, never from the branch. Reading the declared type is
     * therefore the only way to tell a genuinely scalar branch from one that accepts objects too.
     * Without it, `allOf: [<object branch>, {"type": ["object", "null"]}]` - satisfiable by any
     * object, and the canonical "referenced type, but nullable" shape - is rejected as
     * unsatisfiable.
     *
     * Deliberately NOT decided via ObjectShapeResolver: that answers "does this branch ASSERT
     * object-ness", which a multi-type branch deliberately does not (it classifies as Blocking
     * there, precisely so a sibling is not routed through the object path on its account). The
     * question here is the weaker "can an object satisfy this branch at all", and conflating the
     * two is what produces the false rejection above.
     *
     * Both schemas are consulted because they carry the declaration for different branch shapes:
     * getBranchSchema() holds an inline `type`, while getJsonSchema() proxies to the resolved
     * target of a `$ref` branch, which is where that branch's `type` actually lives.
     */
    private static function branchPermitsObjectValues(CompositionPropertyDecorator $branch): bool
    {
        foreach ([$branch->getBranchSchema(), $branch->getJsonSchema()] as $branchSchema) {
            if (in_array('object', (array) ($branchSchema->getJson()['type'] ?? []), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws SchemaException
     */
    private static function throwConflictingAllOfTypesException(PropertyInterface $property): void
    {
        throw new SchemaException(
            sprintf(
                "Property '%s' is defined with conflicting types in allOf composition branches"
                    . ' (file %s): allOf requires all constraints to hold simultaneously,'
                    . ' making this schema unsatisfiable',
                $property->getName(),
                $property->getJsonSchema()->getFile(),
            ),
            $property->getJsonSchema(),
        );
    }

    /**
     * Reachable only when NO branch has a nested schema — assertNoAllOfConflict() only ever
     * delegates here for that case, the object-vs-scalar case being covered separately by
     * assertNoObjectScalarTypeConflict(). Throws when the intersection of all typed branches'
     * scalar types is empty and at least one branch does not allow null - i.e. no value can
     * satisfy every branch's type constraint simultaneously.
     *
     * @param CompositionPropertyDecorator[] $compositionProperties
     *
     * @throws SchemaException
     */
    private static function assertScalarTypeIntersectionIsNonEmpty(
        PropertyInterface $property,
        array $compositionProperties,
    ): void {
        $constrainingBranches = self::getConstrainingBranches($compositionProperties);

        if (empty($constrainingBranches)) {
            // No typed branches — no type constraint, so nothing can conflict.
            return;
        }

        [$nonNullNames, $allBranchesAllowNull] = self::intersectAllOfBranchTypes($constrainingBranches);

        if (empty($nonNullNames) && !$allBranchesAllowNull) {
            self::throwConflictingAllOfTypesException($property);
        }
    }

    /**
     * @param CompositionPropertyDecorator[] $compositionProperties
     *
     * @return CompositionPropertyDecorator[] The branches that declare a type keyword and
     *                                        therefore constrain the allOf type intersection.
     */
    private static function getConstrainingBranches(array $compositionProperties): array
    {
        return array_values(array_filter(
            $compositionProperties,
            static fn(CompositionPropertyDecorator $branch): bool => $branch->getType() !== null,
        ));
    }

    /**
     * Intersects the non-null type names across all $constrainingBranches and reports whether
     * every one of them also allows null. Shared by assertScalarTypeIntersectionIsNonEmpty()
     * (which only needs to know whether the intersection came out empty) and transferAllOfType()
     * (which needs the intersected names themselves to build the property's PropertyType), so the
     * intersection math itself has a single implementation.
     *
     * @param CompositionPropertyDecorator[] $constrainingBranches Branches with getType() !== null.
     *
     * @return array{0: string[], 1: bool} [nonNullNames, allBranchesAllowNull]
     */
    private static function intersectAllOfBranchTypes(array $constrainingBranches): array
    {
        // Intersection of non-null type names across all typed branches.
        // TypeIntersection::compute handles int ⊂ float (integer is a subtype of number in JSON Schema).
        $nonNullSets = array_map(
            static fn(CompositionPropertyDecorator $branch): array => array_values(array_filter(
                $branch->getType()->getNames(),
                static fn(string $typeName): bool => $typeName !== 'null',
            )),
            $constrainingBranches,
        );
        $nonNullNames = array_shift($nonNullSets);
        foreach ($nonNullSets as $typeSet) {
            $nonNullNames = TypeIntersection::compute($nonNullNames, $typeSet);
        }

        // Null is valid in allOf only when ALL typed branches allow it.
        $allBranchesAllowNull = count(array_filter(
            $constrainingBranches,
            static fn(CompositionPropertyDecorator $branch): bool =>
                in_array('null', $branch->getType()->getNames(), true)
                || $branch->getType()->isNullable() === true,
        )) === count($constrainingBranches);

        return [$nonNullNames, $allBranchesAllowNull];
    }

    /**
     * Derive and apply the parent property's type using allOf intersection semantics.
     *
     * Only typed branches (those that declare a type keyword) constrain the intersection.
     * Untyped branches impose no type restriction and are excluded. Null is valid only when
     * ALL typed branches allow it.
     *
     * Does not throw: an empty non-null intersection is already rejected by
     * assertBranchTypesAreSatisfiable(), called unconditionally before transferPropertyType(), so
     * by the time we get here an empty intersection can only mean "every branch allows null"
     * rather than a genuine conflict.
     *
     * @param CompositionPropertyDecorator[] $compositionProperties
     */
    private static function transferAllOfType(
        PropertyInterface $property,
        array $compositionProperties,
        bool $hasBranchWithOptionalProperty,
    ): void {
        $constrainingBranches = self::getConstrainingBranches($compositionProperties);

        if (empty($constrainingBranches)) {
            // No typed branches — no type constraint to apply.
            return;
        }

        [$nonNullNames, $allBranchesAllowNull] = self::intersectAllOfBranchTypes($constrainingBranches);

        if (empty($nonNullNames)) {
            // Only null survives the intersection; the null-processor path handles pure-null types.
            return;
        }

        $nonNullNames = self::narrowToDeclaredType($property, $nonNullNames);

        if (!$nonNullNames) {
            // The branches don't overlap the declared type: the allOf can never be satisfied. It is
            // only accepted inside a branch of another composition (see
            // assertBranchTypesAreSatisfiable()), where the property keeps its declared type.
            return;
        }

        $nullable = ($allBranchesAllowNull || $hasBranchWithOptionalProperty) ? true : null;
        $property->setType(new PropertyType($nonNullNames, $nullable));
    }

    /**
     * Derive and apply the parent property's type using anyOf/oneOf union semantics.
     *
     * Branches are partitioned into three categories:
     * - Typed (getType() !== null): contribute their names to the union.
     * - Explicit null-type ({type:null}): getType() is null but typeHint contains 'null';
     *   contributes nullable=true to the result.
     * - Truly untyped ({}): getType() is null and typeHint does not contain 'null'; the branch
     *   accepts every value, so the composition is always satisfiable and no type hint applies.
     *
     * A truly untyped branch causes early return without setting a type (property remains mixed),
     * matching the behaviour of PropertyMerger::mergeNullableBranch for object-level compositions.
     * An explicit null-type branch ({type:null}) is NOT treated as untyped — it adds nullable=true
     * to the typed union rather than removing the type hint.
     *
     * @param CompositionPropertyDecorator[] $compositionProperties
     */
    private static function transferAnyOfOneOfType(
        PropertyInterface $property,
        array $compositionProperties,
        bool $hasBranchWithOptionalProperty,
    ): void {
        $hasExplicitNullBranch = false;

        foreach ($compositionProperties as $compositionProperty) {
            if ($compositionProperty->getType() !== null) {
                continue;
            }

            if (str_contains($compositionProperty->getTypeHint(), 'null')) {
                // Explicit null-type branch ({type: null}): contributes nullable=true.
                $hasExplicitNullBranch = true;
            } else {
                // Truly untyped branch ({}): any value is valid, so the composition is
                // always satisfiable — no type hint is appropriate for this property.
                return;
            }
        }

        $typedBranches = array_values(array_filter(
            $compositionProperties,
            static fn(CompositionPropertyDecorator $branch): bool => $branch->getType() !== null,
        ));

        if (empty($typedBranches)) {
            // Only explicit null branches; no scalar type to build a union from.
            return;
        }

        $allNames = array_merge(...array_map(
            static fn(CompositionPropertyDecorator $branch): array => $branch->getType()->getNames(),
            $typedBranches,
        ));

        $hasNull = $hasExplicitNullBranch || in_array('null', $allNames, true);

        // Every branch is narrowed to the declared type on its own before the union is built.
        // Building the union first and narrowing it afterwards was rejected: an integer and a
        // number branch under a declared number would give [int, float] ∩ [float] = [float],
        // dropping the int the integer branch accepts (int is a subtype of float only within a
        // single intersection). Union of the narrowed branches is [int] ∪ [float] = [int, float].
        $nonNullNames = array_values(array_unique(array_merge(...array_map(
            static fn(CompositionPropertyDecorator $branch): array => self::narrowToDeclaredType(
                $property,
                array_values(array_filter(
                    $branch->getType()->getNames(),
                    static fn(string $typeName): bool => $typeName !== 'null',
                )),
            ),
            $typedBranches,
        ))));

        if (!$nonNullNames) {
            return;
        }

        $nullable = ($hasNull || $hasBranchWithOptionalProperty) ? true : null;
        $property->setType(new PropertyType($nonNullNames, $nullable));
    }
}
