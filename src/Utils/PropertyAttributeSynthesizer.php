<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Attributes\JsonPointer;
use PHPModelGenerator\Attributes\JsonSchema as JsonSchemaAttribute;
use PHPModelGenerator\Model\Attributes\PhpAttribute;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\ConditionalPropertyValidator;
use PHPModelGenerator\Model\Validator\Factory\Composition\AllOfValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Composition\AnyOfValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Composition\IfValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Composition\OneOfValidatorFactory;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;

class PropertyAttributeSynthesizer
{
    private const array COMPOSITION_KEYWORDS = [
        AllOfValidatorFactory::class => 'allOf',
        AnyOfValidatorFactory::class => 'anyOf',
        OneOfValidatorFactory::class => 'oneOf',
        IfValidatorFactory::class    => 'if',
    ];

    public function __construct(private GeneratorConfiguration $generatorConfiguration)
    {}

    /**
     * Synthesise correct #[JsonPointer] and #[JsonSchema] attributes for each property that
     * was transferred from composition branches onto the outer schema.
     *
     * Called once per validator after all its branches have been resolved.
     *
     * @param array<string, true> $seenBranchPropertyNames
     */
    public function synthesiseForValidator(
        AbstractComposedPropertyValidator $validator,
        Schema $schema,
        array $seenBranchPropertyNames,
    ): void {
        $compositionProcessor = $validator->getCompositionProcessor();
        $keyword = self::COMPOSITION_KEYWORDS[$compositionProcessor];

        $isConditional = $validator instanceof ConditionalPropertyValidator;

        // For pointer synthesis: include all branches (if, then, else) so each property gets a
        // pointer to wherever it is defined, regardless of which branch type defines it.
        // For JSON schema synthesis: use condition branches (then/else) since those define the
        // data-shape constraints, plus the if-branch schema is added separately under 'if'.
        $branchesForPointers = $validator->getComposedProperties();
        $branchesForSchemas  = $isConditional
            ? $validator->getConditionBranches()
            : $validator->getComposedProperties();

        $ifBranch = $isConditional ? $validator->getIfBranch() : null;
        $thenBranch = $isConditional ? $validator->getThenBranch() : null;
        $elseBranch = $isConditional ? $validator->getElseBranch() : null;

        foreach (array_keys($seenBranchPropertyNames) as $propertyName) {
            $outerProperty = $schema->getProperty($propertyName);

            if ($outerProperty === null) {
                continue;
            }

            $branchPointers = [];
            $branchSchemas  = [];

            foreach ($branchesForPointers as $branch) {
                if ($branch->getNestedSchema() === null) {
                    continue;
                }

                foreach ($branch->getNestedSchema()->getProperties() as $branchProperty) {
                    if ($branchProperty->getName() === $propertyName) {
                        // Use the composition branch position (e.g. /allOf/0) rather than the
                        // property's own pointer: for a $ref'd branch the latter resolves to the
                        // referenced definition (/$defs/…) instead of the branch it was merged from.
                        $branchPointer = $branch->getBranchSchema()->getPointer();
                        $branchPointers[] = $branchPointer !== ''
                            ? $branchPointer . '/properties/' . JsonSchema::encodePointer($propertyName)
                            : $branchProperty->getJsonSchema()->getPointer();
                        break;
                    }
                }
            }

            foreach ($branchesForSchemas as $branch) {
                if ($branch->getNestedSchema() === null) {
                    continue;
                }

                foreach ($branch->getNestedSchema()->getProperties() as $branchProperty) {
                    if ($branchProperty->getName() === $propertyName) {
                        $branchSchemas[] = $branchProperty->getJsonSchema()->getJson();
                        break;
                    }
                }
            }

            $ifBranchJson = $ifBranch !== null
                ? $this->findPropertyJsonInBranch($ifBranch, $propertyName)
                : null;
            $thenBranchJson = $thenBranch !== null
                ? $this->findPropertyJsonInBranch($thenBranch, $propertyName)
                : null;
            $elseBranchJson = $elseBranch !== null
                ? $this->findPropertyJsonInBranch($elseBranch, $propertyName)
                : null;

            $isRootRegistered = $schema->isRootRegistered($propertyName);
            $rootPointer      = $isRootRegistered ? $outerProperty->getJsonSchema()->getPointer() : null;
            $rootLevelJson    = $isRootRegistered ? $outerProperty->getJsonSchema()->getJson() : null;

            $this->synthesiseJsonPointerAttributes(
                $outerProperty,
                $rootPointer,
                $branchPointers,
            );

            $this->synthesiseJsonSchemaAttribute(
                $outerProperty,
                $keyword,
                $branchSchemas,
                $ifBranchJson,
                $thenBranchJson,
                $elseBranchJson,
                $rootLevelJson,
            );
        }
    }

    private function synthesiseJsonPointerAttributes(
        PropertyInterface $property,
        ?string $rootPointer,
        array $branchPointers,
    ): void {
        if (($this->generatorConfiguration->getEnabledAttributes() & PhpAttribute::JSON_POINTER) === 0) {
            return;
        }

        $property->filterAttributes(
            static fn(PhpAttribute $attribute): bool => $attribute->getFqcn() !== JsonPointer::class,
        );

        if ($rootPointer !== null) {
            $property->addAttribute(new PhpAttribute(JsonPointer::class, [$rootPointer]));
        }

        foreach ($branchPointers as $pointer) {
            $property->addAttribute(new PhpAttribute(JsonPointer::class, [$pointer]));
        }
    }

    private function findPropertyJsonInBranch(
        CompositionPropertyDecorator $branch,
        string $propertyName,
    ): ?array {
        $nestedSchema = $branch->getNestedSchema();

        if ($nestedSchema === null) {
            return null;
        }

        foreach ($nestedSchema->getProperties() as $branchProperty) {
            if ($branchProperty->getName() === $propertyName) {
                return $branchProperty->getJsonSchema()->getJson();
            }
        }

        return null;
    }

    private function synthesiseJsonSchemaAttribute(
        PropertyInterface $property,
        string $keyword,
        array $branchSchemas,
        ?array $ifBranchJson,
        ?array $thenBranchJson,
        ?array $elseBranchJson,
        ?array $rootLevelJson,
    ): void {
        if (($this->generatorConfiguration->getEnabledAttributes() & PhpAttribute::JSON_SCHEMA) === 0) {
            return;
        }

        $baseJson = $rootLevelJson ?? [];

        if ($keyword === 'if') {
            $conditionalParts = array_filter([
                'if'   => $ifBranchJson,
                'then' => $thenBranchJson,
                'else' => $elseBranchJson,
            ]);
            $synthesised = array_merge($baseJson, $conditionalParts);
        } else {
            $uniqueBranchSchemas = array_values(array_unique(
                array_map(static fn(array $schema): string => json_encode($schema), $branchSchemas),
            ));
            $deduplicatedBranchSchemas = array_map(
                static fn(string $encoded): array => json_decode($encoded, true),
                $uniqueBranchSchemas,
            );
            $synthesised = array_merge($baseJson, [$keyword => $deduplicatedBranchSchemas]);
        }

        $property->filterAttributes(
            static fn(PhpAttribute $attribute): bool => $attribute->getFqcn() !== JsonSchemaAttribute::class,
        );

        $property->addAttribute(
            new PhpAttribute(
                JsonSchemaAttribute::class,
                [empty($synthesised) ? '{}' : json_encode($synthesised)],
            ),
        );
    }
}
