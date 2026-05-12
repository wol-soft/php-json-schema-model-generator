<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Fixtures;

use PHPModelGenerator\MediaString\ContentValidatorInterface;

class AlwaysInvalidContentValidator implements ContentValidatorInterface
{
    public static function validate(string $value): void
    {
        throw new \RuntimeException("Content validation failed for: $value");
    }
}
