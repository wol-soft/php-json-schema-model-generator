<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\SchemaProvider;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProvider\RecursiveDirectoryProvider;
use PHPModelGenerator\Tests\Fixtures\FileGetContentsFailureSimulator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RecursiveDirectoryProviderTest extends TestCase
{
    private string $schemaDir;
    private string $outputDir;

    public function setUp(): void
    {
        $this->schemaDir = TEST_BASE_DIR . '/RecursiveDirectoryProviderTest/schemas';
        $this->outputDir = TEST_BASE_DIR . '/RecursiveDirectoryProviderTest/output';

        @mkdir($this->schemaDir, 0777, true);
        @mkdir($this->outputDir, 0777, true);
    }

    public function tearDown(): void
    {
        $this->removeDirectory($this->schemaDir);
        $this->removeDirectory($this->outputDir);
    }

    /**
     * A file whose content is empty (zero bytes) cannot be parsed and must throw a SchemaException
     * rather than silently skipping or producing garbled output.
     */
    public function testEmptyJsonFileThrowsSchemaException(): void
    {
        file_put_contents($this->schemaDir . '/empty.json', '');

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/^Invalid JSON-Schema file .+empty\.json$/');

        (new ModelGenerator(
            (new GeneratorConfiguration())->setLogger(new NullLogger()),
        ))->generateModels(
            new RecursiveDirectoryProvider($this->schemaDir),
            $this->outputDir,
        );
    }

    /**
     * A $ref pointing to a file whose JSON is valid but decodes to a non-array value must cause
     * getRef() to throw SchemaException. The referenced file cannot be used as a schema.
     *
     * Tested via a direct getRef() call so the exception is not wrapped by PropertyFactory.
     */
    public function testGetRefToNonObjectJsonThrowsSchemaException(): void
    {
        $refFilename = 'non_object_ref.json';
        file_put_contents($this->schemaDir . '/' . $refFilename, '42');

        $provider = new RecursiveDirectoryProvider($this->schemaDir);
        // Use a normalised current-file path so dirname() produces a backslash-only path
        // and the candidate path remains resolvable on all platforms.
        $currentFile = realpath($this->schemaDir) . DIRECTORY_SEPARATOR . 'dummy.json';

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            '/^Referenced JSON-Schema file .+ must contain a JSON object$/',
        );
        $provider->getRef($currentFile, null, $refFilename);
    }

    /**
     * A $ref that resolves to an existing local filesystem entry which cannot be read as a regular
     * file must produce a distinct "failed to read" message naming the resolved path, rather than
     * being misreported as "non existing" (which implies no path could be resolved at all).
     *
     * There is no portable, platform-independent filesystem trick to reproduce "path exists but
     * cannot be read": a Unix domain socket reproduces it on Linux/macOS but has no Windows
     * equivalent (PHP's "unix://" transport isn't supported there), a directory returns "" - not
     * false - from file_get_contents() on Linux (falling through to the "Invalid JSON-Schema file"
     * check instead), and permission bits are bypassed entirely when tests run as root, which they
     * commonly do. FileGetContentsFailureSimulator arms the namespaced file_get_contents() override
     * (tests/Fixtures/file_get_contents_override.php) to fail for one specific, otherwise perfectly
     * readable file instead, so the test is deterministic on every platform and the exact exception
     * message can be asserted rather than a platform-dependent regex.
     */
    public function testGetRefToUnreadableLocalFileThrowsSchemaException(): void
    {
        $refFilename = 'unreadable.json';
        file_put_contents($this->schemaDir . '/' . $refFilename, '{}');
        $resolvedPath = realpath($this->schemaDir . '/' . $refFilename);

        FileGetContentsFailureSimulator::armFor(
            $resolvedPath,
            "file_get_contents($resolvedPath): Failed to open stream: simulated read failure",
        );

        try {
            $provider = new RecursiveDirectoryProvider($this->schemaDir);
            // Use a normalised current-file path so dirname() produces a backslash-only path
            // and the candidate path remains resolvable on all platforms.
            $currentFile = realpath($this->schemaDir) . DIRECTORY_SEPARATOR . 'dummy.json';

            $this->expectException(SchemaException::class);
            $this->expectExceptionMessage(
                "Failed to read referenced JSON-Schema file $refFilename from $resolvedPath: "
                    . "file_get_contents($resolvedPath): Failed to open stream: simulated read failure",
            );

            $provider->getRef($currentFile, null, $refFilename);
        } finally {
            FileGetContentsFailureSimulator::disarm();
        }
    }

    /**
     * Files whose JSON decodes to a non-object value (boolean, number, string, null) are silently
     * skipped — consistent with how SchemaProcessor skips non-object schemas.
     */
    public function testNonObjectJsonFilesAreSkipped(): void
    {
        file_put_contents($this->schemaDir . '/valid.json', json_encode([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ]));
        file_put_contents($this->schemaDir . '/true_schema.json', 'true');
        file_put_contents($this->schemaDir . '/false_schema.json', 'false');
        file_put_contents($this->schemaDir . '/number_schema.json', '42');
        file_put_contents($this->schemaDir . '/string_schema.json', '"hello"');
        file_put_contents($this->schemaDir . '/null_schema.json', 'null');

        (new ModelGenerator(
            (new GeneratorConfiguration())->setLogger(new NullLogger()),
        ))->generateModels(
            new RecursiveDirectoryProvider($this->schemaDir),
            $this->outputDir,
        );

        $this->assertTrue(file_exists($this->outputDir . '/Valid.php'));
        $this->assertCount(1, glob($this->outputDir . '/*.php'));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }

        rmdir($path);
    }
}
