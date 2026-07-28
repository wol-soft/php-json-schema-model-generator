<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\SchemaDefinition\ResolvedDefinitionsCollection;
use PHPModelGenerator\Model\Validator\InstanceOfValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;

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
     * @inheritdoc
     *
     * A composition branch must not render validators that only make sense for the property as
     * a whole: RequiredPropertyValidator checks presence of the outer property (already checked
     * once, outside any branch); InstanceOfValidator against an empty-property placeholder class
     * would incorrectly reject any object value that satisfies the branch's actual (unconstrained)
     * semantics.
     *
     * A branch's own nested composition/conditional validator (allOf, anyOf, oneOf, not,
     * if/then/else — see AbstractComposedPropertyValidator) is deliberately NOT excluded here:
     * $nestedSchema is only ever set for a branch whose own schema declares "type": "object"
     * (PropertyFactory::createObjectProperty()), and for any such branch AbstractCompositionValidatorFactory
     * ::shouldSkip() already blocks every composition-keyword factory from attaching a validator
     * to that same branch property in the first place — the object's own composition is instead
     * processed entirely inside its generated nested class. So a branch here never simultaneously
     * has a nested schema and its own composed/conditional validator; the validator, when present,
     * always belongs to a bare (untyped) branch and must be kept and rendered, or the nested
     * composition would silently accept every value (issue #167).
     *
     * Filtering here — at render time, without mutating the wrapped property's own validator
     * list — keeps the exclusion branch-local. The wrapped property may be shared (via
     * PropertyProxy's underlying-property delegation) with a completely different use of the same
     * $ref definition, e.g. as a directly required property elsewhere; that other use must keep
     * its own RequiredPropertyValidator intact.
     */
    public function getOrderedValidators(): array
    {
        $nestedSchema = $this->getNestedSchema();

        return array_values(array_filter(
            parent::getOrderedValidators(),
            static function (PropertyValidatorInterface $validator) use ($nestedSchema): bool {
                if (is_a($validator, RequiredPropertyValidator::class)) {
                    return false;
                }

                return !(
                    is_a($validator, InstanceOfValidator::class)
                    && $nestedSchema !== null
                    && empty($nestedSchema->getProperties())
                );
            },
        ));
    }
}
