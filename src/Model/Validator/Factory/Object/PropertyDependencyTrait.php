<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Object;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\PropertyDependencyValidator;
use PHPModelGenerator\Model\Validator\SchemaDependencyValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\SchemaNamespaceTransferDecorator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Attaches a property or schema dependency (the 'dependencies' keyword) to a property.
 * Shared by PropertiesValidatorFactory and RequiredValidatorFactory, as a property's
 * 'dependencies' entry applies regardless of whether the property was declared in 'properties'
 * or only exists because it is listed in 'required'.
 */
trait PropertyDependencyTrait
{
    /**
     * @throws SchemaException
     */
    private function addDependencyValidator(
        PropertyInterface $property,
        JsonSchema $dependencyJsonSchema,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
    ): void {
        $propertyDependency = true;

        foreach ($dependencyJsonSchema->getJson() as $index => $dependency) {
            if (!is_int($index) || !is_string($dependency)) {
                $propertyDependency = false;
                break;
            }
        }

        $dependencyPointer = $dependencyJsonSchema->getPointer();

        if ($propertyDependency) {
            $property->addValidator(
                (new PropertyDependencyValidator($property, $dependencyJsonSchema->getJson()))
                    ->withJsonPointer($dependencyPointer),
            );

            return;
        }

        $json = $dependencyJsonSchema->getJson();
        if (!isset($json['type'])) {
            $dependencyJsonSchema = $dependencyJsonSchema->withJson($json + ['type' => 'object']);
        }

        $dependencySchema = $schemaProcessor->processSchema(
            $dependencyJsonSchema,
            $schema->getClassPath(),
            "{$schema->getClassName()}_{$property->getName()}_Dependency",
            $schema->getSchemaDictionary(),
        );

        $property->addValidator(
            (new SchemaDependencyValidator($schemaProcessor, $property, $dependencySchema))
                ->withJsonPointer($dependencyPointer),
        );
        $schema->addNamespaceTransferDecorator(new SchemaNamespaceTransferDecorator($dependencySchema));

        $this->transferDependentPropertiesToBaseSchema($dependencySchema, $schema);

        // The dependent subschema is a positive applicator at the same instance location as the
        // schema that declares it, so whatever it evaluates is evaluated there too and a sibling
        // `unevaluatedProperties` must credit it — while the trigger property is present.
        $schema->addDependentEvaluatedProperties(
            $property->getName(),
            array_map(strval(...), array_keys($json['properties'] ?? [])),
            isset($json['additionalProperties']) && $json['additionalProperties'] !== false,
        );

        $this->recordDependentContributionShortfall($schema, $property, $json);
    }

    /**
     * The names a dependent subschema contributes are read from its own `properties`, which is
     * every name it declares directly but nothing it reaches through a composition or a reference,
     * and nothing its `patternProperties` match — a pattern match is only credited through the
     * enclosing class's own `_patternProperties` record, which the dependent subschema's pattern
     * validator never writes (see Schema::addDependentEvaluatedProperties()).
     *
     * The reach-through gap cannot be closed the way the enclosing schema's own compositions are,
     * by reading their outcome back at runtime: the dependency validator runs during the trigger
     * property's validation pass, which is *after* the post-composition phase where
     * unevaluatedProperties evaluates, so no dependency result exists yet when the accumulator is
     * built. Harvesting the nested names statically instead is only sound for a conjunctive
     * `allOf`; for `anyOf`, `oneOf` and `if`/`then`/`else` it would credit branches that never
     * ran — the exact over-crediting the branch classes' runtime slots exist to avoid. So the
     * shortfall is reported rather than papered over: the effect is over-rejection of a spec-valid
     * instance, which is loud at runtime, and the warning names where it comes from.
     *
     * Only recorded here. Whether an accumulator consults this schema is not known yet — a
     * composition branch gets its accumulator from the schema it belongs to, and the draft decides
     * whether `unevaluatedProperties` exists at all — so UnevaluatedPropertiesPostProcessor reports
     * it once it does. Deciding by the schema's own `unevaluatedProperties` key at this point missed
     * every branch and warned on drafts which ignore the keyword.
     *
     * @param array<string, mixed> $dependentJson The dependent subschema as written
     */
    private function recordDependentContributionShortfall(
        Schema $schema,
        PropertyInterface $property,
        array $dependentJson,
    ): void {
        $uncreditedKeywords = array_values(array_filter(
            ['allOf', 'anyOf', 'oneOf', 'if', '$ref'],
            static fn(string $keyword): bool => array_key_exists($keyword, $dependentJson),
        ));

        // Declared directly, so not a reach-through, but uncredited all the same. An empty list
        // evaluates nothing and is not worth a warning.
        if (($dependentJson['patternProperties'] ?? []) !== []) {
            $uncreditedKeywords[] = 'patternProperties';
        }

        if ($uncreditedKeywords !== []) {
            $schema->addDependentContributionShortfall($property->getName(), $uncreditedKeywords);
        }
    }

    /**
     * The transferred properties neither validate nor transform: the dependency schema only validates the data (also
     * with its filters, which keep running inside the dependency schema). A property which is declared by the base
     * schema keeps the declaration of the base schema, independent of the order of the properties.
     */
    private function transferDependentPropertiesToBaseSchema(Schema $dependencySchema, Schema $schema): void
    {
        foreach ($dependencySchema->getProperties() as $dependencyProperty) {
            // Registered as a transferred property (not as a property of the base schema): a property which the base
            // schema declares takes precedence, see PropertyMerger::merge().
            $schema->addProperty(
                (clone $dependencyProperty)
                    ->setRequired(false)
                    ->setType(null)
                    ->filterValidators(static fn(): bool => false),
                SchemaDependencyValidator::class,
            );
        }
    }
}
