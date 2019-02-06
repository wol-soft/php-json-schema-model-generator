<?php

namespace PHPModelGenerator\Tests;

use FilesystemIterator;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Generator;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Class AbstractPHPModelGeneratorTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
abstract class AbstractPHPModelGeneratorTest extends TestCase
{
    private $names = [];

    private $generatedFiles = [];

    public static function setUpBeforeClass(): void
    {
        if (is_dir(sys_get_temp_dir() . '/PHPModelGeneratorTest')) {
            $di = new RecursiveDirectoryIterator(sys_get_temp_dir() . '/PHPModelGeneratorTest', FilesystemIterator::SKIP_DOTS);
            $ri = new RecursiveIteratorIterator($di, RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($ri as $file) {
                $file->isDir() ? rmdir($file) : unlink($file);
            }
        }

        @mkdir(sys_get_temp_dir() . '/PHPModelGeneratorTest');
        @mkdir(sys_get_temp_dir() . '/PHPModelGeneratorTest/Models');
    }

    public function tearDown(): void
    {
        parent::tearDown();

        if ($this->hasFailed()) {
            $failedResultDir = FAILED_CLASSES_PATH . preg_replace( '/[^a-z0-9]+/i', '-', $this->getName());

            @mkdir($failedResultDir, 0777, true);
            foreach ($this->names as $name) {
                copy(
                    sys_get_temp_dir() . '/PHPModelGeneratorTest/' . $name . '.json',
                    $failedResultDir . DIRECTORY_SEPARATOR . $name . '.json'
                );
            }

            foreach ($this->generatedFiles as $file) {
                copy(
                    $file,
                    $failedResultDir . DIRECTORY_SEPARATOR . basename($file)
                );
            }
        }

        // clear the JSON schema definitions
        foreach ($this->names as $name) {
            @unlink(sys_get_temp_dir() . '/PHPModelGeneratorTest/' . $name . '.json');
        }

        // clear the generated class files
        foreach ($this->generatedFiles as $file) {
            @unlink($file);
        }

        $this->names = [];
        $this->generatedFiles = [];
    }

    /**
     * Generate an object from a given JSON schema file and return the FQCN
     *
     * @param string                      $file
     * @param GeneratorConfiguration|null $generatorConfiguration
     *
     * @return string
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function generateObjectFromFile(string $file, GeneratorConfiguration $generatorConfiguration = null): string
    {
        return $this->generateObject(
            file_get_contents(__DIR__ . '/Schema/' . $this->getStaticClassName() . '/' . $file),
            $generatorConfiguration
        );
    }

    /**
     * Generate an object from a file template and apply all $values via sprintf to the template
     *
     * @param string                      $file
     * @param array                       $values
     * @param GeneratorConfiguration|null $generatorConfiguration
     * @param bool                        $escape
     *
     * @return string
     */
    public function generateObjectFromFileTemplate(
        string $file,
        array $values,
        GeneratorConfiguration $generatorConfiguration = null,
        bool $escape = true
    ): string {
        return $this->generateObject(
            call_user_func_array(
                'sprintf',
                array_merge(
                    [file_get_contents(__DIR__ . '/Schema/' . $this->getStaticClassName() . '/' . $file)],
                    array_map(
                        function ($item) use ($escape) {
                            return $escape ? str_replace("'", '"', addcslashes($item, '"\\')) : $item;
                        },
                        $values
                    )
                )
            ),
            $generatorConfiguration
        );
    }

    /**
     * Generate an object from a given JSON schema string and return the FQCN
     *
     * @param string                 $jsonSchema
     * @param GeneratorConfiguration $generatorConfiguration
     *
     * @return string
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function generateObject(string $jsonSchema, GeneratorConfiguration $generatorConfiguration = null): string
    {
        $generatorConfiguration = $generatorConfiguration ?? new GeneratorConfiguration();
        $generatorConfiguration
            ->setPrettyPrint(false)
            ->setOutputEnabled(false);

        $baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'PHPModelGeneratorTest';
        foreach ($this->names as $name) {
            unlink($baseDir . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . $name . '.php');
        }

        $className = $this->getClassName();

        // generate an object ID for valid JSON schema files to avoid class name collisions in the testing process
        $jsonSchemaArray = json_decode($jsonSchema, true);
        if ($jsonSchemaArray) {
            $jsonSchemaArray['id'] = $className;
            $jsonSchema = json_encode($jsonSchemaArray);
        }

        file_put_contents($baseDir . DIRECTORY_SEPARATOR . $className . '.json', $jsonSchema);

        $generatedFiles = (new Generator($generatorConfiguration))->generateModels(
            $baseDir,
            $baseDir . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR
        );

        foreach ($generatedFiles as $path) {
            $this->generatedFiles[] = $path;

            require_once $path;
        }

        return $className;
    }

    /**
     * Generate a unique name for a class
     *
     * @return string
     */
    private function getClassName()
    {
        // include the static class name to avoid collisions from loaded classes from multiple tests
        $name = $this->getStaticClassName() . '_' . uniqid();

        while (in_array($name, $this->names)) {
            $name .= '1';
        }

        $this->names[] = $name;

        return $name;
    }

    private function getStaticClassName()
    {
        $parts = explode('\\', static::class);

        return end($parts);
    }
}
