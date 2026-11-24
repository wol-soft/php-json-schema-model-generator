<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PropertyConstraint;

/**
 * Why an object doesn't accept a property name
 */
class Denial
{
    public const string ADDITIONAL_PROPERTIES = 'additionalProperties';
    public const string DENY_OPTION = 'denyAdditionalProperties';
    public const string TYPE_CONFLICT = 'typeConflict';
    public const string PROPERTY_NAMES = 'propertyNames';

    /**
     * @param string $kind One of the constants of this class
     * @param string $pointer The pointer of the schema (or of the composition) denying the name
     * @param bool $everyBranch Whether every branch of the composition at the pointer denies the name
     * @param string[] $types The type of the property and the type of the additionalProperties schema for a type
     *                        conflict
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $pointer,
        public readonly bool $everyBranch = false,
        public readonly array $types = [],
    ) {}

    public function withEveryBranch(string $compositionPointer): self
    {
        return new self($this->kind, $compositionPointer, true, $this->types);
    }

    /**
     * The reason as a phrase which completes a sentence about the denied property, e.g. "denied as an additional
     * property by the schema at '#'"
     */
    public function describe(): string
    {
        $holder = $this->everyBranch
            ? "every branch of the composition at '#$this->pointer'"
            : "the schema at '#$this->pointer'";

        return match ($this->kind) {
            self::ADDITIONAL_PROPERTIES => "denied as an additional property by $holder",
            self::DENY_OPTION => "denied as an additional property by $holder (denyAdditionalProperties option)",
            self::TYPE_CONFLICT => sprintf(
                "its type '%s' conflicts with the additionalProperties type '%s' of %s",
                $this->types[0],
                $this->types[1],
                $holder,
            ),
            self::PROPERTY_NAMES => "its name is rejected by the propertyNames of $holder",
        };
    }

    /**
     * Whether the phrase of describe() is a predicate ("denied as ...") instead of a complete clause
     * ("its name is rejected ...")
     */
    public function isPredicate(): bool
    {
        return $this->kind === self::ADDITIONAL_PROPERTIES || $this->kind === self::DENY_OPTION;
    }
}
