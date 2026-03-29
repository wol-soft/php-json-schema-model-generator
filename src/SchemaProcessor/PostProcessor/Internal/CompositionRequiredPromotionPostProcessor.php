<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor\Internal;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\ConditionalPropertyValidator;
use PHPModelGenerator\Model\Validator\Factory\Composition\AllOfValidatorFactory;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;

/**
 * Promotes properties transferred from composition branches to non-nullable when the composition
 * structure guarantees the property is always present in a valid object.
 *
 * Rules:
 *   allOf  — property is required in any branch (all branches apply simultaneously)
 *   anyOf  — property is required in every branch (at least one always matches)
 *   oneOf  — property is required in every branch (exactly one always matches)
 *   if/then/else — property is required in both then and else (one always applies)
 *
 * The property's isRequired() flag is intentionally left false so the template short-circuit
 * (which exits early when the key is absent) continues to work correctly during construction.
 * Only the nullable flag on the PropertyType is changed to false.
 */
class CompositionRequiredPromotionPostProcessor extends PostProcessor
{
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        foreach ($schema->getBaseValidators() as $validator) {
            if (!($validator instanceof AbstractComposedPropertyValidator)) {
                continue;
            }

            foreach ($this->collectPromotablePropertyNames($validator) as $propertyName) {
                $this->promoteProperty($schema, $propertyName);
            }
        }
    }

    /**
     * Returns the names of all properties that are guaranteed to be present by the given validator.
     *
     * @return string[]
     */
    private function collectPromotablePropertyNames(AbstractComposedPropertyValidator $validator): array
    {
        if ($validator instanceof ConditionalPropertyValidator) {
            return $this->collectFromConditional($validator);
        }

        return $this->collectFromComposed($validator);
    }

    /**
     * For if/then/else: a property is guaranteed only when both then and else are present and
     * both require the property.
     *
     * @return string[]
     */
    private function collectFromConditional(ConditionalPropertyValidator $validator): array
    {
        $branches = $validator->getConditionBranches();

        if (count($branches) < 2) {
            return [];
        }

        $requiredPerBranch = array_map(
            static fn($branch): array =>
                $branch->getNestedSchema()?->getJsonSchema()->getJson()['required'] ?? [],
            $branches,
        );

        return array_values(array_intersect(...$requiredPerBranch));
    }

    /**
     * For allOf: a property is guaranteed when it is required in any branch.
     * For anyOf/oneOf: a property is guaranteed when it is required in every branch.
     *
     * @return string[]
     */
    private function collectFromComposed(ComposedPropertyValidator $validator): array
    {
        $branches = $validator->getComposedProperties();

        if (empty($branches)) {
            return [];
        }

        $requiredPerBranch = array_map(
            static fn($branch): array =>
                $branch->getNestedSchema()?->getJsonSchema()->getJson()['required'] ?? [],
            $branches,
        );

        if (is_a($validator->getCompositionProcessor(), AllOfValidatorFactory::class, true)) {
            return array_values(array_unique(array_merge(...$requiredPerBranch)));
        }

        return array_values(array_intersect(...$requiredPerBranch));
    }

    /**
     * Strips the nullable flag from the property's type if the property is not already required
     * at root level and has a type that can be promoted.
     */
    private function promoteProperty(Schema $schema, string $propertyName): void
    {
        $property = array_find(
            $schema->getProperties(),
            static fn (PropertyInterface $property): bool => $property->getName() === $propertyName,
        );

        if (!$property || $property->isRequired()) {
            return;
        }

        $type = $property->getType();
        $outputType = $property->getType(true);

        if ($type === null) {
            return;
        }

        $property->setType(
            new PropertyType($type->getNames(), false),
            new PropertyType($outputType->getNames(), false),
        );
    }
}
