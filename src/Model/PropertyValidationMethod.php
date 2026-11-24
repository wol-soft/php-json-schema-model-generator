<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * A method validating a value with the validators of a property. The body is rendered when the code of the method is
 * requested: the call which is rendered into the validators of the container is available without rendering the body,
 * so a validator which is part of its own schema (a recursive reference) calls the method instead of rendering itself
 * again and again.
 */
final class PropertyValidationMethod implements MethodInterface
{
    public function __construct(
        private readonly string $methodName,
        private readonly PropertyInterface $property,
        private readonly Schema $schema,
        private readonly GeneratorConfiguration $generatorConfiguration,
    ) {}

    public function getCode(): string
    {
        $validators = (new RenderHelper($this->generatorConfiguration))
            ->renderValidators($this->property, $this->schema, 1);

        return "private function {$this->methodName}(&\$value, \$modelData): void\n{\n    $validators\n}";
    }
}
