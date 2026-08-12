<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Property;

use PHPModelGenerator\Model\Attributes\PhpAttribute;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\PropertyDecoratorInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintDecoratorInterface;
use PHPModelGenerator\Utils\ResolvableInterface;

/**
 * Interface PropertyInterface
 *
 * @package PHPModelGenerator\Model
 */
interface PropertyInterface extends ResolvableInterface
{
    public function getName(): string;

    /**
     * @param bool $variableName If set to true the name for the variable is returned. Otherwise, the name for functions
     *                           will be returned
     */
    public function getAttribute(bool $variableName = false): string;

    /**
     * @param bool $outputType If set to true the output type will be returned (may differ from the base type)
     */
    public function getType(bool $outputType = false): ?PropertyType;

    /**
     * @param PropertyType|null $outputType By default the output type will be equal to the base type but due to applied
     *                                      filters the output type may change
     * @param bool $reset set to true for a full type reset (including type hint decorators like array, ...)
     */
    public function setType(
        ?PropertyType $type = null,
        ?PropertyType $outputType = null,
        bool $reset = false,
    ): PropertyInterface;

    /**
     * @param bool $outputType If set to true the output type hint will be returned (may differ from the base type)
     * @param string[] $skipDecorators Provide a set of decorators (FQCN) which shouldn't be applied
     *                                 (might be necessary to avoid infinite loops for recursive calls)
     */
    public function getTypeHint(bool $outputType = false, array $skipDecorators = []): string;

    public function addTypeHintDecorator(TypeHintDecoratorInterface $typeHintDecorator): PropertyInterface;

    /**
     * Get a description for the property. If no description is available an empty string will be returned
     */
    public function getDescription(): string;

    public function getComment(): ?string;

    public function setComment(string $comment): PropertyInterface;

    public function getExamples(): array;

    public function setExamples(array $examples): PropertyInterface;

    /**
     * Add a validator for the property
     *
     * The priority is used to order the validators applied to a property.
     * The validators with the lowest priority number will be executed first.
     *
     * Priority 1:   Required checks
     * Priority 2:   Type Checks
     * Priority 3:   Enum Checks
     * Priority 10+: Filter validators
     * Priority 99:  Default priority used for casual validators
     * Priority 100: Validators for compositions
     *
     * The optional $sourceKey records which schema keyword (e.g. 'pattern', 'minimum')
     * caused this validator to be added.  Normally set automatically by PropertyFactory
     * after each Draft modifier runs; pass it explicitly only when transferring a validator
     * from another property (e.g. multi-type sub-property transfer).
     */
    public function addValidator(
        PropertyValidatorInterface $validator,
        int $priority = 99,
        ?string $sourceKey = null,
    ): PropertyInterface;

    /**
     * @return Validator[]
     */
    public function getValidators(): array;

    /**
     * Filter the assigned validators
     */
    public function filterValidators(callable $filter): PropertyInterface;

    /**
     * Retrieve all added validators ordered by priority
     *
     * @return PropertyValidatorInterface[]
     */
    public function getOrderedValidators(): array;

    /**
     * Add a decorator to the property
     */
    public function addDecorator(PropertyDecoratorInterface $decorator): PropertyInterface;

    /**
     * Resolve all decorators of the property
     */
    public function resolveDecorator(string $input, bool $nestedProperty): string;

    /**
     * Filter the assigned decorators
     */
    public function filterDecorators(callable $filter): PropertyInterface;

    /**
     * @return PropertyDecoratorInterface[]
     */
    public function getDecorators(): array;

    public function setRequired(bool $isPropertyRequired): PropertyInterface;

    /**
     * Mark the property as representing an item of an array (as opposed to an object property).
     * Array items have no "required" keyword of their own — they are unconditionally mandatory —
     * and must never receive a RequiredPropertyValidator.
     */
    public function setArrayItem(bool $isArrayItem): PropertyInterface;

    public function isArrayItem(): bool;

    public function setReadOnly(bool $isPropertyReadOnly): PropertyInterface;

    public function setInternal(bool $isPropertyInternal): PropertyInterface;

    /**
     * @param bool $raw By default, the provided value will be added to the generated code via var_export. If the raw
     * option is enabled the value provided in $defaultValue will not be changed.
     */
    public function setDefaultValue(mixed $defaultValue, bool $raw = false): PropertyInterface;

    public function getDefaultValue(): ?string;

    public function isRequired(): bool;

    public function isReadOnly(): bool;

    public function isWriteOnly(): bool;

    public function setWriteOnly(bool $isPropertyWriteOnly): PropertyInterface;

    public function isInternal(): bool;

    /**
     * Attach the single generated PHP class whose instances represent this property's value
     * whenever the value is an object.
     *
     * Contract: the nested schema is an identity/representation link, not a validation
     * container - it names which class represents object values, it does not by itself assert
     * that the value must be an object. A non-null nested schema alone is therefore NOT proof
     * that the property is definitively object-typed: PropertyFactory::createObjectProperty()
     * also sets a nested schema on a guarded, object-*describing* property (a bare
     * `properties`/`required` shape with no `type`), which is deliberately built to let
     * non-object values pass through unchanged (see `ObjectModifier(asserting: false)`) - the
     * value can vacuously satisfy such a branch without being an object at all.
     *
     * "Definitively object-typed with exactly one representing class" is signaled by the
     * COMBINATION of a non-null nested schema and a non-null `getType()`: the guarded/describing
     * wiring above resets the property's type to null precisely because it does not assert
     * object-ness, while an asserting branch (an explicit `type: object` schema, or a
     * composition that asserts object-ness) carries both a nested schema and a non-null,
     * object-only type. Consumers that need the "definitively an object" guarantee - e.g.
     * assertNoObjectScalarTypeConflict() in AbstractCompositionValidatorFactory - must check
     * both signals together, not `getNestedSchema() !== null` alone.
     *
     * @return PropertyInterface
     */
    public function setNestedSchema(Schema $schema);

    /**
     * Get the single generated class representing this property's object values.
     *
     * `null` means no single class represents the property's object values: the property is
     * either not exclusively object-valued, or (anyOf/oneOf compositions) its object values
     * are represented by branch-owned classes - each reachable via the corresponding branch
     * property of the property's composed validator, which is deliberately NOT flattened into
     * a list here: a multi-class accessor would break the "non-null implies exactly one
     * representing class" inference consumers depend on.
     */
    public function getNestedSchema(): ?Schema;

    /**
     * Get the JSON schema used to set up the property
     */
    public function getJsonSchema(): JsonSchema;

    public function setJsonSchema(JsonSchema $jsonSchema): static;

    public function filterAttributes(callable $filter): static;

    public function addAttribute(
        PhpAttribute $attribute,
        ?GeneratorConfiguration $generatorConfiguration = null,
        ?int $enablementFlag = null,
    ): static;

    /**
     * Replace the JsonPointer attribute with one carrying the given pointer value.
     * Used by the $ref resolver to set the reference site's pointer on a resolved property
     * rather than the definition's pointer.
     */
    public function overrideJsonPointer(PhpAttribute $attribute): static;

    /**
     * @return PhpAttribute[]
     */
    public function getAttributes(): array;
}
