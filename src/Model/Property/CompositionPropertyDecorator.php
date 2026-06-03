<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\SchemaDefinition\ResolvedDefinitionsCollection;
use PHPModelGenerator\Utils\RenderHelper;

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
     * Used by the composition post processor to harvest names that must invalidate the
     * setter-side validation cache when those properties change.
     *
     * @return string[]
     */
    public function getBranchDeclaredPropertyNames(): array
    {
        return array_keys($this->jsonSchema->getJson()['properties'] ?? []);
    }

    /**
     * Returns the declared property names as a PHP array literal ready for direct template
     * embedding. var_export emits a syntactically-valid PHP literal regardless of which
     * characters the names contain (quotes, backslashes, multibyte sequences).
     */
    public function getBranchDeclaredPropertyNamesPhpLiteral(): string
    {
        return var_export($this->getBranchDeclaredPropertyNames(), true);
    }

    /**
     * Returns the patternProperties regexes as a PHP array literal ready for direct template
     * embedding. Each pattern is wrapped with `/` delimiters and any embedded `/` is escaped so
     * the result can be passed straight to `preg_match`.
     */
    public function getBranchPatternPropertyPatternsPhpLiteral(): string
    {
        return RenderHelper::varExportPcrePatterns(
            array_keys($this->jsonSchema->getJson()['patternProperties'] ?? []),
        );
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
