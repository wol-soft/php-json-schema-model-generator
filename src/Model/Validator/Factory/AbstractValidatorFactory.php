<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory;

use PHPModelGenerator\Draft\Modifier\ModifierInterface;

abstract class AbstractValidatorFactory implements ModifierInterface
{
    protected string $key;

    public function setKey(string $key): void
    {
        $this->key = $key;
    }
}
