<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator;

use PHPMicroTemplate\Render;
use PHPModelGenerator\Exception\InvalidArgumentException;

/**
 * Class ObjectInstantiationDecorator
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator
 */
class ObjectInstantiationDecorator implements PropertyDecoratorInterface
{
    /** @var Render */
    protected static $renderer;
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

        if (!static::$renderer) {
            static::$renderer = new Render(
                join(DIRECTORY_SEPARATOR, [__DIR__, '..', '..', 'Templates']) . DIRECTORY_SEPARATOR
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function decorate(string $input): string
    {
        return static::$renderer->renderTemplate(
            DIRECTORY_SEPARATOR . 'Decorator' . DIRECTORY_SEPARATOR . 'ObjectInstantiationDecorator.phptpl',
            [
                'input' => $input,
                'className' => $this->className
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public function getExceptionClasses(): array
    {
        return [InvalidArgumentException::class];
    }
}
