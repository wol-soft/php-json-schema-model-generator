<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Composition;

use Closure;
use PHPModelGenerator\Draft\Draft;
use PHPModelGenerator\Draft\Producer\ExclusiveProducer;
use PHPModelGenerator\Exception\Generic\DeniedPropertyException;
use PHPModelGenerator\Exception\MessageFormatter;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\BaseProperty;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\ClearTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\CompositionTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\Filter\CompositionCompatibilityChecker;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\TypeIntersection;

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
     *   array by the branch processing that follows.
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
            if (!is_array($branch) && !is_bool($branch)) {
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
     * TypeError from JsonSchema rather than a schema error naming the offending keyword.
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
            if (!array_key_exists($keyword, $json) || is_array($json[$keyword]) || is_bool($json[$keyword])) {
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
                    "Composition branch %s for '%s' in file '%s' is forced to type %s by the inherited parent type,"
                        . " but its 'enum'/'const' contains no value of that type: the allOf composition can never"
                        . ' be satisfied',
                    $branchLabel,
                    $property->getName(),
                    $propertySchema->getFile(),
                    self::formatTypes($branchJson['type']),
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
                'type' => self::formatTypes($branchJson['type']),
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
     * Render the 'type' of a branch for a message: 'object', or ['string', 'null'] for a type list
     * (the same notation InvalidTypeException uses for its expected types).
     *
     * @param string|string[] $types
     */
    private static function formatTypes(string | array $types): string
    {
        return is_array($types) ? '[' . MessageFormatter::quotedList($types) . ']' : "'$types'";
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
        $json = $propertySchema->getJson()['propertySchema']->getJson();
        $draft = $schemaProcessor->getGeneratorConfiguration()->getBuiltDraft(
            $propertySchema->getJson()['propertySchema'],
        );

        $property->addTypeHintDecorator(new ClearTypeHintDecorator());

        foreach ($json[$this->key] as $index => $compositionElement) {
            if ($compositionElement === false) {
                $compositionProperties[] = $this->createAlwaysFalseBranchProperty(
                    $schemaProcessor,
                    $schema,
                    $property,
                    $propertySchema->getJson()['propertySchema'],
                );
                continue;
            }

            if ($compositionElement === true) {
                $trueBranchProperty = $this->createAlwaysTrueBranchProperty(
                    $schemaProcessor,
                    $schema,
                    $property,
                    $propertySchema->getJson()['propertySchema'],
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

            $compositionSchema = $propertySchema->getJson()['propertySchema']->navigate("$this->key/$index");

            $compositionProperty = new CompositionPropertyDecorator(
                $property->getName(),
                $compositionSchema,
                $propertyFactory->create(
                    $schemaProcessor,
                    $schema,
                    $property->getName(),
                    $compositionSchema,
                    $property->isRequired(),
                ),
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

            // RequiredPropertyValidator/InstanceOfValidator-for-empty-object exclusion for this
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
        );

        $presenceCheck = $property instanceof BaseProperty
            ? 'true'
            : "array_key_exists('" . addslashes($property->getName()) . "', \$modelData)";

        // RequiredPropertyValidator exclusion for this branch is handled by
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
        );

        $branchProperty->markAsAlwaysTrueBranch();

        // No validator added — true schema always succeeds.
        // No type hint decorator — true schema contributes no type constraint.
        // RequiredPropertyValidator exclusion for this branch is handled by
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
                if (!isset($json[$this->key]['type'])) {
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
                    $this->inheritIfPropertyType($schemaProcessor, $property, $propertySchema->withJson($json)),
                    $injectedTypeBranchIndices,
                ];
            default:
                foreach ($json[$this->key] as $index => &$composedElement) {
                    if (!is_bool($composedElement) && !isset($composedElement['type'])) {
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
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): JsonSchema {
        $json = $propertySchema->getJson();

        foreach (['if', 'then', 'else'] as $keyword) {
            if (!isset($json[$keyword]) || is_bool($json[$keyword])) {
                continue;
            }

            if (!isset($json[$keyword]['type'])) {
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
     * After all composition branches resolve, derive the parent property's type from the
     * branch types and apply it. Skips when any branch has a nested schema (object merging
     * is handled elsewhere) - see assertNoAllOfConflict() for the allOf conflict check, which
     * is asserted up front and unconditionally, independently of whether type transfer itself
     * proceeds or is skipped for this reason.
     *
     * allOf: intersect all typed branch types — only values satisfying every branch simultaneously
     * are valid, so the PHP type is the intersection. Branches with no declared type impose no
     * constraint and are excluded from the intersection. An empty intersection (contradictory
     * branch types) throws SchemaException because no value can ever be valid.
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
     *
     * @throws SchemaException when allOf branches declare conflicting types, including an
     *                          object-shaped branch conflicting with a scalar-typed branch.
     */
    public static function transferPropertyType(
        PropertyInterface $property,
        array $compositionProperties,
        bool $isAllOf,
    ): void {
        // Conflict detection is a single topic regardless of branch shape, so it is asserted
        // once, up front, from this one call site - see assertNoAllOfConflict(). This is
        // deliberately unconditional with respect to the nested-schema early return just below:
        // that early return is about type TRANSFER (there is nothing to compute a PHP type from
        // when object merging owns the branch), not about conflict detection, and keeping the two
        // concerns apart is what lets one method own the entire "are these allOf branches in
        // conflict" question instead of it being reachable from two different places.
        if ($isAllOf) {
            self::assertNoAllOfConflict($property, $compositionProperties);
        }

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
                    . ' (file %s). allOf requires all constraints to hold simultaneously,'
                    . ' making this schema unsatisfiable.',
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
     * assertNoAllOfConflict(), called unconditionally before transferPropertyType() ever reaches
     * this method, so by the time we get here an empty intersection can only mean "every branch
     * allows null" rather than a genuine conflict.
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
        $nonNullNames = array_values(array_filter(
            array_unique($allNames),
            static fn(string $typeName): bool => $typeName !== 'null',
        ));

        if (!$nonNullNames) {
            return;
        }

        $nullable = ($hasNull || $hasBranchWithOptionalProperty) ? true : null;
        $property->setType(new PropertyType($nonNullNames, $nullable));
    }
}
