<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Fixtures;

use PHPModelGenerator\MediaString\ContentValidatorInterface;

class AlwaysValidContentValidator implements ContentValidatorInterface
{
    public static function validate(string $value): void
    {
    }
}
