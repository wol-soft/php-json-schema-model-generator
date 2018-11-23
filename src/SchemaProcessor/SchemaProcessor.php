<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\RenderJob;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
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
    protected $renderProxy;
    /** @var string */
    protected $source;
    /** @var string */
    protected $destination;

    /** @var string */
    protected $currentClassPath;
    /** @var string */
    protected $currentClassName;

    /** @var array */
    protected $generatedFiles = [];

    /**
     * SchemaProcessor constructor.
     *
     * @param string                 $source
     * @param string                 $destination
     * @param GeneratorConfiguration $generatorConfiguration
     * @param RenderQueue            $renderProxy
     */
    public function __construct(
        string $source,
        string $destination,
        GeneratorConfiguration $generatorConfiguration,
        RenderQueue $renderProxy
    ) {
        $this->source = $source;
        $this->destination = $destination;
        $this->generatorConfiguration = $generatorConfiguration;
        $this->renderProxy = $renderProxy;
    }

    /**
     * Process a given json schema file
     *
     * @param string $jsonSchemaFile
     *
     * @throws SchemaException
     */
    public function process(string $jsonSchemaFile): void
    {
        $jsonSchema = file_get_contents($jsonSchemaFile);

        if (!$jsonSchema || !($jsonSchema = json_decode($jsonSchema, true))) {
            throw new SchemaException("Invalid JSON-Schema file $jsonSchemaFile");
        }

        $this->setCurrentClassPath($jsonSchemaFile);
        $this->currentClassName = ucfirst($jsonSchema['id'] ?? str_ireplace('.json', '', basename($jsonSchemaFile)));

        $this->processSchema($jsonSchema, $this->currentClassPath, $this->currentClassName);
    }

    /**
     * Process a JSON schema stored as an associative array
     *
     * @param array  $jsonSchema
     * @param string $classPath
     * @param string $className
     * @param array  $parentDefinitions If a nested object of a schema is processed import the definitions of the parent
     *                                  schema to make them available for the nested schema as well
     *
     * @return Schema
     *
     * @throws SchemaException
     */
    public function processSchema(
        array $jsonSchema,
        string $classPath,
        string $className,
        array $parentDefinitions = []
    ): Schema {
        if (!isset($jsonSchema['type']) || $jsonSchema['type'] !== 'object') {
            throw new SchemaException("JSON-Schema doesn't provide an object " . $jsonSchema['id'] ?? '');
        }

        return $this->generateModel($classPath, $className, $jsonSchema, $parentDefinitions);
    }

    /**
     * Generate a model and store the model to the file system
     *
     * @param string $classPath
     * @param string $className
     * @param array  $structure
     * @param array  $parentDefinitions
     *
     * @return Schema
     */
    protected function generateModel(
        string $classPath,
        string $className,
        array $structure,
        array $parentDefinitions = []
    ): Schema {
        $schema = new Schema($parentDefinitions);
        //$schemaPropertyProcessorFactory = new SchemaPropertyProcessorFactory();

        $structure['type'] = 'base';

        (new PropertyFactory(new PropertyProcessorFactory()))->create(
            new PropertyCollectionProcessor($structure['required'] ?? []),
            $this,
            $schema,
            $className,
            $structure
        );
/*
        foreach (array_keys($structure) as $schemaProperty) {
            $schemaPropertyProcessorFactory
                ->getSchemaPropertyProcessor($schemaProperty)
                ->process($this, $schema, $structure);
        }
*/
        $fileName = join(
                DIRECTORY_SEPARATOR,
                [$this->destination, str_replace('\\', DIRECTORY_SEPARATOR, $classPath), $className]
            ) . '.php';

        $this->renderProxy->addRenderJob(new RenderJob($fileName, $classPath, $className, $schema));

        if ($this->generatorConfiguration->isOutputEnabled()) {
            // @codeCoverageIgnoreStart
            echo "Generated class $className\n";
            // @codeCoverageIgnoreEno
        }

        $this->generatedFiles[] = $fileName;

        return $schema;
    }

    /**
     * Get the class path out of the file path of a schema file
     *
     * @param string $jsonSchemaFile
     */
    protected function setCurrentClassPath(string $jsonSchemaFile): void
    {
        $path = str_replace($this->source, '', dirname($jsonSchemaFile));
        $pieces = array_map(
            function ($directory) {
                return ucfirst($directory);
            },
            explode(DIRECTORY_SEPARATOR, $path)
        );

        $this->currentClassPath = join('\\', $pieces);
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
}
