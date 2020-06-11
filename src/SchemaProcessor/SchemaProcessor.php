<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\RenderJob;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\SchemaDefinitionDictionary;
use PHPModelGenerator\PropertyProcessor\PropertyMetaDataCollection;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;

/**
 * Class SchemaProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor
 */
class SchemaProcessor
{
    /** @var GeneratorConfiguration */
    protected $generatorConfiguration;
    /** @var RenderQueue */
    protected $renderQueue;
    /** @var string */
    protected $baseSource;
    /** @var string */
    protected $destination;

    /** @var string */
    protected $currentClassPath;
    /** @var string */
    protected $currentClassName;

    /** @var array Collect processed schemas to avoid duplicated classes */
    protected $processedSchema = [];
    /** @var array */
    protected $generatedFiles = [];

    /**
     * SchemaProcessor constructor.
     *
     * @param string                 $baseSource
     * @param string                 $destination
     * @param GeneratorConfiguration $generatorConfiguration
     * @param RenderQueue            $renderQueue
     */
    public function __construct(
        string $baseSource,
        string $destination,
        GeneratorConfiguration $generatorConfiguration,
        RenderQueue $renderQueue
    ) {
        $this->baseSource = $baseSource;
        $this->destination = $destination;
        $this->generatorConfiguration = $generatorConfiguration;
        $this->renderQueue = $renderQueue;
    }

    /**
     * Process a given json schema file
     *
     * @param array $jsonSchema
     * @param string $sourcePath
     *
     * @throws SchemaException
     */
    public function process(array $jsonSchema, string $sourcePath): void
    {
        $this->setCurrentClassPath($sourcePath);
        $this->currentClassName = $this->generatorConfiguration->getClassNameGenerator()->getClassName(
            str_ireplace('.json', '', basename($sourcePath)),
            $jsonSchema,
            false
        );

        $this->processSchema(
            $jsonSchema,
            $this->currentClassPath,
            $this->currentClassName,
            new SchemaDefinitionDictionary(dirname($sourcePath)),
            true
        );
    }

    /**
     * Process a JSON schema stored as an associative array
     *
     * @param array                      $jsonSchema
     * @param string                     $classPath
     * @param string                     $className
     * @param SchemaDefinitionDictionary $dictionary   If a nested object of a schema is processed import the
     *                                                 definitions of the parent schema to make them available for the
     *                                                 nested schema as well
     * @param bool                       $initialClass Is it an initial class or a nested class?
     *
     * @return Schema|null
     *
     * @throws SchemaException
     */
    public function processSchema(
        array $jsonSchema,
        string $classPath,
        string $className,
        SchemaDefinitionDictionary $dictionary,
        bool $initialClass = false
    ): ?Schema {
        if ((!isset($jsonSchema['type']) || $jsonSchema['type'] !== 'object') &&
            !array_intersect(array_keys($jsonSchema), ['anyOf', 'allOf', 'oneOf', 'if', '$ref'])
        ) {
            // skip the JSON schema as neither an object, a reference nor a composition is defined on the root level
            return null;
        }

        return $this->generateModel($classPath, $className, $jsonSchema, $dictionary, $initialClass);
    }

    /**
     * Generate a model and store the model to the file system
     *
     * @param string                     $classPath
     * @param string                     $className
     * @param array                      $structure
     * @param SchemaDefinitionDictionary $dictionary
     * @param bool                       $initialClass
     *
     * @return Schema
     *
     * @throws SchemaException
     */
    protected function generateModel(
        string $classPath,
        string $className,
        array $structure,
        SchemaDefinitionDictionary $dictionary,
        bool $initialClass
    ): Schema {
        $schemaSignature = md5(json_encode($structure));

        if (!$initialClass && isset($this->processedSchema[$schemaSignature])) {
            if ($this->generatorConfiguration->isOutputEnabled()) {
                echo "Duplicated signature $schemaSignature for class $className." .
                    " Redirecting to {$this->processedSchema[$schemaSignature]->getClassName()}\n";
            }

            return $this->processedSchema[$schemaSignature];
        }

        $schema = new Schema($classPath, $className, $dictionary);
        $this->processedSchema[$schemaSignature] = $schema;
        $structure['type'] = 'base';

        (new PropertyFactory(new PropertyProcessorFactory()))->create(
            new PropertyMetaDataCollection($structure['required'] ?? []),
            $this,
            $schema,
            $className,
            $structure
        );

        $this->generateClassFile($classPath, $className, $schema, $initialClass);

        return $schema;
    }

    /**
     * Attach a new class file render job to the render proxy
     *
     * @param string $classPath
     * @param string $className
     * @param Schema $schema
     * @param bool   $initialClass
     */
    public function generateClassFile(
        string $classPath,
        string $className,
        Schema $schema,
        bool $initialClass = false
    ): void {
        $fileName = join(
            DIRECTORY_SEPARATOR,
            [$this->destination, str_replace('\\', DIRECTORY_SEPARATOR, $classPath), $className]
        ) . '.php';

        $this->renderQueue->addRenderJob(new RenderJob($fileName, $classPath, $className, $schema, $initialClass));

        if ($this->generatorConfiguration->isOutputEnabled()) {
            echo "Generated class {$this->generatorConfiguration->getNamespacePrefix()}\\$classPath\\$className\n";
        }

        $this->generatedFiles[] = $fileName;
    }

    /**
     * Get the class path out of the file path of a schema file
     *
     * @param string $jsonSchemaFile
     */
    protected function setCurrentClassPath(string $jsonSchemaFile): void
    {
        $path = str_replace($this->baseSource, '', dirname($jsonSchemaFile));
        $pieces = array_map(
            function ($directory) {
                return ucfirst($directory);
            },
            explode(DIRECTORY_SEPARATOR, $path)
        );

        $this->currentClassPath = join('\\', array_filter($pieces));
    }

    /**
     * @return string
     */
    public function getCurrentClassPath(): string
    {
        return $this->currentClassPath;
    }

    /**
     * @return string
     */
    public function getCurrentClassName(): string
    {
        return $this->currentClassName;
    }

    /**
     * @return array
     */
    public function getGeneratedFiles(): array
    {
        return $this->generatedFiles;
    }

    /**
     * @return GeneratorConfiguration
     */
    public function getGeneratorConfiguration(): GeneratorConfiguration
    {
        return $this->generatorConfiguration;
    }
}
