<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Property;

use PHPModelGenerator\Attributes\JsonPointer;
use PHPModelGenerator\Attributes\SchemaName;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Attributes\AttributesTrait;
use PHPModelGenerator\Model\Attributes\PhpAttribute;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\SchemaDefinition\ResolvedDefinitionsCollection;
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
    // AttributesTrait provides local attribute storage so each proxy instance
    // has its own SchemaName/JsonPointer/etc. independent of the shared underlying
    // property. NOTE: isInternal() still delegates to getProperty() (the underlying),
    // while getAttributes() returns the proxy's local list. This split is intentional:
    // isInternal() controls visible-vs-hidden at the schema level (consistent across
    // all proxies of the same $def), while getAttributes() reflects per-proxy metadata.
    use AttributesTrait;

    private ?JsonSchema $overrideJsonSchema = null;

    /**
     * PropertyProxy constructor.
     *
     * @param string $name The name must be provided separately as the name is not bound to the structure of a
     * referenced schema. Consequently, two properties with different names can refer an identical schema utilizing the
     * PropertyProxy. By providing a name to each of the proxies the resulting properties will get the correct names.
     *
     * @throws SchemaException
     */
    public function __construct(
        string $name,
        JsonSchema $jsonSchema,
        protected ResolvedDefinitionsCollection $definitionsCollection,
        protected string $key,
    ) {
        parent::__construct($name, $jsonSchema);
    }

    /**
     * Get the property out of the resolved definitions collection to proxy function calls
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
    public function setType(
        ?PropertyType $type = null,
        ?PropertyType $outputType = null,
        bool $reset = false,
    ): PropertyInterface {
        $this->getProperty()->setType($type, $outputType, $reset);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getTypeHint(bool $outputType = false, array $skipDecorators = []): string
    {
        return $this->getProperty()->getTypeHint($outputType, $skipDecorators);
    }

    /**
     * @inheritdoc
     */
    public function addTypeHintDecorator(TypeHintDecoratorInterface $typeHintDecorator): PropertyInterface
    {
        $this->getProperty()->addTypeHintDecorator($typeHintDecorator);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getDescription(): string
    {
        return $this->getProperty()->getDescription();
    }

    public function getComment(): ?string
    {
        return $this->getProperty()->getComment();
    }

    public function setComment(string $comment): PropertyInterface
    {
        $this->getProperty()->setComment($comment);

        return $this;
    }

    public function getExamples(): array
    {
        return $this->getProperty()->getExamples();
    }

    public function setExamples(array $examples): PropertyInterface
    {
        $this->getProperty()->setExamples($examples);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function addValidator(
        PropertyValidatorInterface $validator,
        int $priority = 99,
        ?string $sourceKey = null,
    ): PropertyInterface {
        $this->getProperty()->addValidator($validator, $priority, $sourceKey);

        return $this;
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
        $this->getProperty()->filterValidators($filter);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getOrderedValidators(): array
    {
        return array_map(
            fn(PropertyValidatorInterface $propertyValidator): PropertyValidatorInterface =>
                $propertyValidator->withProperty($this),
            $this->getProperty()->getOrderedValidators(),
        );
    }

    /**
     * @inheritdoc
     */
    public function addDecorator(PropertyDecoratorInterface $decorator): PropertyInterface
    {
        $this->getProperty()->addDecorator($decorator);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function filterDecorators(callable $filter): PropertyInterface
    {
        $this->getProperty()->filterDecorators($filter);

        return $this;
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
        $this->getProperty()->setRequired($isPropertyRequired);

        return $this;
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
        $this->getProperty()->setReadOnly($isPropertyReadOnly);

        return $this;
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
    public function setWriteOnly(bool $isPropertyWriteOnly): PropertyInterface
    {
        $this->getProperty()->setWriteOnly($isPropertyWriteOnly);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function isWriteOnly(): bool
    {
        return $this->getProperty()->isWriteOnly();
    }

    /**
     * @inheritdoc
     */
    public function setDefaultValue($defaultValue, bool $raw = false): PropertyInterface
    {
        $this->getProperty()->setDefaultValue($defaultValue, $raw);

        return $this;
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
        $this->getProperty()->setNestedSchema($schema);

        return $this;
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
        return $this->overrideJsonSchema ?? $this->getProperty()->getJsonSchema();
    }

    /**
     * @inheritdoc
     *
     * Stores a local override rather than delegating to the underlying property, preventing
     * mutation of a shared $ref-resolved property when only this proxy's schema must change.
     */
    public function setJsonSchema(JsonSchema $jsonSchema): static
    {
        $this->overrideJsonSchema = $jsonSchema;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setInternal(bool $isPropertyInternal): PropertyInterface
    {
        $this->getProperty()->setInternal($isPropertyInternal);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function isInternal(): bool
    {
        return $this->getProperty()->isInternal();
    }
}
