<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Property;

use PHPModelGenerator\Model\SchemaDefinition\ResolvedDefinitionsCollection;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\PropertyDecoratorInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintDecoratorInterface;

/**
 * Class PropertyProxy
 *
 * @package PHPModelGenerator\Model
 */
class PropertyProxy implements PropertyInterface
{
    /** @var string */
    protected $key;
    /** @var ResolvedDefinitionsCollection */
    protected $definitionsCollection;

    /**
     * PropertyProxy constructor.
     *
     * @param ResolvedDefinitionsCollection $definitionsCollection
     * @param string                        $key
     */
    public function __construct(ResolvedDefinitionsCollection $definitionsCollection, string $key)
    {
        $this->key = $key;
        $this->definitionsCollection = $definitionsCollection;
    }

    /**
     * Get the property out of the resolved definitions collection to proxy function calls
     *
     * @return PropertyInterface
     */
    protected function getProperty(): PropertyInterface
    {
        return $this->definitionsCollection->offsetGet($this->key);
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return $this->getProperty()->getName();
    }

    /**
     * @inheritdoc
     */
    public function getAttribute(): string
    {
        return $this->getProperty()->getAttribute();
    }

    /**
     * @inheritdoc
     */
    public function getType(bool $outputType = false): string
    {
        return $this->getProperty()->getType($outputType);
    }

    /**
     * @inheritdoc
     */
    public function setType(string $type, ?string $outputType = null): PropertyInterface
    {
        return $this->getProperty()->setType($type, $outputType);
    }

    /**
     * @inheritdoc
     */
    public function getTypeHint(bool $outputType = false): string
    {
        return $this->getProperty()->getTypeHint($outputType);
    }

    /**
     * @inheritdoc
     */
    public function addTypeHintDecorator(TypeHintDecoratorInterface $typeHintDecorator): PropertyInterface
    {
        return $this->getProperty()->addTypeHintDecorator($typeHintDecorator);
    }

    /**
     * @inheritdoc
     */
    public function getDescription(): string
    {
        return $this->getProperty()->getDescription();
    }

    /**
     * @inheritdoc
     */
    public function addValidator(PropertyValidatorInterface $validator, int $priority = 99): PropertyInterface
    {
        return $this->getProperty()->addValidator($validator, $priority);
    }

    /**
     * @inheritdoc
     */
    public function getValidators(): array
    {
        return $this->getProperty()->getValidators();
    }

    /**
     * @inheritdoc
     */
    public function filterValidators(callable $filter): PropertyInterface
    {
        return $this->getProperty()->filterValidators($filter);
    }

    /**
     * @inheritdoc
     */
    public function getOrderedValidators(): array
    {
        return $this->getProperty()->getOrderedValidators();
    }

    /**
     * @inheritdoc
     */
    public function addDecorator(PropertyDecoratorInterface $decorator): PropertyInterface
    {
        return $this->getProperty()->addDecorator($decorator);
    }

    /**
     * @inheritdoc
     */
    public function resolveDecorator(string $input): string
    {
        return $this->getProperty()->resolveDecorator($input);
    }

    /**
     * @inheritdoc
     */
    public function hasDecorators(): bool
    {
        return $this->getProperty()->hasDecorators();
    }

    /**
     * @inheritdoc
     */
    public function setRequired(bool $isPropertyRequired): PropertyInterface
    {
        return $this->getProperty()->setRequired($isPropertyRequired);
    }

    /**
     * @inheritdoc
     */
    public function isRequired(): bool
    {
        return $this->getProperty()->isRequired();
    }

    /**
     * @inheritdoc
     */
    public function setReadOnly(bool $isPropertyReadOnly): PropertyInterface
    {
        return $this->getProperty()->setReadOnly($isPropertyReadOnly);
    }

    /**
     * @inheritdoc
     */
    public function isReadOnly(): bool
    {
        return $this->getProperty()->isReadOnly();
    }

    /**
     * @inheritdoc
     */
    public function setDefaultValue($defaultValue): PropertyInterface
    {
        return $this->getProperty()->setDefaultValue($defaultValue);
    }

    /**
     * @inheritdoc
     */
    public function getDefaultValue()
    {
        return $this->getProperty()->getDefaultValue();
    }

    /**
     * @inheritdoc
     */
    public function setNestedSchema(Schema $schema): PropertyInterface
    {
        return $this->getProperty()->setNestedSchema($schema);
    }

    /**
     * @inheritdoc
     */
    public function getNestedSchema(): ?Schema
    {
        return $this->getProperty()->getNestedSchema();
    }
}
