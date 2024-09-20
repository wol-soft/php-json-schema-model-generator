<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Property;

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
     */
    public function addValidator(PropertyValidatorInterface $validator, int $priority = 99): PropertyInterface;

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
     * @return PropertyDecoratorInterface[]
     */
    public function getDecorators(): array;

    public function setRequired(bool $isPropertyRequired): PropertyInterface;

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

    public function isInternal(): bool;

    /**
     * Set a nested schema
     *
     * @return PropertyInterface
     */
    public function setNestedSchema(Schema $schema);

    /**
     * Get a nested schema if a schema was appended to the property
     */
    public function getNestedSchema(): ?Schema;

    /**
     * Get the JSON schema used to set up the property
     */
    public function getJsonSchema(): JsonSchema;
}
