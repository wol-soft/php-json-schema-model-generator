<?php

declare(strict_types = 1);

namespace PHPModelGenerator;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;

/**
 * Class Generator
 *
 * @package PHPModelGenerator
 */
class Generator
{
    /** @var GeneratorConfiguration */
    protected $generatorConfiguration;

    /**
     * Generator constructor.
     *
     * @param GeneratorConfiguration $generatorConfiguration The configuration to apply to the generator
     */
    public function __construct(GeneratorConfiguration $generatorConfiguration = null)
    {
        $this->generatorConfiguration = $generatorConfiguration ?? new GeneratorConfiguration();
    }

    /**
     * Generate models from JSON-Schema files. Returns true on success.
     *
     * @param string                 $source                 The directory with the JSON-Schema files
     * @param string                 $destination            The directory where to put the generated PHP models
     *
     * @return bool
     *
     * @throws InvalidArgumentException Will be thrown if either the $source or the $destination directory doesn't exist
     *                                  or the $destination directory is not empty
     * @throws SchemaException          Will be thrown if a schema is invalid or can't be parsed
     * @throws FileSystemException      Will be thrown if a file system error occured
     */
    public function generateModels(string $source, string $destination): bool {
        if (!is_dir($source)) {
            throw new InvalidArgumentException("Source directory '$source' doesn't exist");
        }

        if (!is_dir($destination) || count(scandir($destination)) > 2) {
            throw new InvalidArgumentException("Destination directory '$destination' doesn't exist or is not empty");
        }

        $schemaProcessor = new SchemaProcessor($source, $destination, $this->generatorConfiguration);

        foreach ($this->getSchemaFiles($source) as $jsonSchemaFile) {
            $schemaProcessor->process($jsonSchemaFile);
        }

        return true;
    }

    /**
     * Get all json files from a given directory
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
