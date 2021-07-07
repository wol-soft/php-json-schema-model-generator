<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\Hook;

interface SerializationHookInterface extends SchemaHookInterface
{
    public function getCode(): string;
}
