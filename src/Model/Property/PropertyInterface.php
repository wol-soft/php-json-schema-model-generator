<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Property;

use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\PropertyDecoratorInterface;

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
     * @return string
     */
    public function getType(): string;

    /**
     * @param string $type
     *
     * @return PropertyInterface
     */
    public function setType(string $type): PropertyInterface;

    /**
     * Get a description for the property. If no description is available an empty string will be returned
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Set a description for the property
     *
     * @param string $description
     *
     * @return PropertyInterface
     */
    public function setDescription(string $description): PropertyInterface;

    /**
     * Add a validator for the property
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
     * @return PropertyInterface[]
     */
    public function getNestedProperties(): array;

    /**
     * @param PropertyInterface $nestedProperty
     *
     * @return PropertyInterface
     */
    public function addNestedProperty(PropertyInterface $nestedProperty): PropertyInterface;

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
     *
     * @return string
     */
    public function resolveDecorator(string $input): string;

    /**
     * @return bool
     */
    public function hasDecorators(): bool;

    /**
     * Get a list of all required classes
     *
     * @return array
     */
    public function getExceptionClasses(): array;

    /**
     * @param bool $isPropertyRequired
     *
     * @return PropertyInterface
     */
    public function setRequired(bool $isPropertyRequired): PropertyInterface;

    /**
     * @return bool
     */
    public function isRequired(): bool;

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
}
