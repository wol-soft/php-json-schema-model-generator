<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\Hook;

use PHPModelGenerator\Model\Property\PropertyInterface;

interface GetterHookInterface extends SchemaHookInterface
{
    public function getCode(PropertyInterface $property): string;
}
