<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Fixtures;

/**
 * Arms a single file path to make the namespaced file_get_contents() override
 * (tests/Fixtures/file_get_contents_override.php) report a read failure for that path only,
 * while every other call - in this test and every other test in the suite - passes through
 * to the real function unchanged.
 *
 * This exists so tests can exercise "path resolved but content could not be read" without
 * depending on OS-specific filesystem behaviour: a Unix domain socket reproduces that
 * condition on Linux/macOS but has no Windows equivalent (PHP's "unix://" transport is not
 * supported there), and Unix permission bits are bypassed entirely when tests run as root.
 */
final class FileGetContentsFailureSimulator
{
    private static ?string $armedPath = null;
    private static ?string $armedMessage = null;

    public static function armFor(string $path, string $message): void
    {
        self::$armedPath = $path;
        self::$armedMessage = $message;
    }

    public static function disarm(): void
    {
        self::$armedPath = null;
        self::$armedMessage = null;
    }

    public static function messageFor(string $path): ?string
    {
        return self::$armedPath === $path ? self::$armedMessage : null;
    }
}
