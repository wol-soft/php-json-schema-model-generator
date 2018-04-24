<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model;

/**
 * Class Property
 *
 * @package PHPModelGenerator\Model
 */
class Property
{
    /** @var string */
    protected $name;
    /** @var string */
    protected $attribute;
    /** @var string */
    protected $type;
    /** @var bool */
    protected $isRequired;
    /** @var array */
    protected $validator = [];

    /**
     * Property constructor.
     *
     * @param string $name
     * @param string $type
     * @param bool   $isRequired
     */
    public function __construct(string $name, string $type, bool $isRequired = false)
    {
        $this->attribute = $this->processAttributeName($name);
        $this->name = $name;
        $this->type = $type;
        $this->isRequired = $isRequired;
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
     * @return bool
     */
    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    /**
     * Add a validator for the property
     *
     * @param PropertyValidator $validator
     */
    public function addValidator(PropertyValidator $validator)
    {
        $this->validator[] = $validator;
    }

    /**
     * @return PropertyValidator[]
     */
    public function getValidators(): array
    {
        return $this->validator;
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
        $elements = array_map(function ($element) {
                return ucfirst(strtolower($element));
            },
            preg_split('/[^a-z]/i', $name)
        );

        return lcfirst(join('', $elements));
    }
}
