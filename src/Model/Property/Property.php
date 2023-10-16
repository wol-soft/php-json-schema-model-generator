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
    protected $nestedSchema;
    /** @var PropertyDecoratorInterface[] */
    public $decorators = [];
    /** @var TypeHintDecoratorInterface[] */
    public $typeHintDecorators = [];

    private $renderedTypeHints = [];
    /** @var int Track the amount of unresolved validators */
    private $pendingValidators = 0;

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

        $this->resolve();
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
    public function setType(
        PropertyType $type = null,
        PropertyType $outputType = null,
        bool $reset = false
    ): PropertyInterface {
        if ($reset) {
            $this->typeHintDecorators = [];
        }

        $this->type = $type;
        $this->outputType = $outputType;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getTypeHint(bool $outputType = false, array $skipDecorators = []): string
    {
        if (isset($this->renderedTypeHints[$outputType])) {
            return $this->renderedTypeHints[$outputType];
        }

        static $skipDec = [];

        $additionalSkips = array_diff($skipDecorators, $skipDec);
        $skipDec = array_merge($skipDec, $additionalSkips);

        $input = [$outputType && $this->outputType !== null ? $this->outputType : $this->type];

        // If the output type differs from an input type also accept the output type
        if (!$outputType && $this->outputType !== null && $this->outputType !== $this->type) {
            $input = [$this->type, $this->outputType];
        }

        $input = join(
            '|',
            array_filter(array_map(function (?PropertyType $input) use ($outputType, $skipDec): string {
                $typeHint = $input ? $input->getName() : '';

                $filteredDecorators = array_filter(
                    $this->typeHintDecorators,
                    static function (TypeHintDecoratorInterface $decorator) use ($skipDec): bool {
                        return !in_array(get_class($decorator), $skipDec);
                    }
                );

                foreach ($filteredDecorators as $decorator) {
                    $typeHint = $decorator->decorate($typeHint, $outputType);
                }

                return $typeHint;
            }, $input))
        );

        $skipDec = array_diff($skipDec, $additionalSkips);

        return $this->renderedTypeHints[$outputType] = $input ?: 'mixed';
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
        if (!$validator->isResolved()) {
            $this->isResolved = false;

            $this->pendingValidators++;

            $validator->onResolve(function () {
                if (--$this->pendingValidators === 0) {
                    $this->resolve();
                }
            });
        }

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
            static function (Validator $validator, Validator $comparedValidator): int {
                if ($validator->getPriority() == $comparedValidator->getPriority()) {
                    return 0;
                }
                return ($validator->getPriority() < $comparedValidator->getPriority()) ? -1 : 1;
            }
        );

        return array_map(
            static function (Validator $validator): PropertyValidatorInterface {
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
    public function setDefaultValue($defaultValue, bool $raw = false): PropertyInterface
    {
        $this->defaultValue = $defaultValue !== null && !$raw ? var_export($defaultValue, true) : $defaultValue;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getDefaultValue(): ?string
    {
        return $this->defaultValue;
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
        $this->nestedSchema = $schema;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getNestedSchema(): ?Schema
    {
        return $this->nestedSchema;
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
