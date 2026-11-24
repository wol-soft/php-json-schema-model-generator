<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PropertyConstraint;

use Closure;
use PHPModelGenerator\Draft\Draft;
use PHPModelGenerator\Draft\Producer\ExclusiveProducer;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\SchemaDefinition\SchemaDefinitionDictionary;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\JsonSchema as JsonSchemaUtil;
use Throwable;

/**
 * Rejects a schema whose property constraints contradict each other, as the generated model can't be used:
 * - a property which is required although the object denies it (no instance satisfies the schema)
 * - a property which is declared although the object denies it (the property can never be provided, its generated
 *   accessors are dead)
 * - more required properties than maxProperties allows
 *
 * A name is denied by every schema which applies unconditionally to the object: the schema itself, every allOf branch
 * (nested ones included) and the target of a reference next to which the draft applies keywords. A schema denies a
 * name which it doesn't declare by its properties keyword and doesn't match by one of its patternProperties if it sets
 * additionalProperties to false (or doesn't set it while the denyAdditionalProperties option is active), if its
 * additionalProperties type conflicts with a type declared for the name, or if its propertyNames schema rejects the
 * name. An anyOf/oneOf composition denies a name only if every branch denies it. The conditional keywords (if, then,
 * else, schema dependencies) never deny a name for every instance.
 */
class ContradictoryPropertyConstraintsChecker
{
    private readonly PropertyNameEvaluator $propertyNameEvaluator;

    /**
     * @param Closure(string, string): ?JsonSchema $refResolver Resolves a $ref string (and the base id it is written
     *                                                          in) to the JsonSchema of its target, or null if the
     *                                                          target can't be resolved
     * @param bool $referenceSuppressesSiblings Whether the keywords next to a $ref are ignored (Draft 07 and earlier)
     */
    public function __construct(
        private readonly Closure $refResolver,
        private readonly bool $referenceSuppressesSiblings,
        private readonly bool $denyAdditionalProperties,
    ) {
        $this->propertyNameEvaluator = new PropertyNameEvaluator(
            fn(string $reference): ?JsonSchema => ($this->refResolver)($reference, '#'),
            $referenceSuppressesSiblings,
        );
    }

    /**
     * Resolves the references via the definitions of the processed schema - a raw, unprocessed peek which doesn't
     * create any property
     */
    public static function forDictionary(
        SchemaProcessor $schemaProcessor,
        SchemaDefinitionDictionary $dictionary,
        Draft $draft,
        GeneratorConfiguration $generatorConfiguration,
    ): self {
        return new self(
            static fn(string $reference, string $baseId): ?JsonSchema => self::resolveReference(
                $schemaProcessor,
                $dictionary,
                $reference,
                $baseId,
            ),
            $draft->getProducerForKeyword('$ref') instanceof ExclusiveProducer,
            $generatorConfiguration->denyAdditionalProperties(),
        );
    }

    private static function resolveReference(
        SchemaProcessor $schemaProcessor,
        SchemaDefinitionDictionary $dictionary,
        string $reference,
        string $baseId,
    ): ?JsonSchema {
        $path = [];

        try {
            $definition = $dictionary->getDefinition($reference, $schemaProcessor, $path, $baseId);

            return $definition?->getSource()->navigate(implode('/', $path));
        } catch (SchemaException $exception) {
            // Resolving a reference to another file generates the class of that file. A failure of that generation
            // is a fault of the referenced schema which must not be swallowed here: the real reference resolution
            // would find the schema registered and not report it again.
            if ($exception->isReferencedSchemaFailure()) {
                throw $exception;
            }

            // An unresolvable or malformed reference: the real reference resolution reports the problem with a more
            // precise message, nothing is denied by the target
            return null;
        } catch (Throwable) {
            // A reference to a boolean schema
            return null;
        }
    }

    /**
     * A boolean target has no keywords which could deny a name: it is treated like an unresolved reference
     */
    private function resolveObjectTarget(string $reference, JsonSchema $context): ?JsonSchema
    {
        $target = ($this->refResolver)($reference, $context->getBaseId());

        return $target === null || $target->isBooleanSchema() ? null : $target;
    }

