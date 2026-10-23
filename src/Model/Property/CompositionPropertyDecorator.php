<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\SchemaDefinition\ResolvedDefinitionsCollection;
use PHPModelGenerator\Model\Validator\DependencyValidatorInterface;
use PHPModelGenerator\Model\Validator\InstanceOfValidator;
use PHPModelGenerator\Model\Validator\ObjectUpdateDependency;
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

    /**
     * How the result of the branch may change with an update of the key, or with an update of any key the branch
     * doesn't declare as property if no key is given (see ObjectUpdateDependency). A key the branch declares is
     * validated with its value. Otherwise the validators of the nested schema decide: its object-level validators
     * (maxProperties, additionalProperties - also implicitly denied via the generator configuration -,
     * patternProperties, propertyNames, a nested composition, ...) and the dependencies of its properties. A true
     * branch has no validators, no update can change its result. Any other branch without a nested schema depends on
     * the value of every key, its validators are unknown.
     *
     * Deciding by a whitelist of the keywords in the branch JSON was rejected: a $ref branch only contains the
     * reference and additionalProperties may be denied by the configuration without any keyword in the schema. The
     * validators of the generated nested schema cover both.
     */
    public function getObjectUpdateDependency(?string $key): ObjectUpdateDependency
    {
        // Checked before the nested schema: a true branch has none, treating it as a branch with unknown validators
        // made every setter of the model validate the composition.
        if ($this->alwaysTrueBranch) {
            return ObjectUpdateDependency::None;
        }

        $nestedSchema = $this->getNestedSchema();

        if ($nestedSchema === null) {
            return ObjectUpdateDependency::Value;
        }

        $dependency = ObjectUpdateDependency::ofValidators($nestedSchema->getBaseValidators(), $key);

        foreach ($nestedSchema->getProperties() as $property) {
            if ($property->isInternal()) {
                continue;
            }

            if ($property->getName() === $key) {
                return ObjectUpdateDependency::Value;
            }

            foreach ($property->getOrderedValidators() as $validator) {
                if ($validator instanceof DependencyValidatorInterface) {
                    $dependency = $dependency->strongest(ObjectUpdateDependency::ofValidator($validator, $key));
                }
            }
        }

        return $dependency;
    }

    /**
     * Whether the value of a key the branch doesn't declare may change the result: the branch must be evaluated again
     * with every update, the cached result can't be used.
     */
    public function dependsOnUndeclaredValues(): bool
    {
        return $this->getObjectUpdateDependency(null) === ObjectUpdateDependency::Value;
    }

    /**
     * Whether adding a key the branch doesn't declare may change the result: the cached result is only used as long
     * as no key is added.
     */
    public function dependsOnAddedKeys(): bool
    {
        return $this->getObjectUpdateDependency(null) === ObjectUpdateDependency::AddedKey;
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
