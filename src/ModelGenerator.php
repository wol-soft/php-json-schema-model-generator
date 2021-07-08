<?php

declare(strict_types = 1);

namespace PHPModelGenerator;

use FilesystemIterator;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\SchemaProcessor\PostProcessor\Internal\ {
    AdditionalPropertiesPostProcessor,
    CompositionValidationPostProcessor,
    ExtendObjectPropertiesMatchingPatternPropertiesPostProcessor,
    PatternPropertiesPostProcessor,
    SerializationPostProcessor
};
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\SchemaProcessor\RenderQueue;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\SchemaProvider\SchemaProviderInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Class ModelGenerator
 *
 * @package PHPModelGenerator
 */
class ModelGenerator
{
    /** @var GeneratorConfiguration */
    protected $generatorConfiguration;
    /** @var PostProcessor[] */
    protected $postProcessors = [];

    /**
     * Generator constructor.
     *
     * @param GeneratorConfiguration|null $generatorConfiguration The configuration to apply to the generator
     */
    public function __construct(GeneratorConfiguration $generatorConfiguration = null)
    {
        $this->generatorConfiguration = $generatorConfiguration ?? new GeneratorConfiguration();

        // add internal post processors which must always be executed
        $this
            ->addPostProcessor(new CompositionValidationPostProcessor())
            ->addPostProcessor(new AdditionalPropertiesPostProcessor())
            ->addPostProcessor(new PatternPropertiesPostProcessor())
            ->addPostProcessor(new ExtendObjectPropertiesMatchingPatternPropertiesPostProcessor());

        if ($this->generatorConfiguration->hasSerializationEnabled()) {
            $this->addPostProcessor(new SerializationPostProcessor());
        }
    }

    /**
     * @param PostProcessor $postProcessor
     *
     * @return $this
     */
    public function addPostProcessor(PostProcessor $postProcessor): self
    {
        $this->postProcessors[] = $postProcessor;

        return $this;
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
     * @param SchemaProviderInterface $schemaProvider The provider used to fetch the JSON schemas to process
     * @param string $destination                     The directory where to put the generated PHP models
     *
     * @return array
     *
     * @throws FileSystemException      Will be thrown if either the $source or the $destination directory doesn't exist
     *                                  or the $destination directory is not empty
     * @throws SchemaException          Will be thrown if a schema is invalid or can't be parsed
     * @throws FileSystemException      Will be thrown if a file system error occurred
     * @throws RenderException          Will be thrown if a class can't be rendered correctly
     */
    public function generateModels(SchemaProviderInterface $schemaProvider, string $destination): array
    {
        if (!is_dir($destination) || count(scandir($destination)) > 2) {
            throw new FileSystemException("Destination directory '$destination' doesn't exist or is not empty");
        }

        $renderQueue = new RenderQueue();
        $schemaProcessor = new SchemaProcessor(
            $schemaProvider->getBaseDirectory(),
            $destination,
            $this->generatorConfiguration,
            $renderQueue
        );

        foreach ($schemaProvider->getSchemas() as $jsonSchema) {
            $schemaProcessor->process($jsonSchema);
        }

        // render all collected classes
        $renderQueue->execute($this->generatorConfiguration, $this->postProcessors);

        return $schemaProcessor->getGeneratedFiles();
    }
}
