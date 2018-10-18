<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model;

use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\PropertyDecoratorInterface;

/**
 * Class Property
 *
 * @package PHPModelGenerator\Model
 */
class Property
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
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getAttribute(): string
    {
        return $this->attribute;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @param string $type
     *
     * @return Property
     */
    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Add a validator for the property
     *
     * @param PropertyValidatorInterface $validator
     * @param int                        $priority
     *
     * @return Property
     */
    public function addValidator(PropertyValidatorInterface $validator, int $priority = 99): self
    {
        $this->validator[] = new Validator($validator, $priority);

        return $this;
    }

    /**
     * Retrieve all added validators ordered by priority
     *
     * @return PropertyValidatorInterface[]
     */
    public function getValidators(): array
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
     * @return Property[]
     */
    public function getNestedProperties(): array
    {
        return $this->nestedProperties;
    }

    /**
     * @param Property $nestedProperty
     *
     * @return Property
     */
    public function addNestedProperty(Property $nestedProperty): self
    {
        $this->nestedProperties[] = $nestedProperty;

        return $this;
    }

    /**
     * Add a decorator to the property
     *
     * @param PropertyDecoratorInterface $decorator
     *
     * @return Property
     */
    public function addDecorator(PropertyDecoratorInterface $decorator): self
    {
        $this->decorators[] = $decorator;

        return $this;
    }

    public function resolveDecorator(string $input): string
    {
        foreach ($this->decorators as $decorator) {
            $input = $decorator->decorate($input);
        }

        return $input;
    }

    /**
     * @return bool
     */
    public function hasDecorators(): bool
    {
        return count($this->decorators) > 0;
    }

    /**
     * Get a list of all required classes
     *
     * @return array
     */
    public function getClasses(): array
    {
        $use = [];

        foreach ($this->getValidators() as $validator) {
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
     * @param bool $isPropertyRequired
     *
     * @return Property
     */
    public function setRequired(bool $isPropertyRequired): self
    {
        $this->isPropertyRequired = $isPropertyRequired;
        return $this;
    }

    /**
     * @return bool
     */
    public function isRequired(): bool
    {
        return $this->isPropertyRequired;
    }
}
