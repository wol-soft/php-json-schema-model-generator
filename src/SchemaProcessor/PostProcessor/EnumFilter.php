<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use BackedEnum;
use PHPModelGenerator\Filter\TransformingFilterInterface;

class EnumFilter implements TransformingFilterInterface
{
    public function getAcceptedTypes(): array
    {
        return ['string', 'null'];
    }

    public function getToken(): string
    {
        return 'php_model_generator_enum';
    }

    public function getFilter(): array
    {
        return [self::class, 'filter'];
    }

    public function getSerializer(): array
    {
        return [self::class, 'serialize'];
    }

// TODO: move to production lib
    public static function filter($value, $options): ?BackedEnum
    {
        return $value === null ? null : $options['fqcn']::from($value);
    }

    public static function serialize(?BackedEnum $value): null | int | string
    {
        return $value?->value;
    }
}
