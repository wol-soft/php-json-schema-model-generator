<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Attributes;

final class PhpAttribute
{
    public const int JSON_POINTER = 1;
    public const int SCHEMA_NAME = 2;
    public const int SOURCE = 4;
    public const int JSON_SCHEMA = 8;
    public const int REQUIRED = 16;
    public const int READ_WRITE_ONLY = 32;
    public const int DEPRECATED = 64;

    // Attributes which are always enabled because they are used for internal functionality
    public const int ALWAYS_ENABLED_ATTRIBUTES = self::JSON_POINTER | self::SCHEMA_NAME;

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
