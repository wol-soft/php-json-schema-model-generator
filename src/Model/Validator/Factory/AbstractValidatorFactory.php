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

    public function getKey(): ?string
    {
        return isset($this->key) ? $this->key : null;
    }

    /**
     * True for a value that is a valid subschema: a boolean or a schema object. json_decode(...,
     * true) maps the empty object `{}` and the empty array `[]` to the same empty PHP array, so
     * `[]` cannot be told apart from `{}` and is accepted as the empty schema. A non-empty list is
     * never a schema object and is rejected, as are scalars and null.
     */
    protected function isBooleanOrSchemaObject(mixed $value): bool
    {
        return is_bool($value) || (is_array($value) && ($value === [] || !array_is_list($value)));
    }
}
