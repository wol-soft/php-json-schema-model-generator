<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Composition;

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

        if (!isset($propertySchema->getJson()['then']) && !isset($propertySchema->getJson()['else'])) {
            throw new SchemaException(
                sprintf(
                    'Incomplete conditional composition for property %s in file %s',
                    $property->getName(),
                    $property->getJsonSchema()->getFile(),
                ),
            );
        }

        // Inherit the parent type into if/then/else sub-schemas before the filter check so
        // that sub-schemas that inherit 'object' are correctly recognised as object-typed.
        // Object-typed sub-schemas create nested schemas whose properties are processed
        // independently and are not subject to ComposedItem $value reset.
        $propertySchema = $this->inheritPropertyType($propertySchema);
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
        $parentNames = array_filter(
            $originalParentType->getNames(),
            static fn(string $typeName): bool => $typeName !== 'null',
        );

        if (empty($parentNames)) {
            return;
        }

        $thenType = $thenProperty->getType();
        $elseType = $elseProperty->getType();

        if ($thenType === null || $elseType === null) {
            // At least one branch is untyped — it accepts any value, so no total conflict.
            return;
        }

        $thenNonNull = array_filter(
            $thenType->getNames(),
            static fn(string $typeName): bool => $typeName !== 'null',
        );
        $elseNonNull = array_filter(
            $elseType->getNames(),
            static fn(string $typeName): bool => $typeName !== 'null',
        );

        $thenConflicts = empty(TypeIntersection::compute(array_values($parentNames), array_values($thenNonNull)));
        $elseConflicts = empty(TypeIntersection::compute(array_values($parentNames), array_values($elseNonNull)));

        if ($thenConflicts && $elseConflicts) {
            throw new SchemaException(sprintf(
                "Property '%s' has a type that conflicts with all if/then/else composition branches"
                    . ' (file %s). No value can satisfy both the property type and the applicable'
                    . ' branch constraint, making this schema unsatisfiable.',
                $property->getName(),
                $property->getJsonSchema()->getFile(),
            ));
        }
    }
}
