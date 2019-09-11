<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Property;

use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\PropertyDecoratorInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintDecoratorInterface;

/**
 * Class Property
 *
 * @package PHPModelGenerator\Model\Property
 */
class Property implements PropertyInterface
{
    /** @var string */
    protected $name = '';
    /** @var string */
    protected $attribute = '';
    /** @var string */
    protected $type = 'null';
    /** @var bool */
    protected $isPropertyRequired = true;
    /** @var string */
    protected $description = '';
    /** @var mixed */
    protected $defaultValue;

    /** @var Validator[] */
    protected $validator = [];
    /** @var Property[] */
    protected $nestedProperties = [];
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
     * @param string $type
     */
    public function __construct(string $name, string $type)
    {
        $this->attribute = $this->processAttributeName($name);
        $this->name = $name;
        $this->type = $type;
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritdoc
     */
    public function getAttribute(): string
    {
        return $this->attribute;
    }

    /**
     * @inheritdoc
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @inheritdoc
     */
    public function setType(string $type): PropertyInterface
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getTypeHint(): string
    {
        $input = $this->type;

        foreach ($this->typeHintDecorators as $decorator) {
            $input = $decorator->decorate($input);
        }

        return $input ?? 'mixed';
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
    public function setDescription(string $description): PropertyInterface
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function addValidator(PropertyValidatorInterface $validator, int $priority = 99): PropertyInterface
    {
        $this->validator[] = new Validator($validator, $priority);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getValidators(): array
    {
        return $this->validator;
    }

    /**
     * @inheritdoc
     */
    public function filterValidators(callable $filter): PropertyInterface
    {
        $this->validator = array_filter($this->validator, $filter);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getOrderedValidators(): array
    {
        usort(
            $this->validator,
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
            $this->validator
        );
    }

    /**
     * @inheritdoc
     */
    public function getNestedProperties(): array
    {
        return $this->nestedProperties;
    }

    /**
     * @inheritdoc
     */
    public function addNestedProperty(PropertyInterface $nestedProperty): PropertyInterface
    {
        $this->nestedProperties[] = $nestedProperty;

        return $this;
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
    public function resolveDecorator(string $input): string
    {
        foreach ($this->decorators as $decorator) {
            $input = $decorator->decorate($input, $this);
        }

        return $input;
    }

    /**
     * @inheritdoc
     */
    public function hasDecorators(): bool
    {
        return count($this->decorators) > 0;
    }

    /**
     * Convert a name of a JSON-field into a valid PHP variable name to be used as class attribute
     *
     * @param string $name
     *
     * @return string
     */
    protected function processAttributeName(string $name): string
    {
        $name = preg_replace_callback(
            '/([a-z][a-z0-9]*)([A-Z])/',
            function ($matches) {
                return "{$matches[1]}-{$matches[2]}";
            },
            $name
        );

        $elements = array_map(
            function ($element) {
                return ucfirst(strtolower($element));
            },
            preg_split('/[^a-z0-9]/i', $name)
        );

        return lcfirst(join('', $elements));
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
    public function setDefaultValue($defaultValue): PropertyInterface
    {
        $this->defaultValue = $defaultValue;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getDefaultValue()
    {
        return var_export($this->defaultValue, true);
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
}
