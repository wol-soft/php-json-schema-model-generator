<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\Composition;

use Exception;
use PHPModelGenerator\Draft\Producer\ExclusiveProducer;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\SchemaDefinition\SchemaDefinitionDictionary;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use TypeError;

/**
 * Finds a schema which requires itself for the very same value: a `$ref` or a branch of allOf, anyOf, oneOf, not and
 * if/then/else which leads back to a schema on the way without descending into the value (items, properties,
 * contains, ...). The generated validation of such a schema never terminates, every composition validates all of its
 * branches. A reference which descends into the value validates a smaller value and is no cycle.
 *
 * The walk is static: it follows the raw JSON of the schema and peeks through references via the definition
 * dictionary, no property is created. A reference which can't be resolved is not followed, the reference resolution
 * which follows reports the failure with its own message.
 */
final class CompositionCycleDetector
{
    private const array BRANCH_LIST_KEYWORDS = ['allOf', 'anyOf', 'oneOf'];
    private const array SINGLE_BRANCH_KEYWORDS = ['not', 'if', 'then', 'else'];

    /** @var array<string, true> The locations from which no cycle is reachable */
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
        $this->acyclicLocations = [];

        $namedEntries = array_filter(
            $this->walk($schema, $dictionary, [], true),
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
     *
     * @return array<int, array{file: string, pointer: string, named: bool}>
     *
     * @throws SchemaException
     */
    private function walk(JsonSchema $schema, SchemaDefinitionDictionary $dictionary, array $path, bool $named): array
    {
        $location = $schema->getFile() . '#' . $schema->getPointer();

        if (isset($this->acyclicLocations[$location])) {
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
            );

            if ($cycle !== []) {
                return $cycle;
            }
        }

        $this->acyclicLocations[$location] = true;

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
            $referencedSchema = $this->resolveReference($schema, $dictionary);

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

    /**
     * @return array{0: JsonSchema, 1: SchemaDefinitionDictionary}|null The referenced schema and the dictionary of
     *                                                                   the document it belongs to, null if the
     *                                                                   reference can't be resolved
     *
     * @throws SchemaException
     */
    private function resolveReference(JsonSchema $schema, SchemaDefinitionDictionary $dictionary): ?array
    {
        $path = [];

        try {
            $definition = $dictionary->getDefinition(
                $schema->getJson()['$ref'],
                $this->schemaProcessor,
                $path,
                $schema->getBaseId(),
            );

            if ($definition === null) {
                return null;
            }

            return [
                $definition->getSource()->navigate(implode('/', $path)),
                $definition->getSchema()->getSchemaDictionary(),
            ];
        } catch (SchemaException $exception) {
            // Resolving a reference to a file which isn't processed yet processes the file. A failure of the
            // referenced file is the very failure the reference resolution reports, it must not be swallowed here:
            // the file is registered as processed, the failure would be lost.
            if ($exception->isReferencedSchemaFailure()) {
                throw $exception;
            }

            return null;
        } catch (Exception | TypeError) {
            // An unresolvable reference, or a reference to a boolean schema which can't be navigated
            return null;
        }
    }
}
