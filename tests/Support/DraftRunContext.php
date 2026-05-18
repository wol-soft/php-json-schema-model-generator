<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Support;

/**
 * Static registry that ApplicableDraftsMetadataParser populates during test building and that
 * AbstractPHPModelGeneratorTestCase::generateClass() reads to apply the correct draft version.
 *
 * Keyed by "{ClassName}::{methodName}::{dataName}" (where dataName matches $this->dataName()
 * at runtime). Each entry is the JsonSchemaDraft enum case that applies to that test run.
 *
 * Only the draft dimension is stored here. Error-collection mode is controlled by whatever
 * GeneratorConfiguration the test passes to generateClass().
 */
final class DraftRunContext
{
    /** @var array<string, JsonSchemaDraft> */
    private static array $drafts = [];

    public static function registerDraftForDataName(
        string $className,
        string $methodName,
        string $dataName,
        JsonSchemaDraft $draft,
    ): void {
        self::$drafts["{$className}::{$methodName}::{$dataName}"] = $draft;
    }

    public static function getDraftForDataName(
        string $className,
        string $methodName,
        string $dataName,
    ): ?JsonSchemaDraft {
        return self::$drafts["{$className}::{$methodName}::{$dataName}"] ?? null;
    }

    public static function reset(): void
    {
        self::$drafts = [];
    }
}
