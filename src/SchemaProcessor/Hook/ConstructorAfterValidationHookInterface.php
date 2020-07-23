<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\Hook;

interface ConstructorAfterValidationHookInterface extends SchemaHookInterface
{
    public function getCode(): string;
}
