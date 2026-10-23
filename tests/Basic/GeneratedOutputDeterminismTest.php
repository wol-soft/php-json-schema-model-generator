<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use FilesystemIterator;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProvider\RecursiveDirectoryProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Generated code is committed by users, so regenerating an unchanged schema must reproduce it
 * byte for byte - regardless of which other schemas are generated in the same run. Extracted
 * method names (the `_validate*_*` and `_getModifiedValues_*` families) are the generator's only
 * source of per-validator disambiguation and must therefore not depend on object identity or on
 * the processing order of unrelated schema files.
 */
class GeneratedOutputDeterminismTest extends TestCase
{
    private string $workDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'PHPModelGeneratorDeterminism_' . uniqid();
        mkdir($this->workDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->workDirectory);

        parent::tearDown();
    }

    /**
     * @param string[] $schemaFiles fixture file names to copy into the run's input directory
     *
     * @return array<string, string> generated file contents keyed by file name
     */
    private function generate(string $runName, array $schemaFiles): array
    {
        $inputDirectory = $this->workDirectory . DIRECTORY_SEPARATOR . $runName . '_in';
        $outputDirectory = $this->workDirectory . DIRECTORY_SEPARATOR . $runName . '_out';
        mkdir($inputDirectory);
        mkdir($outputDirectory);

        foreach ($schemaFiles as $schemaFile) {
            copy(
                __DIR__ . '/../Schema/GeneratedOutputDeterminismTest/' . $schemaFile,
                $inputDirectory . DIRECTORY_SEPARATOR . $schemaFile,
            );
        }

        // The generator loads every class it renders, so each run needs a namespace of its own;
        // the prefix is normalised away before the runs are compared.
        $namespacePrefix = 'Determinism' . ucfirst(str_replace('_', '', $runName));

        $generatedFiles = (new ModelGenerator(
            (new GeneratorConfiguration())
                ->setNamespacePrefix($namespacePrefix)
                ->setLogger(new NullLogger()),
        ))->generateModels(new RecursiveDirectoryProvider($inputDirectory), $outputDirectory);

        $contents = [];
        foreach ($generatedFiles as $generatedFile) {
            $contents[basename($generatedFile)] = str_replace(
                $namespacePrefix,
                'Determinism',
                file_get_contents($generatedFile),
            );
        }
        ksort($contents);

        return $contents;
    }

    /**
     * Two separate generation runs over the same schema set produce identical files.
     */
    public function testRegeneratingTheSameSchemasIsByteIdentical(): void
    {
        $firstRun = $this->generate('first', ['Primary.json', 'AAAUnrelated.json']);
        $secondRun = $this->generate('second', ['Primary.json', 'AAAUnrelated.json']);

        $this->assertNotEmpty($firstRun);
        $this->assertSame($firstRun, $secondRun);
    }

    /**
     * Adding an unrelated schema file - processed before the primary one - allocates objects and
     * shifts every object identity that follows. The primary schema's generated classes must not
     * notice: a name derived from object identity would rename its extracted methods and show up
     * as a spurious diff in users' committed code.
     */
    public function testAddingAnUnrelatedSchemaDoesNotChangeOtherGeneratedClasses(): void
    {
        $alone = $this->generate('alone', ['Primary.json']);
        $withUnrelated = $this->generate('with_unrelated', ['AAAUnrelated.json', 'Primary.json']);

        $this->assertNotEmpty($alone);

        foreach ($alone as $fileName => $contents) {
            $this->assertArrayHasKey($fileName, $withUnrelated);
            $this->assertSame($contents, $withUnrelated[$fileName], "Generated file $fileName changed");
        }
    }

    /**
     * Method names are reserved per generated class: the first request for a name keeps it
     * unchanged, later requests for the same name get a numeric suffix, and reservations in one
     * class never influence another.
     */
    public function testMethodNameReservationIsScopedToTheSchemaAndOrderedByRequest(): void
    {
        $firstSchema = $this->createSchema();
        $secondSchema = $this->createSchema();

        $this->assertSame(
            '_validateTags_ArrayItem_abc',
            $firstSchema->reserveMethodName('_validateTags_ArrayItem_abc'),
        );
        $this->assertSame(
            '_validateTags_ArrayItem_abc_2',
            $firstSchema->reserveMethodName('_validateTags_ArrayItem_abc'),
        );
        $this->assertSame(
            '_validateTags_ArrayItem_abc_3',
            $firstSchema->reserveMethodName('_validateTags_ArrayItem_abc'),
        );
        $this->assertSame(
            '_validateTags_ArrayItem_other',
            $firstSchema->reserveMethodName('_validateTags_ArrayItem_other'),
        );

        $this->assertSame(
            '_validateTags_ArrayItem_abc',
            $secondSchema->reserveMethodName('_validateTags_ArrayItem_abc'),
        );
    }

    private function createSchema(): Schema
    {
        return new Schema(
            $this->workDirectory . DIRECTORY_SEPARATOR . 'SchemaClassName.php',
            'SchemaClassNamespace',
            'SchemaClassName',
            new JsonSchema('schema.json', []),
        );
    }
}
