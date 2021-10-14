<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Property;

use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\PropertyDecoratorInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintDecoratorInterface;

/**
 * Interface PropertyInterface
 *
 * @package PHPModelGenerator\Model
 */
interface PropertyInterface
{
    /**
     * @return string
     */
    public function getName(): string;

    /**
     * @return string
     */
    public function getAttribute(): string;

    /**
     * @param bool $outputType If set to true the output type will be returned (may differ from the base type)
     *
     * @return PropertyType|null
     */
    public function getType(bool $outputType = false): ?PropertyType;

    /**
     * @param PropertyType|null $type
     * @param PropertyType|null $outputType By default the output type will be equal to the base type but due to applied
     *                                      filters the output type may change
     *
     * @return PropertyInterface
     */
    public function setType(PropertyType $type = null, PropertyType $outputType = null): PropertyInterface;

    /**
     * @param bool $outputType If set to true the output type hint will be returned (may differ from the base type)
     *
     * @return string
     */
    public function getTypeHint(bool $outputType = false): string;

    /**
     * @param TypeHintDecoratorInterface $typeHintDecorator
     *
     * @return PropertyInterface
     */
    public function addTypeHintDecorator(TypeHintDecoratorInterface $typeHintDecorator): PropertyInterface;

    /**
     * Get a description for the property. If no description is available an empty string will be returned
     *
     * @return string
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
     *
     * @param PropertyValidatorInterface $validator
     * @param int $priority
     *
     * @return PropertyInterface
     */
    public function addValidator(PropertyValidatorInterface $validator, int $priority = 99): PropertyInterface;

    /**
     * @return Validator[]
     */
    public function getValidators(): array;

    /**
     * Filter the assigned validators
     *
     * @param callable $filter
     *
     * @return PropertyInterface
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
     *
     * @param PropertyDecoratorInterface $decorator
     *
     * @return PropertyInterface
     */
    public function addDecorator(PropertyDecoratorInterface $decorator): PropertyInterface;

    /**
     * Resolve all decorators of the property
     *
     * @param string $input
     * @param bool $nestedProperty
     *
     * @return string
     */
    public function resolveDecorator(string $input, bool $nestedProperty): string;

    /**
     * @return PropertyDecoratorInterface[]
     */
    public function getDecorators(): array;

    /**
     * @param bool $isPropertyRequired
     *
     * @return PropertyInterface
     */
    public function setRequired(bool $isPropertyRequired): PropertyInterface;

    /**
     * @param bool $isPropertyReadOnly
     *
     * @return PropertyInterface
     */
    public function setReadOnly(bool $isPropertyReadOnly): PropertyInterface;

    /**
     * @param bool $isPropertyInternal
     *
     * @return PropertyInterface
     */
    public function setInternal(bool $isPropertyInternal): PropertyInterface;

    /**
     * @param mixed $defaultValue
     *
     * @return PropertyInterface
     */
    public function setDefaultValue($defaultValue): PropertyInterface;

    /**
     * @return string|null
     */
    public function getDefaultValue(): ?string;

    /**
     * @return bool
     */
    public function isRequired(): bool;

    /**
     * @return bool
     */
    public function isReadOnly(): bool;

    /**
     * @return bool
     */
    public function isInternal(): bool;

    /**
     * Set a nested schema
     *
     * @param Schema $schema
     *
     * @return PropertyInterface
     */
    public function setNestedSchema(Schema $schema);

    /**
     * Get a nested schema if a schema was appended to the property
     *
     * @return null|Schema
     */
    public function getNestedSchema(): ?Schema;

    /**
     * Get the JSON schema used to set up the property
     *
     * @return JsonSchema
     */
    public function getJsonSchema(): JsonSchema;
}
