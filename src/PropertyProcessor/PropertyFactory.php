<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor;

use PHPModelGenerator\Draft\Draft;
use PHPModelGenerator\Draft\DraftFactoryInterface;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Class PropertyFactory
 *
 * @package PHPModelGenerator\PropertyProcessor
 */
class PropertyFactory
{
    /** @var Draft[] Keyed by draft class name */
    private array $draftCache = [];

    public function __construct(protected ProcessorFactoryInterface $processorFactory)
    {}

    /**
     * Create a property
     *
     * @throws SchemaException
     */
    public function create(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        string $propertyName,
        JsonSchema $propertySchema,
        bool $required = false,
    ): PropertyInterface {
        $json = $propertySchema->getJson();

        // redirect properties with a constant value to the ConstProcessor
        if (isset($json['const'])) {
            $json['type'] = 'const';
        }
        // redirect references to the ReferenceProcessor
        if (isset($json['$ref'])) {
            $json['type'] = isset($json['type']) && $json['type'] === 'base'
                ? 'baseReference'
                : 'reference';
        }

        $resolvedType = $json['type'] ?? 'any';

        $property = $this->processorFactory
            ->getProcessor(
                $resolvedType,
                $schemaProcessor,
                $schema,
                $required,
            )
            ->process($propertyName, $propertySchema);

        if (!is_array($resolvedType)) {
            $this->applyDraftModifiers($schemaProcessor, $schema, $property, $propertySchema);
        }

        return $property;
    }

    /**
     * Run all Draft modifiers for the property's type(s) on the given property.
     *
     * @throws SchemaException
     */
    private function applyDraftModifiers(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $configDraft = $schemaProcessor->getGeneratorConfiguration()->getDraft();

        $draft = $configDraft instanceof DraftFactoryInterface
            ? $configDraft->getDraftForSchema($propertySchema)
            : $configDraft;

        $type = $propertySchema->getJson()['type'] ?? 'any';

        $builtDraft = $this->draftCache[$draft::class] ??= $draft->getDefinition()->build();

        // Types not declared in the draft are internal routing signals (e.g. 'allOf', 'base',
        // 'reference'). They have no draft modifiers to apply.
        if ($type !== 'any' && !$builtDraft->hasType($type)) {
            return;
        }

        // For untyped properties ('any'), only run universal modifiers (the 'any' entry itself).
        // getCoveredTypes('any') returns all types — that would incorrectly apply type-specific
        // modifiers (e.g. TypeCheckModifier) to properties that carry no type constraint.
        $coveredTypes = $type === 'any'
            ? array_filter(
                $builtDraft->getCoveredTypes('any'),
                static fn($t) => $t->getType() === 'any',
            )
            : $builtDraft->getCoveredTypes($type);

        foreach ($coveredTypes as $coveredType) {
            foreach ($coveredType->getModifiers() as $modifier) {
                $modifier->modify($schemaProcessor, $schema, $property, $propertySchema);
            }
        }
    }
}
