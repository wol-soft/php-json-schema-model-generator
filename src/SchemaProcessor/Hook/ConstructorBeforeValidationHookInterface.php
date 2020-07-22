<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\Hook;

interface ConstructorBeforeValidationHookInterface extends SchemaHookInterface
{
    public function getCode(): string;
}
