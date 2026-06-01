<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\SchemaDefinition\ResolvedDefinitionsCollection;

/**
 * Class CompositionPropertyDecorator
 *
 * @package PHPModelGenerator\Model\Property
 */
class CompositionPropertyDecorator extends PropertyProxy
{
    private const string PROPERTY_KEY = 'composition';

    /**
     * Store all properties from nested schemas of the composed property validator. If the composition validator fails
     * all affected properties must be set to null to adopt only valid values in the base model.
     *
     * @var PropertyInterface[]
     */
    protected $affectedObjectProperties = [];

    private bool $alwaysTrueBranch = false;

    /**
     * CompositionPropertyDecorator constructor.
     *
     * @throws SchemaException
     */
    public function __construct(string $propertyName, JsonSchema $jsonSchema, PropertyInterface $property)
    {
        parent::__construct(
            $propertyName,
            $jsonSchema,
            new ResolvedDefinitionsCollection([self::PROPERTY_KEY => $property]),
            self::PROPERTY_KEY,
        );

        $property->onResolve(function (): void {
            $this->resolve();
        });
    }

    /**
     * Append an object property which is affected by the composition validator
     */
    public function appendAffectedObjectProperty(PropertyInterface $property): void
    {
        $this->affectedObjectProperties[] = $property;
    }

    /**
     * @return PropertyInterface[]
     */
    public function getAffectedObjectProperties(): array
    {
        return $this->affectedObjectProperties;
    }

    public function markAsAlwaysTrueBranch(): void
    {
        $this->alwaysTrueBranch = true;
    }

    public function isAlwaysTrueBranch(): bool
    {
        return $this->alwaysTrueBranch;
    }

    /**
     * Return the branch-level JSON schema (the composition element schema, which may contain
     * additionalProperties constraints). This is distinct from getJsonSchema(), which proxies
     * to the inner wrapped property's schema via PropertyProxy.
     */
    public function getBranchSchema(): JsonSchema
    {
        return $this->jsonSchema;
    }

    /**
     * Returns the property names declared in this branch's `properties` keyword.
     *
     * Used by composition templates to compute the evaluated property set for a successful
     * inline branch: each declared property name present in the model is credited as evaluated.
     *
     * @return string[]
     */
    public function getBranchDeclaredPropertyNames(): array
    {
        return array_keys($this->jsonSchema->getJson()['properties'] ?? []);
    }

    /**
     * Returns base64-encoded patternProperties patterns declared in this branch's JSON schema.
     *
     * The patterns are base64-encoded so they can be safely embedded as PHP string literals
     * in templates without escaping concerns. Templates decode them with base64_decode() before
     * calling preg_match().
     *
     * @return string[]
     */
    public function getBranchPatternPropertyPatterns(): array
    {
        $patterns = [];

        foreach (array_keys($this->jsonSchema->getJson()['patternProperties'] ?? []) as $pattern) {
            $patterns[] = base64_encode($pattern);
        }

        return $patterns;
    }

    /**
     * Returns true if this branch's JSON schema explicitly declares an `additionalProperties`
     * value that is not `false` (i.e., `true` or a schema object).
     *
     * Absent `additionalProperties` returns false: omitting the keyword does not contribute
     * annotation results that unevaluatedProperties checks. When this method returns true,
     * a successful branch is treated as evaluating every model key.
     */
    public function branchHasNonFalseAdditionalProperties(): bool
    {
        $branchJson = $this->jsonSchema->getJson();

        return isset($branchJson['additionalProperties'])
            && $branchJson['additionalProperties'] !== false;
    }
}
