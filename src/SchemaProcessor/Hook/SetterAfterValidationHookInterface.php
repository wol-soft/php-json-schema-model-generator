<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\Hook;

use PHPModelGenerator\Model\Property\PropertyInterface;

interface SetterAfterValidationHookInterface extends SchemaHookInterface
{
    public function getCode(PropertyInterface $property, bool $batchUpdate = false): string;
}
