<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\PropertyDecoratorInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintDecoratorInterface;

/**
 * Class Property
 *
 * @package PHPModelGenerator\Model\Property
 */
class Property extends AbstractProperty
{
    /** @var PropertyType */
    protected $type;
    /** @var PropertyType|null */
    protected $outputType = null;
    /** @var bool */
    protected $isPropertyRequired = true;
    /** @var bool */
    protected $isPropertyReadOnly = false;
    /** @var bool */
    protected $isPropertyInternal = false;
    /** @var string */
    protected $description = '';
    /** @var mixed */
    protected $defaultValue;

    /** @var Validator[] */
    protected $validators = [];
    /** @var Schema */
    protected $schema;
    /** @var PropertyDecoratorInterface[] */
    public $decorators = [];
    /** @var TypeHintDecoratorInterface[] */
    public $typeHintDecorators = [];

    /**
     * Property constructor.
     *
     * @param string $name
     * @param PropertyType|null $type
     * @param JsonSchema $jsonSchema
     * @param string $description
     *
     * @throws SchemaException
     */
    public function __construct(string $name, ?PropertyType $type, JsonSchema $jsonSchema, string $description = '')
    {
        parent::__construct($name, $jsonSchema);

        $this->type = $type;
        $this->description = $description;
    }

    /**
     * @inheritdoc
     */
    public function getType(bool $outputType = false): ?PropertyType
    {
        // If the output type differs from an input type also accept the output type
        // (in this case the transforming filter is skipped)
        // TODO: PHP 8 use union types to accept multiple input types
        if (!$outputType
            && $this->type
            && $this->outputType
            && $this->outputType->getName() !== $this->type->getName()
        ) {
            return null;
        }

        return $outputType && $this->outputType !== null ? $this->outputType : $this->type;
    }

    /**
     * @inheritdoc
     */
    public function setType(PropertyType $type = null, PropertyType $outputType = null): PropertyInterface
    {
        $this->type = $type;
        $this->outputType = $outputType;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getTypeHint(bool $outputType = false): string
    {
        $input = [$outputType && $this->outputType !== null ? $this->outputType : $this->type];

        // If the output type differs from an input type also accept the output type
        if (!$outputType && $this->outputType !== null && $this->outputType !== $this->type) {
            $input = [$this->type, $this->outputType];
        }

        $input = join('|', array_filter(array_map(function (?PropertyType $input) use ($outputType): string {
            $typeHint = $input ? $input->getName() : '';

            foreach ($this->typeHintDecorators as $decorator) {
                $typeHint = $decorator->decorate($typeHint, $outputType);
            }

            return $typeHint;
        }, $input)));

        return $input ?: 'mixed';
    }

    /**
     * @inheritdoc
     */
    public function addTypeHintDecorator(TypeHintDecoratorInterface $typeHintDecorator): PropertyInterface
    {
        $this->typeHintDecorators[] = $typeHintDecorator;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @inheritdoc
     */
    public function addValidator(PropertyValidatorInterface $validator, int $priority = 99): PropertyInterface
    {
        $this->validators[] = new Validator($validator, $priority);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getValidators(): array
    {
        return $this->validators;
    }

    /**
     * @inheritdoc
     */
    public function filterValidators(callable $filter): PropertyInterface
    {
        $this->validators = array_filter($this->validators, $filter);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getOrderedValidators(): array
    {
        usort(
            $this->validators,
            function (Validator $validator, Validator $comparedValidator) {
                if ($validator->getPriority() == $comparedValidator->getPriority()) {
                    return 0;
                }
                return ($validator->getPriority() < $comparedValidator->getPriority()) ? -1 : 1;
            }
        );

        return array_map(
            function (Validator $validator) {
                return $validator->getValidator();
            },
            $this->validators
        );
    }

    /**
     * @inheritdoc
     */
    public function addDecorator(PropertyDecoratorInterface $decorator): PropertyInterface
    {
        $this->decorators[] = $decorator;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function resolveDecorator(string $input, bool $nestedProperty): string
    {
        foreach ($this->decorators as $decorator) {
            $input = $decorator->decorate($input, $this, $nestedProperty);
        }

        return $input;
    }

    /**
     * @inheritdoc
     */
    public function getDecorators(): array
    {
        return $this->decorators;
    }

    /**
     * @inheritdoc
     */
    public function setRequired(bool $isPropertyRequired): PropertyInterface
    {
        $this->isPropertyRequired = $isPropertyRequired;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setReadOnly(bool $isPropertyReadOnly): PropertyInterface
    {
        $this->isPropertyReadOnly = $isPropertyReadOnly;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setDefaultValue($defaultValue): PropertyInterface
    {
        $this->defaultValue = $defaultValue;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getDefaultValue(): ?string
    {
        return $this->defaultValue !== null ? var_export($this->defaultValue, true) : null;
    }

    /**
     * @inheritdoc
     */
    public function isRequired(): bool
    {
        return $this->isPropertyRequired;
    }

    /**
     * @inheritdoc
     */
    public function isReadOnly(): bool
    {
        return $this->isPropertyReadOnly;
    }

    /**
     * @inheritdoc
     */
    public function setNestedSchema(Schema $schema): PropertyInterface
    {
        $this->schema = $schema;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getNestedSchema(): ?Schema
    {
        return $this->schema;
    }

    /**
     * @inheritdoc
     */
    public function setInternal(bool $isPropertyInternal): PropertyInterface
    {
        $this->isPropertyInternal = $isPropertyInternal;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function isInternal(): bool
    {
        return $this->isPropertyInternal;
    }
}
