<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Composition;

use PHPModelGenerator\Exception\Generic\DeniedPropertyException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\BaseProperty;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\ConditionalPropertyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\PropertyProcessor\Filter\CompositionCompatibilityChecker;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;
use PHPModelGenerator\Utils\TypeIntersection;

class IfValidatorFactory
    extends AbstractCompositionValidatorFactory
    implements ComposedPropertiesValidatorFactoryInterface
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
        if (!isset($propertySchema->getJson()[$this->key]) || $this->shouldSkip($property, $propertySchema)) {
            return;
        }

        $json = $propertySchema->getJson();

        if (!isset($json['then']) && !isset($json['else'])) {
            throw new SchemaException(
                sprintf(
                    'Incomplete conditional composition for property %s in file %s',
                    $property->getName(),
                    $property->getJsonSchema()->getFile(),
                ),
            );
        }

        $json = $this->resolveBooleanBranches($json, $property, $schemaProcessor);

        if ($json === null) {
            return;
        }

        // Inherit the parent type into if/then/else sub-schemas before the filter check so
        // that sub-schemas that inherit 'object' are correctly recognised as object-typed.
        // Object-typed sub-schemas create nested schemas whose properties are processed
        // independently and are not subject to ComposedItem $value reset.
        $propertySchema = $this->inheritPropertyType($propertySchema->withJson($json));
        $json = $propertySchema->getJson();

        // Check for filter keywords in if/then/else sub-schemas after type inheritance.
        // TODO: filters inside if/then/else sub-schemas cannot be correctly applied
        // (ComposedItem.phptpl resets $value to $originalModelData after each branch).
        // Proper per-branch filter chaining is deferred to a follow-up topic.
        foreach (['if', 'then', 'else'] as $keyword) {
            if (
                isset($json[$keyword])
                && is_array($json[$keyword])
                && CompositionCompatibilityChecker::branchContainsFilter($json[$keyword])
            ) {
                throw new SchemaException(sprintf(
                    'A filter keyword inside an if/then/else composition branch is not supported'
                        . ' for property %s in file %s (%s sub-schema).',
                    $property->getName(),
                    $property->getJsonSchema()->getFile(),
                    $keyword,
                ));
            }
        }

        $propertyFactory = new PropertyFactory();

        $onlyForDefinedValues = !($property instanceof BaseProperty)
            && (!$property->isRequired()
                && $schemaProcessor->getGeneratorConfiguration()->isImplicitNullAllowed());

        /** @var array<string, CompositionPropertyDecorator|null> $properties */
        $properties = [];

        foreach (['if', 'then', 'else'] as $keyword) {
            if (!isset($json[$keyword])) {
                $properties[$keyword] = null;
                continue;
            }

            if ($json[$keyword] === false) {
                $properties[$keyword] = $this->createAlwaysFailingBranchProperty(
                    $schemaProcessor,
                    $schema,
                    $property,
                    $propertySchema,
                );
                continue;
            }

            $compositionSchema = $propertySchema->navigate($keyword);

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

            $compositionProperty->onResolve(static function () use ($compositionProperty): void {
                $compositionProperty->filterValidators(
                    static fn(Validator $validator): bool =>
                        !is_a($validator->getValidator(), RequiredPropertyValidator::class) &&
                        !is_a($validator->getValidator(), ComposedPropertyValidator::class),
                );
            });

            $properties[$keyword] = $compositionProperty;
        }

        $this->applyIfThenElseTypeSemantics($property, $properties);

        $property->addValidator(
            new ConditionalPropertyValidator(
                $schemaProcessor->getGeneratorConfiguration(),
                $property,
                array_values(array_filter($properties)),
                array_values(array_filter([$properties['then'], $properties['else']])),
                [
                    'ifProperty' => $properties['if'],
                    'thenProperty' => $properties['then'],
                    'elseProperty' => $properties['else'],
                    'schema' => $schema,
                    'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                    'viewHelper' => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
                    'onlyForDefinedValues' => $onlyForDefinedValues,
                ],
            ),
            100,
        );
    }

    /**
     * Create a composition branch that always fails, used for boolean `false` if/then/else branches.
     *
     * Unlike the allOf/anyOf/oneOf false-branch (which uses array_key_exists to guard absent
     * optional properties), here the outer ConditionalComposedItem template's onlyForDefinedValues
     * guard already prevents the entire conditional from running for absent properties. So the
     * branch itself just needs to always throw regardless of the value.
     */
    private function createAlwaysFailingBranchProperty(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): CompositionPropertyDecorator {
        $propertyFactory = new PropertyFactory();
        $branchSchema = $propertySchema->withJson([]);

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

        $branchProperty->onResolve(function () use ($branchProperty): void {
            $branchProperty->filterValidators(
                static fn(Validator $validator): bool =>
                    !is_a($validator->getValidator(), RequiredPropertyValidator::class) &&
                    !is_a($validator->getValidator(), ComposedPropertyValidator::class),
            );
            $branchProperty->addValidator(
                new PropertyValidator(
                    $branchProperty,
                    'true',
                    DeniedPropertyException::class,
                ),
            );
        });

        return $branchProperty;
    }

    /**
     * Resolve boolean `if`/`then`/`else` branches into concrete schema arrays or return-null signals.
     *
     * Returns null when the entire if/then/else imposes no constraint and modify() should return
     * early. Returns the (possibly rewritten) $json array otherwise. Always-false branches are left
     * as boolean false values in the returned array so the foreach loop in modify() can handle them.
     *
     * @throws SchemaException
     */
    private function resolveBooleanBranches(
        array $json,
        PropertyInterface $property,
        SchemaProcessor $schemaProcessor,
    ): ?array {
        if (is_bool($json['if'])) {
            if ($json['if'] === false) {
                if (!isset($json['else'])) {
                    if (isset($json['then']) && $schemaProcessor->getGeneratorConfiguration()->isOutputEnabled()) {
                        // @codeCoverageIgnoreStart
                        echo "Warning: if: false for property '{$property->getName()}'"
                            . " — then branch will never apply (condition never matches); no constraint generated.\n";
                        // @codeCoverageIgnoreEnd
                    }
                    return null;
                }

                if ($json['else'] === true) {
                    return null;
                }

                if ($json['else'] === false) {
                    $this->warnIfAlwaysFalse(
                        $schemaProcessor,
                        $property,
                        'if: false with else: false means the composition is always unsatisfiable',
                    );
                    // Rewrite as if: {} (always passes), then: false (always fails).
                    // The false then-branch is handled in the foreach loop below.
                    $json['if'] = [];
                    $json['then'] = false;
                    unset($json['else']);
                    return $json;
                }

                // Rewrite if: false, else: X as if: {}, then: X.
                // An empty if schema always passes so then always applies.
                // The ConditionalException will say "Condition: Valid" which is accurate
                // for if: {} but won't mention "else"; the message still correctly names
                // the failing branch constraint.
                $json['if'] = [];
                $json['then'] = $json['else'];
                unset($json['else']);

                return $json;
            }

            if (!isset($json['then'])) {
                if (isset($json['else']) && $schemaProcessor->getGeneratorConfiguration()->isOutputEnabled()) {
                    // @codeCoverageIgnoreStart
                    echo "Warning: if: true for property '{$property->getName()}'"
                        . " — else branch will never apply (condition always matches); no constraint generated.\n";
                    // @codeCoverageIgnoreEnd
                }
                return null;
            }

            if ($json['then'] === true) {
                return null;
            }

            if ($json['then'] === false) {
                $this->warnIfAlwaysFalse(
                    $schemaProcessor,
                    $property,
                    'if: true with then: false means the composition is always unsatisfiable',
                );
            }

            // Rewrite if: true, then: Y as if: {}, then: Y (removing else — it never applies).
            // If then is false the false-branch is handled in the foreach loop below.
            $json['if'] = [];
            unset($json['else']);

            return $json;
        }

        if (isset($json['then']) && is_bool($json['then'])) {
            if ($json['then'] === false) {
                throw new SchemaException(
                    sprintf(
                        'then: false is unsatisfiable for property %s in file %s',
                        $property->getName(),
                        $property->getJsonSchema()->getFile(),
                    ),
                );
            }

            unset($json['then']);
        }

        if (isset($json['else']) && is_bool($json['else'])) {
            if ($json['else'] === false) {
                throw new SchemaException(
                    sprintf(
                        'else: false is unsatisfiable for property %s in file %s',
                        $property->getName(),
                        $property->getJsonSchema()->getFile(),
                    ),
                );
            }

            unset($json['else']);
        }

        if (!isset($json['then']) && !isset($json['else'])) {
            return null;
        }

        return $json;
    }

    /**
     * Apply type widening and conflict detection for property-level if/then/else.
     *
     * When both then and else branches are present, a coordinated callback fires after both
     * resolve to:
     * - Detect unsatisfiable schemas: if the parent property has a declared type that is
     *   incompatible (empty intersection) with BOTH then and else types, throw SchemaException.
     * - Widen the property type: when the parent property has no declared type, compute the
     *   union of then and else types (anyOf-like semantics) and apply it to the parent property.
     *
     * If else is absent, the absent branch accepts any value when `if` evaluates to false, so
     * the composition cannot constrain the type further; widening is skipped (property stays
     * mixed). Conflict detection also requires both branches to be present — a single conflicting
     * branch does not make the schema unsatisfiable.
     *
     * The parent type is captured at call time (before branch resolution) to avoid reading a
     * value that may have been mutated by concurrent onResolve side-effects.
     *
     * @param array<string, CompositionPropertyDecorator|null> $properties
     *
     * @throws SchemaException
     */
    private function applyIfThenElseTypeSemantics(
        PropertyInterface $property,
        array $properties,
    ): void {
        $thenProperty = $properties['then'];
        $elseProperty = $properties['else'];

        if ($thenProperty === null || $elseProperty === null) {
            // Absent branch = untyped (accepts any value on that path) — no type widening or
            // conflict detection possible without both branches.
            return;
        }

        // Capture before any branch-resolution callback can mutate the parent type.
        $originalParentType = $property->getType(true);

        $resolvedCount = 0;
        $onBothResolved = function () use (
            &$resolvedCount,
            $property,
            $thenProperty,
            $elseProperty,
            $originalParentType,
        ): void {
            $resolvedCount++;
            if ($resolvedCount < 2) {
                return;
            }

            // Object-level if/then/else creates nested schemas in the branches; type merging for
            // that case is owned by PropertyMerger. Skip here to avoid false conflict detection
            // (the parent 'object' type and branch generated-class types appear disjoint to
            // TypeIntersection::compute even though they are semantically compatible).
            if ($thenProperty->getNestedSchema() !== null || $elseProperty->getNestedSchema() !== null) {
                return;
            }

            if ($originalParentType !== null) {
                $this->detectIfThenElseConflict(
                    $property,
                    $originalParentType,
                    $thenProperty,
                    $elseProperty,
                );
                // Parent type constrains the property independently; widening is not applied.
                return;
            }

            // No parent type: widen to the union of then and else types (anyOf-like semantics).
            $this->transferPropertyType($property, [$thenProperty, $elseProperty], false);
        };

        $thenProperty->onResolve($onBothResolved);
        $elseProperty->onResolve($onBothResolved);
    }

    /**
     * Throw a SchemaException when the parent property's declared type is incompatible with
     * BOTH the then and the else branch types (empty intersection on both sides).
     *
     * A schema is unsatisfiable under this condition: no value can simultaneously satisfy the
     * parent type constraint and whichever composition branch fires. The schema is NOT
     * unsatisfiable when only one branch conflicts (values that satisfy the compatible branch
     * are still valid).
     *
     * @throws SchemaException
     */
    private function detectIfThenElseConflict(
        PropertyInterface $property,
        PropertyType $originalParentType,
        CompositionPropertyDecorator $thenProperty,
        CompositionPropertyDecorator $elseProperty,
    ): void {
        // isNullable() encodes the null part of a multi-type (e.g. ["string","null"]) separately
        // from getNames() — it is not present as a name string. Append 'null' so that a
        // null-typed branch is not incorrectly flagged as conflicting with a nullable parent.
        $parentNames = $originalParentType->isNullable()
            ? [...$originalParentType->getNames(), 'null']
            : $originalParentType->getNames();

        $thenTypes = $this->getBranchTypeNames($thenProperty);
        $elseTypes = $this->getBranchTypeNames($elseProperty);

        // A null return means the branch is truly untyped (no type keyword) — it accepts
        // any value and cannot conflict with the parent type.
        $thenConflicts = $thenTypes !== null
            && empty(TypeIntersection::compute($parentNames, $thenTypes));
        $elseConflicts = $elseTypes !== null
            && empty(TypeIntersection::compute($parentNames, $elseTypes));

        if ($thenConflicts || $elseConflicts) {
            throw new SchemaException(sprintf(
                "Property '%s' has an if/then/else composition branch with a type incompatible"
                    . " with the property's declared type (file %s)."
                    . ' No value can satisfy both constraints.',
                $property->getName(),
                $property->getJsonSchema()->getFile(),
            ));
        }
    }

    /**
     * Returns the full set of type names for a composition branch, or null when the branch
     * is truly untyped (no type keyword — accepts any value).
     *
     * NullModifier sets getType() to PHP null but adds a TypeHintDecorator containing 'null',
     * so we distinguish null-typed branches (effective types: ['null']) from truly untyped
     * branches (getType() also null, but no 'null' in the type hint).
     *
     * @return string[]|null null when the branch has no type constraint
     */
    private function getBranchTypeNames(CompositionPropertyDecorator $branch): ?array
    {
        $type = $branch->getType();

        if ($type !== null) {
            return $type->getNames();
        }

        // NullModifier-processed branch: getType() is PHP null but typeHint contains 'null'.
        if (str_contains($branch->getTypeHint(), 'null')) {
            return ['null'];
        }

        return null;
    }
}
