<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\CodeQuality;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Opt-in (PHPCS_GENERATED_CODE_AUDIT=1) hook that lets the whole test suite double as a corpus for the generated
 * code coding standard: every test's tearDown() hands the models directory to collect(), which copies every PHP
 * file found there - not just the main schema classes returned by ModelGenerator::generateModels(), but also
 * Builder/Companion/Enum classes, which their post processors write directly to disk and never register in that
 * return value - into a process-lifetime directory. A single phpcs run over that whole directory, triggered once
 * via register_shutdown_function() after the last test finishes, produces one JSON report grouping findings by
 * the test that produced each file, for evaluating formatting edge cases across the existing schema fixtures
 * without hand-picking a subset of them.
 */
final class GeneratedCodeAuditor
{
    private const ENV_VAR = 'PHPCS_GENERATED_CODE_AUDIT';
    private const REPORT_PATH_ENV_VAR = 'PHPCS_GENERATED_CODE_AUDIT_REPORT';

    private static ?string $auditDirectory = null;
    private static int $fileCounter = 0;
    private static array $testLabelByAuditPath = [];

    public static function isEnabled(): bool
    {
        return (bool) getenv(self::ENV_VAR);
    }

    public static function collect(string $modelsDirectory, string $testLabel): void
    {
        if (!is_dir($modelsDirectory)) {
            return;
        }

        if (self::$auditDirectory === null) {
            self::$auditDirectory = sys_get_temp_dir() . '/phpcs-generated-code-audit-' . uniqid('', true);
            mkdir(self::$auditDirectory, 0777, true);

            register_shutdown_function([self::class, 'report']);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($modelsDirectory, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            self::$fileCounter++;
            $auditPath = self::$auditDirectory . '/' . self::$fileCounter . '_' . $file->getFilename();

            copy($file->getPathname(), $auditPath);

            self::$testLabelByAuditPath[$auditPath] = $testLabel;
        }
    }

    public static function report(): void
    {
        if (self::$auditDirectory === null) {
            return;
        }

        // phpcs writes its "Time: ...; Memory: ..." summary to stderr even for machine-readable reports, so
        // stderr must stay separate from stdout - merging it would corrupt the JSON report on stdout
        $command = sprintf(
            '%s --standard=%s --report=json %s',
            escapeshellarg(__DIR__ . '/../../vendor/bin/phpcs'),
            escapeshellarg(__DIR__ . '/../generated_code_phpcs.xml'),
            escapeshellarg(self::$auditDirectory),
        );

        $phpcsReport = json_decode(shell_exec($command) ?? '', true) ?? ['totals' => [], 'files' => []];

        $findings = [];
        foreach ($phpcsReport['files'] ?? [] as $auditPath => $fileReport) {
            foreach ($fileReport['messages'] as $message) {
                $findings[] = [
                    'test' => self::$testLabelByAuditPath[$auditPath] ?? 'unknown',
                    'file' => preg_replace('/^\d+_/', '', basename($auditPath)),
                    'line' => $message['line'],
                    'type' => $message['type'],
                    'source' => $message['source'],
                    'message' => $message['message'],
                ];
            }
        }

        $reportPath = getenv(self::REPORT_PATH_ENV_VAR) ?: (sys_get_temp_dir() . '/generated-code-audit-report.json');

        file_put_contents(
            $reportPath,
            json_encode(
                [
                    'totals' => $phpcsReport['totals'] ?? [],
                    'filesChecked' => count($phpcsReport['files'] ?? []),
                    'findings' => $findings,
                ],
                JSON_PRETTY_PRINT,
            ),
        );

        fwrite(
            STDERR,
            sprintf(
                "\nGenerated-code audit: %d finding(s) across %d file(s). Report: %s\n",
                count($findings),
                count($phpcsReport['files'] ?? []),
                $reportPath,
            ),
        );
    }
}
