<?php

namespace PHPModelGenerator\Tests\ComposedValue;

use DirectoryIterator;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

/**
 * Class JsonSchemaTestSuiteTest
 *
 * @package PHPModelGenerator\Tests\ComposedValue
 */
class JsonSchemaTestSuiteTest extends AbstractPHPModelGeneratorTest
{
    /**
     * @dataProvider draftDataProvider
     *
     * @param string $schema
     * @param bool $valid
     * @param array $data
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testJsonSchemaTestSuite(string $schema, bool $valid, array $data): void
    {
        $className = $this->generateClassFromFileTemplate('ObjectTemplate.json', [$schema], null, false, false);

        if (!$valid) {
            $this->expectException(ValidationException::class);
        } else {
            $this->expectNotToPerformAssertions();
        }

        new $className($data);

        $this->fail();
    }

    public function draftDataProvider(): array
    {
        $drafts = [
            'draft3',
            'draft3/optional',
            'draft4',
            'draft4/optional',
            'draft6',
            'draft6/optional',
            'draft7',
            'draft7/optional',
        ];

        $tests = [];

        foreach ($drafts as $draft) {
            foreach ($this->getFilesForDraft($draft) as $key => $path) {
                foreach ($this->setUpDataProviderFromSchemaTestSuiteFile($path) as $testSuiteKey => $test) {
                    $tests["Draft suite[$draft] $key - $testSuiteKey"] = $test;
                }
            }
        }

        return $tests;
    }

    public function getFilesForDraft(string $draft): array
    {
        $files = [];

        foreach (
            new DirectoryIterator(__DIR__ . '/../../vendor/json-schema/json-schema-test-suite/tests/' . $draft)
            as
            $item
        ) {
            if ($item->isFile()) {
                $files[$item->getBasename()] = $item->getRealPath();
            }
        }

        return $files;
    }

    public function setUpDataProviderFromSchemaTestSuiteFile(string $file): array
    {
        $testFile = json_decode(file_get_contents($file), true);

        $tests = [];

        foreach ($testFile as $test) {
            foreach ($test['tests'] as $dataProvider) {
                $tests["{$test['description']}: {$dataProvider['description']}"] = [
                    'schema' => json_encode($test['schema']),
                    'valid' => $dataProvider['valid'],
                    'data' => ['property' => $dataProvider['data']],
                ];
            }
        }

        return $tests;
    }
}
