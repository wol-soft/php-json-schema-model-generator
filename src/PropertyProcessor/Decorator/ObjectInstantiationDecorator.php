<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator;

/**
 * Class ObjectInstantiationDecorator
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator
 */
class ObjectInstantiationDecorator implements PropertyDecoratorInterface
{
    /** @var string */
    protected $className;

    /**
     * ObjectInstantiationDecorator constructor.
     *
     * @param string $className
     */
    public function __construct(string $className)
    {
        $this->className = $className;
    }

    /**
     * @inheritdoc
     */
    public function decorate(string $input): string
    {
        return "new {$this->className}($input)";
    }
}
