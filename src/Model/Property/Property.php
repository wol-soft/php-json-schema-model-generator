<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Property;

use PHPModelGenerator\Exception\SchemaException;
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
    /** @var string|null */
    protected $outputType = null;
    /** @var bool */
    protected $isPropertyRequired = true;
    /** @var bool */
    protected $isPropertyReadOnly = false;
    /** @var string */
    protected $description = '';
    /** @var mixed */
    protected $defaultValue;

    /** @var Validator[] */
    protected $validator = [];
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
     * @param string $description
     *
     * @throws SchemaException
     */
    public function __construct(string $name, string $type, string $description = '')
    {
        $this->attribute = $this->processAttributeName($name);
        $this->name = $name;
        $this->type = $type;
        $this->description = $description;
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
    public function getType(bool $outputType = false): string
    {
        return $outputType && $this->outputType !== null ? $this->outputType : $this->type;
    }

    /**
     * @inheritdoc
     */
    public function setType(string $type, ?string $outputType = null): PropertyInterface
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
        $input = $outputType && $this->outputType !== null ? $this->outputType : $this->type;

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
     *
     * @throws SchemaException
     */
    protected function processAttributeName(string $name): string
    {
        $attributeName = preg_replace_callback(
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
            preg_split('/[^a-z0-9]/i', $attributeName)
        );

        $attributeName = lcfirst(join('', $elements));

        if (empty($attributeName)) {
            throw new SchemaException("Property name '$name' results in an empty attribute name");
        }

        return $attributeName;
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
}
