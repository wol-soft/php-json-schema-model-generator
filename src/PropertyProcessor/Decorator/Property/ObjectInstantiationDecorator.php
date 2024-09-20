<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Decorator\Property;

use PHPMicroTemplate\Render;
use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class ObjectInstantiationDecorator
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator\Property
 */
class ObjectInstantiationDecorator implements PropertyDecoratorInterface
{
    /** @var Render */
    protected static $renderer;

    /**
     * ObjectInstantiationDecorator constructor.
     */
    public function __construct(protected string $className, protected GeneratorConfiguration $generatorConfiguration)
    {
        if (!static::$renderer) {
            static::$renderer = new Render(
                join(DIRECTORY_SEPARATOR, [__DIR__, '..', '..', '..', 'Templates']) . DIRECTORY_SEPARATOR,
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function decorate(string $input, PropertyInterface $property, bool $nestedProperty): string
    {
        return static::$renderer->renderTemplate(
            DIRECTORY_SEPARATOR . 'Decorator' . DIRECTORY_SEPARATOR . 'ObjectInstantiationDecorator.phptpl',
            [
                'input' => $input,
                'className' => $this->className,
                'nestedProperty' => $nestedProperty,
                'viewHelper' => new RenderHelper($this->generatorConfiguration),
                'generatorConfiguration' => $this->generatorConfiguration,
                'nestedValidator' => new PropertyValidator(
                    $property,
                    '',
                    NestedObjectException::class,
                    ['&$instantiationException'],
                ),
            ],
        );
    }
}