    /**
     * @throws SchemaException
     */
    public function check(JsonSchema $schema): void
    {
        $unit = $this->expand($schema, []);
        $declaredTypes = $this->collectDeclaredTypes($unit);
        $dependencies = $this->collectPropertyDependencies($unit);
        $required = $this->collectRequired($unit);

        foreach ($required as $entry) {
            $this->checkRequiredProperty($unit, $entry, $declaredTypes, $dependencies);
        }

        foreach ($this->collectDeclarations($schema, []) as $declaration) {
            $this->checkDeclaredProperty($unit, $declaration, $declaredTypes, $dependencies);
        }

        $this->checkMaxProperties($unit, array_values(array_unique(array_column($required, 'name'))));
    }

    /**
     * @param array{name: string, node: JsonSchema, index: int} $entry
     * @param array<string, string[]> $declaredTypes
     * @param array<string, string[]> $dependencies
     *
     * @throws SchemaException
     */
    private function checkRequiredProperty(
        ConstraintUnit $unit,
        array $entry,
        array $declaredTypes,
        array $dependencies,
    ): void {
        $site = $entry['node']->withPointer($entry['node']->getPointer() . '/required/' . $entry['index']);

        $denial = $this->findDenial($unit, $entry['name'], $declaredTypes);
        if ($denial !== null) {
            $this->fail(sprintf("Property '%s' is required but %s", $entry['name'], $denial->describe()), $site);
        }

        foreach ($this->getDependencyClosure($entry['name'], $dependencies) as $dependency) {
            $denial = $this->findDenial($unit, $dependency, $declaredTypes);

            if ($denial !== null) {
                $this->fail(
                    sprintf(
                        "Property '%s' is required but %s",
                        $entry['name'],
                        $this->describeDependencyDenial($dependency, $denial),
                    ),
                    $site,
                );
            }
        }
    }

    /**
     * @param array{name: string, site: JsonSchema} $declaration
     * @param array<string, string[]> $declaredTypes
     * @param array<string, string[]> $dependencies
     *
     * @throws SchemaException
     */
    private function checkDeclaredProperty(
        ConstraintUnit $unit,
        array $declaration,
        array $declaredTypes,
        array $dependencies,
    ): void {
        $prefix = sprintf(
            "Property '%s' declared at '#%s' can never be provided: ",
            $declaration['name'],
            $declaration['site']->getPointer(),
        );

        $denial = $this->findDenial($unit, $declaration['name'], $declaredTypes);
        if ($denial !== null) {
            $this->fail($prefix . ($denial->isPredicate() ? 'it is ' : '') . $denial->describe(), $declaration['site']);
        }

        foreach ($this->getDependencyClosure($declaration['name'], $dependencies) as $dependency) {
            $denial = $this->findDenial($unit, $dependency, $declaredTypes);

            if ($denial !== null) {
                $this->fail($prefix . $this->describeDependencyDenial($dependency, $denial), $declaration['site']);
            }
        }
    }

    /**
     * @param string[] $requiredNames
     *
     * @throws SchemaException
     */
    private function checkMaxProperties(ConstraintUnit $unit, array $requiredNames): void
    {
        foreach ($unit->nodes as $node) {
            $maxProperties = $node->getJson()['maxProperties'] ?? null;

            if (is_int($maxProperties) && count($requiredNames) > $maxProperties) {
                $this->fail(
                    sprintf(
                        "Properties [%s] are required but exceed the maxProperties %d of the schema at '#%s'",
                        join(', ', array_map(static fn(string $name): string => "'$name'", $requiredNames)),
                        $maxProperties,
                        $node->getPointer(),
                    ),
                    $node->withPointer($node->getPointer() . '/maxProperties'),
                );
            }
        }
    }

