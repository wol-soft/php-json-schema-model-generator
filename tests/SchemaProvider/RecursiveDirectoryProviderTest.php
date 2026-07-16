<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\SchemaProvider;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProvider\RecursiveDirectoryProvider;
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
     * A Unix domain socket is used to trigger this deterministically: file_exists() (used by
     * getLocalRefPath()) reports true for a socket node, so the ref resolves, but file_get_contents()
     * fails to open it as a stream. A directory was considered and rejected: file_get_contents() on a
     * directory returns "" (not false) on Linux, so it falls through to the "Invalid JSON-Schema file"
     * check instead of exercising the read-failure branch. A permission-denied file was also rejected:
     * tests commonly run as root, which bypasses Unix read permission checks entirely.
     *
     * Cross-platform: PHP's "unix://" transport has no Windows support, so stream_socket_server()
     * fails there and the test skips itself rather than asserting a platform it cannot exercise.
     * The trailing OS error text is not asserted verbatim either - the C library's ENXIO message
     * differs between Linux ("No such device or address") and BSD/macOS ("Device not configured"),
     * so only the PHP-authored "Failed to open stream:" prefix (produced by PHP's own streams code,
     * not the OS) is asserted precisely; the underlying reason is matched loosely.
     */
    public function testGetRefToUnreadableLocalFileThrowsSchemaException(): void
    {
        $refFilename = 'unreadable.sock';
        $socketPath = $this->schemaDir . '/' . $refFilename;

        $socket = @stream_socket_server('unix://' . $socketPath, $errno, $errstr);
        if ($socket === false) {
            $this->markTestSkipped("Unable to set up a Unix domain socket for this test: $errstr");
        }

        try {
            $provider = new RecursiveDirectoryProvider($this->schemaDir);
            // Use a normalised current-file path so dirname() produces a backslash-only path
            // and the candidate path remains resolvable on all platforms.
            $currentFile = realpath($this->schemaDir) . DIRECTORY_SEPARATOR . 'dummy.json';

            $this->expectException(SchemaException::class);
            $this->expectExceptionMessageMatches(
                '/^Failed to read referenced JSON-Schema file ' . preg_quote($refFilename, '/') . ' from .+'
                    . preg_quote($refFilename, '/') . ': file_get_contents\(.+' . preg_quote($refFilename, '/')
                    . '\): Failed to open stream: .+$/',
            );

            $provider->getRef($currentFile, null, $refFilename);
        } finally {
            fclose($socket);
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
