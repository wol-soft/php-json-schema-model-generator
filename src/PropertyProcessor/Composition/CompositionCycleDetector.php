<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\Composition;

use PHPModelGenerator\Draft\Producer\ExclusiveProducer;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\SchemaDefinition\SchemaDefinitionDictionary;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Finds a schema which requires itself for the very same value: a `$ref` or a branch of allOf, anyOf, oneOf, not and
 * if/then/else which leads back to a schema on the way without descending into the value (items, properties,
 * contains, ...). The generated validation of such a schema never terminates, every composition validates all of its
 * branches. A reference which descends into the value validates a smaller value and is no cycle.
 *
 * The walk is static: it follows the raw JSON of the schema and peeks through references via the definition
 * dictionary, no property is created. A reference which can't be resolved is not followed, the reference resolution
 * which follows reports the failure with its own message.
 *
 * One detector serves a generation run (see SchemaProcessor::getCompositionCycleDetector()): the schemas behind a
 * reference which were found free of cycles stay known, every composition keyword of a property and every property
 * composing the same definitions stop at a definition which is already known. A schema behind a reference is the JSON
 * of the schema file, nothing which changes during the run. Discarding the findings after each walk was rejected: the
 * same definitions were walked again for each composition keyword of each property. Keeping the findings for the
 * schema a walk starts with was rejected as well: it may be a modified copy sharing the location of the original.
 */
final class CompositionCycleDetector
{
    private const array BRANCH_LIST_KEYWORDS = ['allOf', 'anyOf', 'oneOf'];
    private const array SINGLE_BRANCH_KEYWORDS = ['not', 'if', 'then', 'else'];

    /** @var array<string, true> The locations behind a reference from which no cycle is reachable */
    private array $acyclicLocations = [];

    public function __construct(private readonly SchemaProcessor $schemaProcessor)
    {
    }

    /**
     * @return string[] The schemas of the cycle which is reachable from the schema, starting with the first schema of
     *                  the cycle and ending with its repetition. The schemas are the provided schema and the targets
     *                  of references, the branches written inline between them are left out. A schema in the file of
     *                  the provided schema is noted as a pointer ('#/definitions/node'), others with their file.
     *                  Empty if no cycle is reachable.
     *
     * @throws SchemaException
     */
    public function findCycle(JsonSchema $schema, SchemaDefinitionDictionary $dictionary): array
    {
        $acyclicLocationsOfTheWalk = [];

        $namedEntries = array_filter(
            $this->walk($schema, $dictionary, [], true, false, $acyclicLocationsOfTheWalk),
            static fn(array $entry): bool => $entry['named'],
        );

        return array_map(
            static fn(array $entry): string => $entry['file'] === $schema->getFile()
                ? '#' . $entry['pointer']
                : $entry['file'] . '#' . $entry['pointer'],
            array_values($namedEntries),
        );
    }

    /**
     * @param array<string, array{file: string, pointer: string, named: bool}> $path The schemas on the way, keyed by
     *                                                                               their location
     * @param bool $named Whether the schema is the start of the walk or the target of a reference, in contrast to a
     *                    branch written inline
     * @param bool $fromSource Whether the schema is the target of a reference or written inside of one: its JSON
     *                         is the JSON of the schema file. The schema a walk starts with may be a modified copy
     *                         which shares the location of the original (e.g. without the keywords of the outer
     *                         property), its findings are only kept for the walk ($acyclicLocationsOfTheWalk).
     * @param array<string, true> $acyclicLocationsOfTheWalk
     *
     * @return array<int, array{file: string, pointer: string, named: bool}>
     *
     * @throws SchemaException
     */
    private function walk(
        JsonSchema $schema,
        SchemaDefinitionDictionary $dictionary,
        array $path,
        bool $named,
        bool $fromSource,
        array &$acyclicLocationsOfTheWalk,
    ): array {
        $location = $schema->getFile() . '#' . $schema->getPointer();
        $acyclicLocations = $fromSource ? $this->acyclicLocations : $acyclicLocationsOfTheWalk;

        if (isset($acyclicLocations[$location])) {
            return [];
        }

        $entry = ['file' => $schema->getFile(), 'pointer' => $schema->getPointer(), 'named' => $named];

        if (isset($path[$location])) {
            $cycle = array_values(array_slice($path, array_search($location, array_keys($path), true)));
            // a reference may point at a branch which was passed inline before: the cycle starts at that schema
            $cycle[0]['named'] = true;

            return [...$cycle, $entry];
        }

        $path[$location] = $entry;

        foreach ($this->getSameInstanceSchemas($schema, $dictionary) as $sameInstanceSchema) {
            $cycle = $this->walk(
                $sameInstanceSchema['schema'],
                $sameInstanceSchema['dictionary'],
                $path,
                $sameInstanceSchema['isReference'],
                $fromSource || $sameInstanceSchema['isReference'],
                $acyclicLocationsOfTheWalk,
            );

            if ($cycle !== []) {
                return $cycle;
            }
        }

        if ($fromSource) {
            $this->acyclicLocations[$location] = true;
        } else {
            $acyclicLocationsOfTheWalk[$location] = true;
        }

        return [];
    }

    /**
     * The schemas which validate the same value as the provided schema: the target of its reference and its
     * composition branches. A schema which isn't an object (a boolean schema, a malformed branch) has none.
     *
     * @return array<int, array{schema: JsonSchema, dictionary: SchemaDefinitionDictionary, isReference: bool}>
     *         The dictionary is the one of the document the schema belongs to
     *
     * @throws SchemaException
     */
    private function getSameInstanceSchemas(JsonSchema $schema, SchemaDefinitionDictionary $dictionary): array
    {
        $json = $schema->getJson();
        $sameInstanceSchemas = [];

        if (is_string($json['$ref'] ?? null)) {
            $referencedSchema = $dictionary->getReferencedSchema($schema, $this->schemaProcessor);

            if ($referencedSchema !== null) {
                $sameInstanceSchemas[] = [
                    'schema' => $referencedSchema[0],
                    'dictionary' => $referencedSchema[1],
                    'isReference' => true,
                ];
            }

            // Draft 07 and earlier ignore every keyword next to a reference
            $draft = $this->schemaProcessor->getGeneratorConfiguration()->getBuiltDraft($schema);

            if ($draft->getProducerForKeyword('$ref') instanceof ExclusiveProducer) {
                return $sameInstanceSchemas;
            }
        }

        foreach (self::BRANCH_LIST_KEYWORDS as $keyword) {
            if (!is_array($json[$keyword] ?? null) || !array_is_list($json[$keyword])) {
                continue;
            }

            foreach ($json[$keyword] as $index => $branch) {
                if (is_array($branch)) {
                    $sameInstanceSchemas[] = [
                        'schema' => $schema->navigate("$keyword/$index"),
                        'dictionary' => $dictionary,
                        'isReference' => false,
                    ];
                }
            }
        }

        foreach (self::SINGLE_BRANCH_KEYWORDS as $keyword) {
            if (is_array($json[$keyword] ?? null)) {
                $sameInstanceSchemas[] = [
                    'schema' => $schema->navigate($keyword),
                    'dictionary' => $dictionary,
                    'isReference' => false,
                ];
            }
        }

        return $sameInstanceSchemas;
    }
}
