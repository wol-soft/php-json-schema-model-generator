<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\Decorator\Property;

use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Utils\RenderFactory;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class ObjectInstantiationDecorator
 *
 * @package PHPModelGenerator\PropertyProcessor\Decorator\Property
 */
class ObjectInstantiationDecorator implements PropertyDecoratorInterface
{
    /**
     * ObjectInstantiationDecorator constructor.
     */
    public function __construct(protected string $className, protected GeneratorConfiguration $generatorConfiguration)
    {
    }

    /**
     * @inheritdoc
     */
    public function decorate(string $input, PropertyInterface $property, bool $nestedProperty): string
    {
        return RenderFactory::create(
            join(DIRECTORY_SEPARATOR, [__DIR__, '..', '..', '..', 'Templates']) . DIRECTORY_SEPARATOR,
        )->renderTemplate(
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
