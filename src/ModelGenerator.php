<?php

declare(strict_types = 1);

namespace PHPModelGenerator;

use FilesystemIterator;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\SchemaProcessor\RenderQueue;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;

/**
 * Class ModelGenerator
 *
 * @package PHPModelGenerator
 */
class ModelGenerator
{
    /** @var GeneratorConfiguration */
    protected $generatorConfiguration;

    /**
     * Generator constructor.
     *
     * @param GeneratorConfiguration|null $generatorConfiguration The configuration to apply to the generator
     */
    public function __construct(GeneratorConfiguration $generatorConfiguration = null)
    {
        $this->generatorConfiguration = $generatorConfiguration ?? new GeneratorConfiguration();
    }

    /**
     * Create an directory to store the models in. If the directory already exists and contains files all files will be
     * removed to provide an empty directory for model generation.
     *
     * @param string $modelPath     The absolute path to the directory
     * @param int    $directoryMode The mode to create the directory with
     *
     * @return ModelGenerator
     */
    public function generateModelDirectory(string $modelPath, int $directoryMode = 0777): self
    {
        if (!is_dir($modelPath)) {
            mkdir($modelPath, $directoryMode, true);
        }

        $di = new RecursiveDirectoryIterator($modelPath, FilesystemIterator::SKIP_DOTS);
        $ri = new RecursiveIteratorIterator($di, RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($ri as $file) {
            $file->isDir() ?  rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }

        return $this;
    }

    /**
     * Generate models from JSON-Schema files. Returns an array of generated file names on success.
     *
     * @param string $source      The directory with the JSON-Schema files
     * @param string $destination The directory where to put the generated PHP models
     *
     * @return array
     *
     * @throws FileSystemException      Will be thrown if either the $source or the $destination directory doesn't exist
     *                                  or the $destination directory is not empty
     * @throws SchemaException          Will be thrown if a schema is invalid or can't be parsed
     * @throws FileSystemException      Will be thrown if a file system error occured
     * @throws RenderException          Will be thrown if a class can't be rendered correctly
     */
    public function generateModels(string $source, string $destination): array
    {
        if (!is_dir($source)) {
            throw new FileSystemException("Source directory '$source' doesn't exist");
        }

        if (!is_dir($destination) || count(scandir($destination)) > 2) {
            throw new FileSystemException("Destination directory '$destination' doesn't exist or is not empty");
        }

        $renderProxy = new RenderQueue();
        $schemaProcessor = new SchemaProcessor($source, $destination, $this->generatorConfiguration, $renderProxy);

        foreach ($this->getSchemaFiles($source) as $jsonSchemaFile) {
            $schemaProcessor->process($jsonSchemaFile);
        }

        // render all collected classes
        $renderProxy->execute($destination, $this->generatorConfiguration);

        if ($this->generatorConfiguration->hasPrettyPrintEnabled()) {
            // @codeCoverageIgnoreStart
            $out = $this->generatorConfiguration->isOutputEnabled() ? '' : '2>&1';
            shell_exec(__DIR__ . "/../vendor/bin/ecs check $destination --config " . __DIR__ . "/cs.yml --fix $out");
            // @codeCoverageIgnoreEnd
        }

        return $schemaProcessor->getGeneratedFiles();
    }

    /**
     * Get all json files from a given directory
     *
     * @param string $directory
     *
     * @return array
     */
    protected function getSchemaFiles(string $directory) : array
    {
        $directory = new RecursiveDirectoryIterator($directory);
        $iterator = new RecursiveIteratorIterator($directory);
        $files = [];

        foreach (new RegexIterator($iterator, '/^.+\.json$/i', RecursiveRegexIterator::GET_MATCH) as $file) {
            $files[] = $file[0];
        }

        return $files;
    }
}
