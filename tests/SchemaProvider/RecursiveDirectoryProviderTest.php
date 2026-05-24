<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\SchemaProvider;

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