    /**
     * The dependency of a property is denied as well: providing the property requires providing the dependency.
     */
    private function describeDependencyDenial(string $dependency, Denial $denial): string
    {
        return $denial->isPredicate()
            ? sprintf("its dependency '%s' is %s", $dependency, $denial->describe())
            : sprintf("its dependency '%s' can never be provided: %s", $dependency, $denial->describe());
    }

    /**
     * @throws SchemaException
     */
    private function fail(string $message, JsonSchema $site): never
    {
        throw new SchemaException(sprintf('%s in file %s', $message, $site->getFile()), $site);
    }

    /**
     * Collects the schemas applying unconditionally to the instance: the schema itself, the target of its $ref and
     * its allOf branches, and the anyOf/oneOf compositions.
     *
     * @param string[] $visitedReferences
     */
    private function expand(JsonSchema $node, array $visitedReferences): ConstraintUnit
    {
        $json = $node->getJson();
        $unit = new ConstraintUnit([$node], []);
        $referencedUnit = new ConstraintUnit([], []);

        if (is_string($json['$ref'] ?? null) && !in_array($json['$ref'], $visitedReferences, true)) {
            $target = $this->resolveObjectTarget($json['$ref'], $node);
            $referencedUnit = $target !== null
                ? $this->expand($target, [...$visitedReferences, $json['$ref']])
                : $referencedUnit;

            if ($this->referenceSuppressesSiblings) {
                return $referencedUnit;
            }
        }

        foreach ($this->getBranches($json, 'allOf') as $index => $branch) {
            $unit = $unit->merge($this->expand($node->navigate("allOf/$index"), $visitedReferences));
        }

        $groups = [];
        foreach (['anyOf', 'oneOf'] as $keyword) {
            $branchUnits = [];

            foreach (is_array($json[$keyword] ?? null) ? $json[$keyword] : [] as $index => $branch) {
                if ($branch === true) {
                    // accepts every instance
                    $branchUnits[] = new ConstraintUnit([], []);
                } elseif (is_array($branch)) {
                    $branchUnits[] = $this->expand($node->navigate("$keyword/$index"), $visitedReferences);
                }
            }

            if ($branchUnits !== []) {
                $groups[] = ['pointer' => $node->getPointer() . "/$keyword", 'branches' => $branchUnits];
            }
        }

        return $unit->merge(new ConstraintUnit([], $groups))->merge($referencedUnit);
    }

    /**
     * @return array<int, array>
     */
    private function getBranches(array $json, string $keyword): array
    {
        return is_array($json[$keyword] ?? null)
            ? array_filter($json[$keyword], static fn(mixed $branch): bool => is_array($branch))
            : [];
    }

    /**
     * @return array<string, string[]> The types declared for a property name by the schemas applying unconditionally
     */
    private function collectDeclaredTypes(ConstraintUnit $unit): array
    {
        $types = [];

        foreach ($unit->nodes as $node) {
            foreach ($this->getDeclaredProperties($node) as $name => $propertySchema) {
                if (is_array($propertySchema) && is_string($propertySchema['type'] ?? null)) {
                    $types[$name][] = $propertySchema['type'];
                }
            }
        }

        return $types;
    }

    /**
     * @return array<string, string[]> The properties each property of the schemas applying unconditionally requires
     */
    private function collectPropertyDependencies(ConstraintUnit $unit): array
    {
        $dependencies = [];

        foreach ($unit->nodes as $node) {
            foreach (['dependencies', 'dependentRequired'] as $keyword) {
                $keywordDependencies = $node->getJson()[$keyword] ?? null;

                foreach (is_array($keywordDependencies) ? $keywordDependencies : [] as $name => $dependency) {
                    if ($this->isPropertyList($dependency)) {
                        $dependencies[(string) $name] = array_merge($dependencies[(string) $name] ?? [], $dependency);
                    }
                }
            }
        }

        return $dependencies;
    }

    private function isPropertyList(mixed $dependency): bool
    {
        return is_array($dependency)
            && array_is_list($dependency)
            && $dependency === array_filter($dependency, 'is_string');
    }

