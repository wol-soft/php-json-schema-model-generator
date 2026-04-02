<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Attributes;

final class PhpAttribute
{
    public const JSON_POINTER = 1;
    public const SCHEMA_NAME = 2;
    public const SOURCE = 4;
    public const JSON_SCHEMA = 8;

    /**
     * @param string  $fqcn      Fully-qualified class name of the attribute.
     * @param array   $arguments Pre-rendered PHP expression strings.
     *                           String keys  → named arguments  (key: value)
     *                           Integer keys → positional arguments (value)
     */
    public function __construct(
        private readonly string $fqcn,
        private readonly array $arguments = [],
    ) {}

    public function getFqcn(): string
    {
        return $this->fqcn;
    }

    /**
     * Render the attribute body — the content that appears inside #[...].
     * Uses the simple (unqualified) class name; the FQCN is added via use-import separately.
     */
    public function render(): string
    {
        $parts = explode('\\', $this->fqcn);
        $shortName = end($parts);

        if (empty($this->arguments)) {
            return $shortName;
        }

        $args = [];
        foreach ($this->arguments as $key => $value) {
            $value = var_export($value, true);
            $args[] = is_string($key) ? "$key: $value" : $value;
        }

        return $shortName . '(' . implode(', ', $args) . ')';
    }
}
