<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\SchemaProvider;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProvider\RecursiveDirectoryProvider;
use PHPUnit\Framework\TestCase;

class RecursiveDirectoryProviderTest extends TestCase
{
    private string $schemaDir;
    private string $outputDir;

    public function setUp(): void
    {
        $this->schemaDir = sys_get_temp_dir() . '/PHPModelGeneratorTest/RecursiveDirectoryProviderTest/schemas';
        $this->outputDir = sys_get_temp_dir() . '/PHPModelGeneratorTest/RecursiveDirectoryProviderTest/output';

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
            (new GeneratorConfiguration())->setOutputEnabled(false),
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
            (new GeneratorConfiguration())->setOutputEnabled(false),
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