    /**
     * @param array<string, string[]> $dependencies
     *
     * @return string[] The properties required by providing the property, transitively
     */
    private function getDependencyClosure(string $name, array $dependencies): array
    {
        $closure = [];
        $pending = $dependencies[$name] ?? [];

        while ($pending !== []) {
            $dependency = array_shift($pending);

            if ($dependency !== $name && !in_array($dependency, $closure, true)) {
                $closure[] = $dependency;
                $pending = array_merge($pending, $dependencies[$dependency] ?? []);
            }
        }

        return $closure;
    }

    /**
     * The properties required for every instance: required by a schema applying unconditionally, or by every branch of
     * an anyOf/oneOf composition.
     *
     * @return array<int, array{name: string, node: JsonSchema, index: int}>
     */
    private function collectRequired(ConstraintUnit $unit): array
    {
        $entries = [];

        foreach ($unit->nodes as $node) {
            $required = $node->getJson()['required'] ?? null;

            foreach (is_array($required) ? $required : [] as $index => $name) {
                if (is_string($name)) {
                    $entries[] = ['name' => $name, 'node' => $node, 'index' => $index];
                }
            }
        }

        foreach ($unit->groups as $group) {
            $branchEntries = array_map(
                fn(ConstraintUnit $branch): array => $this->collectRequired($branch),
                $group['branches'],
            );

            foreach ($branchEntries[0] as $entry) {
                foreach (array_slice($branchEntries, 1) as $otherBranchEntries) {
                    if (!in_array($entry['name'], array_column($otherBranchEntries, 'name'), true)) {
                        continue 2;
                    }
                }

                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Collects the first declaration of each property name in the whole schema, also in the conditional parts.
     *
     * @param string[] $visitedReferences
     *
     * @return array<int, array{name: string, site: JsonSchema}>
     */
    private function collectDeclarations(JsonSchema $node, array $visitedReferences): array
    {
        $json = $node->getJson();
        $declarations = [];

        $target = null;
        if (is_string($json['$ref'] ?? null) && !in_array($json['$ref'], $visitedReferences, true)) {
            $target = $this->resolveObjectTarget($json['$ref'], $node);

            if ($this->referenceSuppressesSiblings) {
                return $target !== null
                    ? $this->collectDeclarations($target, [...$visitedReferences, $json['$ref']])
                    : [];
            }
        }

        foreach ($this->getDeclaredProperties($node) as $name => $propertySchema) {
            if ($propertySchema !== false) {
                $declarations[] = [
                    'name' => (string) $name,
                    'site' => $node->withPointer(
                        $node->getPointer() . '/properties/' . JsonSchemaUtil::encodePointer($name),
                    ),
                ];
            }
        }

        $children = [];
        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            foreach (array_keys($this->getBranches($json, $keyword)) as $index) {
                $children[] = "$keyword/$index";
            }
        }

        foreach (['if', 'then', 'else'] as $keyword) {
            if (is_array($json[$keyword] ?? null)) {
                $children[] = $keyword;
            }
        }

        foreach (['dependencies', 'dependentSchemas'] as $keyword) {
            foreach (is_array($json[$keyword] ?? null) ? $json[$keyword] : [] as $name => $dependency) {
                // a list is a property dependency
                if (is_array($dependency) && $dependency !== [] && !array_is_list($dependency)) {
                    $children[] = "$keyword/" . JsonSchemaUtil::encodePointer((string) $name);
                }
            }
        }

        foreach ($children as $child) {
            $declarations = array_merge(
                $declarations,
                $this->collectDeclarations($node->navigate($child), $visitedReferences),
            );
        }

        if ($target !== null) {
            $declarations = array_merge(
                $declarations,
                $this->collectDeclarations($target, [...$visitedReferences, $json['$ref']]),
            );
        }

        // the first declaration of a name is reported
        $names = [];
        return array_values(array_filter($declarations, static function (array $declaration) use (&$names): bool {
            if (isset($names[$declaration['name']])) {
                return false;
            }

            return $names[$declaration['name']] = true;
        }));
    }

    /**
     * @return array<string, mixed> The properties declared by the schema, keyed by their name
     */
    private function getDeclaredProperties(JsonSchema $node): array
    {
        $properties = $node->getJson()['properties'] ?? null;

        if (!is_array($properties)) {
            return [];
        }

        // numeric property names are integer array keys
        return array_combine(array_map('strval', array_keys($properties)), $properties);
    }

    /**
     * @param array<string, string[]> $declaredTypes
     */
    private function findDenial(ConstraintUnit $unit, string $name, array $declaredTypes): ?Denial
    {
        foreach ($unit->nodes as $node) {
            $denial = $this->findNodeDenial($node, $name, $declaredTypes);

            if ($denial !== null) {
                return $denial;
            }
        }

        foreach ($unit->groups as $group) {
            $firstDenial = null;

            foreach ($group['branches'] as $branch) {
                $branchDenial = $this->findDenial($branch, $name, $declaredTypes);

                if ($branchDenial === null) {
                    // a branch accepting the name keeps the name usable
                    continue 2;
                }

                $firstDenial ??= $branchDenial;
            }

            if ($firstDenial !== null) {
                return $firstDenial->withEveryBranch($group['pointer']);
            }
        }

        return null;
    }

    /**
     * @param array<string, string[]> $declaredTypes
     */
    private function findNodeDenial(JsonSchema $node, string $name, array $declaredTypes): ?Denial
    {
        $json = $node->getJson();

        $isDeclared = array_key_exists($name, $this->getDeclaredProperties($node));

        if (!$isDeclared && !$this->matchesPatternProperty($json, $name)) {
            $denial = $this->findAdditionalPropertiesDenial($node, $name, $declaredTypes);

            if ($denial !== null) {
                return $denial;
            }
        }

        if (
            array_key_exists('propertyNames', $json)
            && !$this->propertyNameEvaluator->accepts($json['propertyNames'], $name)
        ) {
            return new Denial(Denial::PROPERTY_NAMES, $node->getPointer());
        }

        return null;
    }

    /**
     * @param array<string, string[]> $declaredTypes
     */
    private function findAdditionalPropertiesDenial(JsonSchema $node, string $name, array $declaredTypes): ?Denial
    {
        $json = $node->getJson();

        if (!array_key_exists('additionalProperties', $json)) {
            return $this->denyAdditionalProperties ? new Denial(Denial::DENY_OPTION, $node->getPointer()) : null;
        }

        if ($json['additionalProperties'] === false) {
            return new Denial(Denial::ADDITIONAL_PROPERTIES, $node->getPointer());
        }

        $additionalSchema = is_array($json['additionalProperties']) ? $json['additionalProperties'] : [];
        $additionalTypes = array_filter((array) ($additionalSchema['type'] ?? null), 'is_string');

        if ($additionalTypes === []) {
            return null;
        }

        foreach ($declaredTypes[$name] ?? [] as $declaredType) {
            foreach ($additionalTypes as $additionalType) {
                if ($this->typesOverlap($declaredType, $additionalType)) {
                    continue 2;
                }
            }

            return new Denial(
                Denial::TYPE_CONFLICT,
                $node->getPointer(),
                false,
                [
                    $this->getPhpTypeName($declaredType),
                    join(' | ', array_map($this->getPhpTypeName(...), $additionalTypes)),
                ],
            );
        }

        return null;
    }

    private function matchesPatternProperty(array $json, string $name): bool
    {
        $patternProperties = is_array($json['patternProperties'] ?? null) ? $json['patternProperties'] : [];

        foreach (array_keys($patternProperties) as $pattern) {
            if (@preg_match('/' . addcslashes((string) $pattern, '/') . '/', $name) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every integer is a number
     */
    private function typesOverlap(string $type, string $otherType): bool
    {
        return $type === $otherType
            || in_array([$type, $otherType], [['integer', 'number'], ['number', 'integer']], true);
    }

    private function getPhpTypeName(string $jsonType): string
    {
        return match ($jsonType) {
            'integer' => 'int',
            'boolean' => 'bool',
            'number' => 'float',
            default => $jsonType,
        };
    }
}
