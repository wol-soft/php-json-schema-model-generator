<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Property;

use PHPModelGenerator\Model\ResolvedDefinitionsCollection;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\PropertyDecoratorInterface;

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
    public function getType(): string
    {
        return $this->getProperty()->getType();
    }

    /**
     * @inheritdoc
     */
    public function setType(string $type): PropertyInterface
    {
        return $this->getProperty()->setType($type);
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
    public function setDescription(string $description): PropertyInterface
    {
        return $this->getProperty()->setDescription($description);
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
    public function filterValidators(callable $filter): void
    {
        $this->getProperty()->filterValidators($filter);
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
    public function getNestedProperties(): array
    {
        return $this->getProperty()->getNestedProperties();
    }

    /**
     * @inheritdoc
     */
    public function addNestedProperty(PropertyInterface $nestedProperty): PropertyInterface
    {
        return $this->getProperty()->addNestedProperty($nestedProperty);
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
    public function getExceptionClasses(): array
    {
        return $this->getProperty()->getExceptionClasses();
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
