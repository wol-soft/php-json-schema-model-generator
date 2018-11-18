<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Property;

use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\PropertyDecoratorInterface;

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

    /** @var Validator[] */
    protected $validator = [];
    /** @var Property[] */
    protected $nestedProperties = [];
    /** @var PropertyDecoratorInterface[] */
    public $decorators = [];

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
            $input = $decorator->decorate($input);
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
     * @inheritdoc
     */
    public function getClasses(): array
    {
        $use = [];

        foreach ($this->getOrderedValidators() as $validator) {
            $use[] = $validator->getExceptionClass();
        }

        foreach ($this->getNestedProperties() as $property) {
            $use = array_merge($use, $property->getClasses());
        }

        return $use;
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
        $elements = array_map(
            function ($element) {
                return ucfirst(strtolower($element));
            },
            preg_split('/[^a-z]/i', $name)
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
    public function isRequired(): bool
    {
        return $this->isPropertyRequired;
    }
}
