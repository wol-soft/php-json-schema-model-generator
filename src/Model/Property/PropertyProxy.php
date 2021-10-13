<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
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
class PropertyProxy extends AbstractProperty
{
    /** @var string */
    protected $key;
    /** @var ResolvedDefinitionsCollection */
    protected $definitionsCollection;

    /**
     * PropertyProxy constructor.
     *
     * @param string $name The name must be provided separately as the name is not bound to the structure of a
     * referenced schema. Consequently two properties with different names can refer an identical schema utilizing the
     * PropertyProxy. By providing a name to each of the proxies the resulting properties will get the correct names.
     * @param JsonSchema $jsonSchema
     * @param ResolvedDefinitionsCollection $definitionsCollection
     * @param string $key
     *
     * @throws SchemaException
     */
    public function __construct(
        string $name,
        JsonSchema $jsonSchema,
        ResolvedDefinitionsCollection $definitionsCollection,
        string $key
    ) {
        parent::__construct($name, $jsonSchema);

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
    public function getType(bool $outputType = false): ?PropertyType
    {
        return $this->getProperty()->getType($outputType);
    }

    /**
     * @inheritdoc
     */
    public function setType(PropertyType $type = null, PropertyType $outputType = null): PropertyInterface
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
        return array_map(function (PropertyValidatorInterface $propertyValidator): PropertyValidatorInterface {
            return $propertyValidator->withProperty($this);
        }, $this->getProperty()->getOrderedValidators());
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
    public function resolveDecorator(string $input, bool $nestedProperty): string
    {
        foreach ($this->getProperty()->getDecorators() as $decorator) {
            $input = $decorator->decorate($input, $this, $nestedProperty);
        }

        return $input;
    }

    /**
     * @inheritdoc
     */
    public function getDecorators(): array
    {
        return $this->getProperty()->getDecorators();
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
    public function getDefaultValue(): ?string
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

    /**
     * @inheritdoc
     */
    public function getJsonSchema(): JsonSchema
    {
        return $this->getProperty()->getJsonSchema();
    }

    /**
     * @inheritdoc
     */
    public function setInternal(bool $isPropertyInternal): PropertyInterface
    {
        return $this->getProperty()->setInternal($isPropertyInternal);
    }

    /**
     * @inheritdoc
     */
    public function isInternal(): bool
    {
        return $this->getProperty()->isInternal();
    }

    public function __clone()
    {
        $cloneKey = $this->key . uniqid();
        $this->definitionsCollection->offsetSet($cloneKey, clone $this->definitionsCollection->offsetGet($this->key));
        $this->key = $cloneKey;
    }
}
