<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PropertyConstraint;

use Closure;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

/**
 * Evaluates a property name against a propertyNames schema at generation time. Keywords which can't be evaluated
 * without an instance (e.g. format) accept the name: the evaluation only rejects a name which is rejected for sure, so
 * a schema is never rejected because of a keyword which isn't understood.
 */
class PropertyNameEvaluator
{
    /**
     * @param Closure(string): ?JsonSchema $refResolver Resolves a $ref string to the JsonSchema of its target, or
     *                                                  null if the target can't be resolved
     * @param bool $referenceSuppressesSiblings Whether the keywords next to a $ref are ignored (Draft 07 and earlier)
     */
    public function __construct(
        private readonly Closure $refResolver,
        private readonly bool $referenceSuppressesSiblings,
    ) {}

    /**
     * @param string[] $visitedReferences The references on the current resolution path, to bail out of cycles
     */
    public function accepts(mixed $schema, string $name, array $visitedReferences = []): bool
    {
        if (is_bool($schema)) {
            return $schema;
        }

        if (!is_array($schema)) {
            return true;
        }

        if (is_string($schema['$ref'] ?? null)) {
            $reference = $schema['$ref'];

            if (in_array($reference, $visitedReferences, true)) {
                return true;
            }

            $target = ($this->refResolver)($reference);
            if ($target !== null) {
                $accepted = $target->isBooleanSchema()
                    ? $target->getBooleanValue()
                    : $this->accepts($target->getJson(), $name, [...$visitedReferences, $reference]);

                if (!$accepted) {
                    return false;
                }
            }

            if ($this->referenceSuppressesSiblings) {
                return true;
            }

            unset($schema['$ref']);
        }

        return $this->acceptsValue($schema, $name)
            && $this->acceptsLength($schema, $name)
            && $this->acceptsComposition($schema, $name, $visitedReferences);
    }

    private function acceptsValue(array $schema, string $name): bool
    {
        // A const or enum with a non-string value is rejected by the propertyNames validator itself
        if (is_string($schema['const'] ?? null) && $schema['const'] !== $name) {
            return false;
        }

        if (is_array($schema['enum'] ?? null) && !in_array($name, $schema['enum'], true)) {
            return false;
        }

        if (is_string($schema['pattern'] ?? null)) {
            // the delimiter handling of the pattern validators
            $matches = @preg_match('/' . addcslashes($schema['pattern'], '/') . '/', $name);

            // an invalid pattern is reported by the pattern validator
            if ($matches === 0) {
                return false;
            }
        }

        return true;
    }

    private function acceptsLength(array $schema, string $name): bool
    {
        $length = mb_strlen($name);

        return !(is_int($schema['minLength'] ?? null) && $length < $schema['minLength'])
            && !(is_int($schema['maxLength'] ?? null) && $length > $schema['maxLength']);
    }

    private function acceptsComposition(array $schema, string $name, array $visitedReferences): bool
    {
        $evaluate = fn(mixed $branch): bool => $this->accepts($branch, $name, $visitedReferences);

        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            if (!is_array($schema[$keyword] ?? null) || $schema[$keyword] === []) {
                continue;
            }

            $matchingBranches = count(array_filter($schema[$keyword], $evaluate));
            $accepted = match ($keyword) {
                'allOf' => $matchingBranches === count($schema[$keyword]),
                'anyOf' => $matchingBranches > 0,
                'oneOf' => $matchingBranches === 1,
            };

            if (!$accepted) {
                return false;
            }
        }

        if (array_key_exists('not', $schema) && $evaluate($schema['not'])) {
            return false;
        }

        if (array_key_exists('if', $schema)) {
            $branch = $evaluate($schema['if']) ? 'then' : 'else';

            if (array_key_exists($branch, $schema) && !$evaluate($schema[$branch])) {
                return false;
            }
        }

        return true;
    }
}
