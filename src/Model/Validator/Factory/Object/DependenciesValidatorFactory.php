<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Object;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\DeniedPropertyValidator;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Model\Validator\PropertyDependencyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\Model\Validator\SchemaDependencyValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\SchemaNamespaceTransferDecorator;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\JsonSchema as JsonSchemaUtil;

class DependenciesValidatorFactory extends AbstractValidatorFactory
{
    /**
     * @throws SchemaException
     */
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $json = $propertySchema->getJson();

        if (empty($json['dependencies'])) {
            return;
        }

        $propertyFactory = new PropertyFactory();

        foreach ($json['dependencies'] as $triggerName => $dependency) {
            $triggerName = (string) $triggerName;
            // A boolean dependency value is navigated to as a boolean schema.
            $dependencyJsonSchema = $propertySchema->navigate(
                'dependencies/' . JsonSchemaUtil::encodePointer($triggerName),
            );
            $dependencyPointer = $dependencyJsonSchema->getPointer();

            if (($json['properties'][$triggerName] ?? null) === false) {
                throw new SchemaException(
                    sprintf(
                        "Property '%s' is denied (schema false) but also has dependencies defined in file %s",
                        $triggerName,
                        $propertySchema->getFile(),
                    ),
                    $dependencyJsonSchema,
                );
            }

            // The dependency schema true accepts every value: nothing to enforce, so an undeclared trigger isn't
            // promoted to a property either (the promotion only provides the property carrying the validator).
            if ($dependency === true) {
                continue;
            }

            if ($dependency === false && in_array($triggerName, $json['required'] ?? [], true)) {
                throw new SchemaException(
                    sprintf(
                        "Property '%s' has the dependency false and must not be provided, but is also listed as"
                            . ' required in file %s',
                        $triggerName,
                        $propertySchema->getFile(),
                    ),
                    $dependencyJsonSchema,
                );
            }

            $unreachableReason = $this->getUnreachableTriggerReason($schemaProcessor, $json, $triggerName);
            if ($unreachableReason !== null) {
                $schemaProcessor->getGeneratorConfiguration()->getLogger()->warning(
                    "Dependencies on '{trigger}' in class '{class}' can never apply: {reason}",
                    [
                        'trigger' => $triggerName,
                        'class' => $schema->getClassName(),
                        'reason' => $unreachableReason,
                    ],
                );

                continue;
            }

            $triggerProperty = $schema->getProperty($triggerName);
            if ($triggerProperty === null) {
                $triggerProperty = $propertyFactory->create(
                    $schemaProcessor,
                    $schema,
                    $triggerName,
                    $propertySchema->withJson([]),
                );
                $schema->addProperty($triggerProperty);
            }

            if ($this->isArrayFormDependency($dependency)) {
                $this->synthesiseMissingTargets(
                    $dependency,
                    $propertySchema,
                    $schemaProcessor,
                    $schema,
                    $propertyFactory,
                );
                // A trigger declared via $ref is recycled for every reference to the definition; the dependency
                // belongs to this schema only.
                $schema->withOwnedProperty(
                    $triggerName,
                    static fn(PropertyInterface $ownedTrigger): PropertyInterface => $ownedTrigger->addValidator(
                        (new PropertyDependencyValidator($ownedTrigger, $dependency))
                            ->withJsonPointer($dependencyPointer),
                    ),
                );
                continue;
            }

            // The dependency schema false only applies while the trigger is present, so it is equivalent to a
            // dependency schema denying the trigger: processed as such, the violation is reported like any other
            // schema dependency (an InvalidSchemaDependencyException wrapping the DeniedPropertyException of the
            // trigger). A validator on the schema throwing a bare DeniedPropertyException was rejected: it reports
            // the trigger as if the schema denied it directly and hides that the dependency caused the rejection.
            if ($dependency === false) {
                $dependencyJsonSchema = $dependencyJsonSchema->withJson(['properties' => [$triggerName => false]]);
            }

            // The dependency schema is validated against the object which holds the property, so an untyped dependency
            // is an object schema, and a type list which includes "object" is narrowed to "object": the other members
            // of the list can never apply. Rejecting such a type list was considered and dropped, as it refuses a valid
            // schema which behaves exactly like the narrowed one.
            $dependencyJson = $dependencyJsonSchema->getJson();
            $declaredType = $dependencyJson['type'] ?? null;
            if ($declaredType === null || (is_array($declaredType) && in_array('object', $declaredType, true))) {
                $dependencyJsonSchema = $dependencyJsonSchema->withJson(['type' => 'object'] + $dependencyJson);
            }

            $dependencySchema = $schemaProcessor->processSchema(
                $dependencyJsonSchema,
                $schema->getClassPath(),
                "{$schema->getClassName()}_{$triggerName}_Dependency",
                $schema->getSchemaDictionary(),
            );

            // processSchema() skips a schema which neither declares "type": "object" nor carries a composition or a
            // reference, so only a dependency whose declared type excludes "object" ends up here. Such a schema is
            // valid JSON Schema: no object satisfies it, so it forbids the property. The generator doesn't map it onto
            // the denial of a `false` dependency, as a type which can never apply is far more likely a mistake than a
            // way to forbid the property, so it is rejected as unsupported, pointing at `false` as the explicit
            // spelling.
            if ($dependencySchema === null) {
                throw new SchemaException(
                    sprintf(
                        "Schema dependency of property '%s' in file %s declares %s, which the object holding the"
                            . ' property can never satisfy: a schema dependency which only forbids the property is not'
                            . ' supported, declare the dependency as false instead',
                        $triggerName,
                        $dependencyJsonSchema->getFile(),
                        $this->formatDeclaredTypes($dependencyJsonSchema->getJson()['type']),
                    ),
                    $dependencyJsonSchema,
                );
            }

            if ($dependency === false) {
                $this->locateDenialAtTheFalseSchema($dependencySchema, $dependencyPointer, $triggerName);
            }

            // Attached to the trigger property, not as a schema-level base validator: base validators only run
            // for the constructor, so a setter or a removal of an additional property would skip the dependency.
            // A trigger declared via $ref is recycled for every reference to the definition (a PropertyProxy
            // delegates addValidator() to that shared property), so the validator goes through
            // withOwnedProperty() to keep it from landing on the other references.
            $schema->withOwnedProperty(
                $triggerName,
                static fn(PropertyInterface $ownedTrigger): PropertyInterface => $ownedTrigger->addValidator(
                    (new SchemaDependencyValidator($schemaProcessor, $ownedTrigger, $dependencySchema))
                        ->withJsonPointer($dependencyJsonSchema->getPointer()),
                ),
            );
            $schema->addNamespaceTransferDecorator(new SchemaNamespaceTransferDecorator($dependencySchema));

            $this->transferDependencySchemaPropertiesToBaseSchema($dependencySchema, $schema);

            // The denied trigger is the only property of the dependency schema false, which evaluates nothing.
            if ($dependency === false) {
                continue;
            }

            // The dependent subschema is a positive applicator at the same instance location as the schema that
            // declares it, so whatever it evaluates is evaluated there too and a sibling `unevaluatedProperties` must
            // credit it — while the trigger property is present.
            $schema->addDependentEvaluatedProperties(
                $triggerName,
                array_map(strval(...), array_keys($dependencyJson['properties'] ?? [])),
                isset($dependencyJson['additionalProperties']) && $dependencyJson['additionalProperties'] !== false,
            );

            $this->recordDependentContributionShortfall($schema, $triggerName, $dependencyJson);
        }
    }

    /**
     * The names a dependent subschema contributes are read from its own `properties`, which is every name it declares
     * directly but nothing it reaches through a composition or a reference, and nothing its `patternProperties` match
     * — a pattern match is only credited through the enclosing class's own `_patternProperties` record, which the
     * dependent subschema's pattern validator never writes (see Schema::addDependentEvaluatedProperties()).
     *
     * The reach-through gap cannot be closed the way the enclosing schema's own compositions are, by reading their
     * outcome back at runtime: the dependency validator runs during the trigger property's validation pass, which is
     * *after* the post-composition phase where unevaluatedProperties evaluates, so no dependency result exists yet
     * when the accumulator is built. Harvesting the nested names statically instead is only sound for a conjunctive
     * `allOf`; for `anyOf`, `oneOf` and `if`/`then`/`else` it would credit branches that never ran — the exact
     * over-crediting the branch classes' runtime slots exist to avoid. So the shortfall is reported rather than
     * papered over: the effect is over-rejection of a spec-valid instance, which is loud at runtime, and the warning
     * names where it comes from.
     *
     * Only recorded here. Whether an accumulator consults this schema is not known yet — a composition branch gets its
     * accumulator from the schema it belongs to, and the draft decides whether `unevaluatedProperties` exists at all —
     * so UnevaluatedPropertiesPostProcessor reports it once it does. Deciding by the schema's own
     * `unevaluatedProperties` key at this point missed every branch and warned on drafts which ignore the keyword.
     *
     * @param array<string, mixed> $dependentJson The dependent subschema as written
     */
    private function recordDependentContributionShortfall(
        Schema $schema,
        string $triggerName,
        array $dependentJson,
    ): void {
        $uncreditedKeywords = array_values(array_filter(
            ['allOf', 'anyOf', 'oneOf', 'if', '$ref'],
            static fn(string $keyword): bool => array_key_exists($keyword, $dependentJson),
        ));

        // Declared directly, so not a reach-through, but uncredited all the same. An empty list evaluates nothing and
        // is not worth a warning.
        if (($dependentJson['patternProperties'] ?? []) !== []) {
            $uncreditedKeywords[] = 'patternProperties';
        }

        if ($uncreditedKeywords !== []) {
            $schema->addDependentContributionShortfall($triggerName, $uncreditedKeywords);
        }
    }

    private function formatDeclaredTypes(mixed $declaredType): string
    {
        $types = array_map(
            static fn(mixed $type): string => is_string($type) ? $type : (string) json_encode($type),
            (array) $declaredType,
        );

        return sprintf("the %s '%s'", count($types) === 1 ? 'type' : 'types', implode("', '", $types));
    }

    /**
     * Returns why the trigger key can never be part of an input of this schema, or null when it can.
     *
     * Decided on the raw schema JSON, not on $schema->getProperty(): a property registered for the key by
     * the required-stub mechanism or a composition transfer does not make the key acceptable, the schema's
     * own additionalProperties still rejects it.
     */
    private function getUnreachableTriggerReason(
        SchemaProcessor $schemaProcessor,
        array $json,
        string $triggerName,
    ): ?string {
        if (array_key_exists($triggerName, $json['properties'] ?? [])) {
            return null;
        }

        // Mirrors AdditionalPropertiesValidatorFactory: an absent keyword is flipped to false by the
        // denyAdditionalProperties() configuration, an explicit value is never overridden.
        if (isset($json['additionalProperties'])) {
            $reason = 'additionalProperties: false rejects the undeclared key';
            $rejectsAdditionalProperties = $json['additionalProperties'] === false;
        } else {
            $reason = 'denyAdditionalProperties() rejects the undeclared key';
            $rejectsAdditionalProperties = $schemaProcessor->getGeneratorConfiguration()->denyAdditionalProperties();
        }

        if (!$rejectsAdditionalProperties) {
            return null;
        }

        // A key matching a patternProperties regex is not an additional property.
        foreach (array_keys($json['patternProperties'] ?? []) as $pattern) {
            if (preg_match('/' . addcslashes((string) $pattern, '/') . '/', $triggerName) === 1) {
                return null;
            }
        }

        return $reason;
    }

    private function isArrayFormDependency(mixed $dependency): bool
    {
        if (!is_array($dependency)) {
            return false;
        }

        foreach ($dependency as $index => $value) {
            if (!is_int($index) || !is_string($value)) {
                return false;
            }
        }

        return true;
    }

    private function synthesiseMissingTargets(
        array $targetNames,
        JsonSchema $propertySchema,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyFactory $propertyFactory,
    ): void {
        foreach ($targetNames as $targetName) {
            if ($schema->getProperty($targetName) !== null) {
                continue;
            }

            $schema->addProperty(
                $propertyFactory->create(
                    $schemaProcessor,
                    $schema,
                    $targetName,
                    $propertySchema->withJson([]),
                ),
            );
        }
    }

    /**
     * The denial of the trigger in the rewritten dependency schema points at /properties/<trigger> inside it, a
     * location which doesn't exist in the schema file: the value was rejected by the schema false at the dependency.
     *
     * Only a validator still carrying the rewritten location is relocated. processSchema() reuses the dependency
     * schema for an identical `false` dependency elsewhere (same trigger name); relocating unconditionally would move
     * the pointer of the first occurrence to the second one. Like every other reused schema, the reused one keeps
     * the location of its first occurrence.
     */
    private function locateDenialAtTheFalseSchema(
        Schema $dependencySchema,
        string $dependencyPointer,
        string $triggerName,
    ): void {
        $rewrittenPointer = $dependencyPointer . '/properties/' . JsonSchemaUtil::encodePointer($triggerName);

        $dependencySchema->replaceBaseValidators(
            static fn(PropertyValidatorInterface $validator): PropertyValidatorInterface =>
                $validator instanceof DeniedPropertyValidator && $validator->getJsonPointer() === $rewrittenPointer
                    ? $validator->withJsonPointer($dependencyPointer)
                    : $validator,
        );
    }

    /**
     * A property which the base schema declares keeps the declaration of the base schema.
     *
     * A declaration of the dependency schema with a type disjoint from the base declaration is NOT rejected as a
     * contradictory schema (unlike disjoint allOf branches): the dependency schema only applies while the trigger is
     * present, so such a schema is satisfiable and only rejects data containing the trigger and the property.
     */
    private function transferDependencySchemaPropertiesToBaseSchema(Schema $dependencySchema, Schema $schema): void
    {
        foreach ($dependencySchema->getProperties() as $dependencyProperty) {
            if ($schema->getProperty($dependencyProperty->getName()) !== null) {
                continue;
            }

            $schema->addProperty(
                (clone $dependencyProperty)
                    ->setRequired(false)
                    ->setType(null)
                    ->filterValidators(static fn(): bool => false),
            );
        }
    }
}
