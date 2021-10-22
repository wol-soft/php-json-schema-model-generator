<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProvider;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * Class RecursiveDirectoryProvider
 *
 * @package PHPModelGenerator\SchemaProvider
 */
class RecursiveDirectoryProvider implements SchemaProviderInterface
{
    /** @var string */
    private $sourceDirectory;

    /**
     * RecursiveDirectoryProvider constructor.
     *
     * @param string $sourceDirectory
     *
     * @throws FileSystemException
     */
    public function __construct(string $sourceDirectory)
    {
        if (!is_dir($sourceDirectory)) {
            throw new FileSystemException("Source directory '$sourceDirectory' doesn't exist");
        }

        $this->sourceDirectory = rtrim($sourceDirectory, "\\/");
    }

    /**
     * @inheritDoc
     *
     * @throws SchemaException
     */
    public function getSchemas(): iterable
    {
        $directory = new RecursiveDirectoryIterator($this->sourceDirectory);
        $iterator = new RecursiveIteratorIterator($directory);
        $schemaFiles = [];

        foreach (new RegexIterator($iterator, '/^.+\.json$/i', RegexIterator::GET_MATCH) as $file) {
            $schemaFiles[] = $file[0];
        }

        sort($schemaFiles, SORT_REGULAR);
        foreach ($schemaFiles as $file) {
            $jsonSchema = file_get_contents($file);

            if (!$jsonSchema || !($decodedJsonSchema = json_decode($jsonSchema, true))) {
                throw new SchemaException("Invalid JSON-Schema file $file");
            }

            yield new JsonSchema($file, $decodedJsonSchema);
        }
    }

    /**
     * @inheritDoc
     */
    public function getBaseDirectory(): string
    {
        return $this->sourceDirectory;
    }
}
