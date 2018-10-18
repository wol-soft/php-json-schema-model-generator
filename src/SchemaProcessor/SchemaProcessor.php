<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor;

use PHPMicroTemplate\Exception\PHPMicroTemplateException;
use PHPMicroTemplate\Render;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class SchemaProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor
 */
class SchemaProcessor
{
    /** @var GeneratorConfiguration */
    protected $generatorConfiguration;
    /** @var string */
    protected $source;
    /** @var string */
    protected $destination;

    /** @var string */
    protected $currentClassPath;
    /** @var string */
    protected $currentClassName;

    /**
     * SchemaProcessor constructor.
     *
     * @param string                 $source
     * @param string                 $destination
     * @param GeneratorConfiguration $generatorConfiguration
     */
    public function __construct(string $source, string $destination, GeneratorConfiguration $generatorConfiguration)
    {
        $this->source = $source;
        $this->destination = $destination;
        $this->generatorConfiguration = $generatorConfiguration;
    }

    /**
     * Process a given json schema file
     *
     * @param string $jsonSchemaFile
     *
     * @throws FileSystemException
     * @throws SchemaException
     * @throws RenderException
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
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function processSchema(array $jsonSchema, string $classPath, string $className): void
    {
        if (!isset($jsonSchema['type']) || $jsonSchema['type'] !== 'object') {
            throw new SchemaException("JSON-Schema doesn't provide an object " . $jsonSchema['id'] ?? '');
        }

        $this->generateModel($classPath, $className, $jsonSchema);
    }

    /**
     * Generate a model and store the model to the file system
     *
     * @param string $classPath
     * @param string $className
     * @param array  $structure
     *
     * @throws FileSystemException
     * @throws SchemaException
     * @throws RenderException
     */
    protected function generateModel(string $classPath, string $className, array $structure): void
    {
        $schema = new Schema();
        $schemaPropertyProcessorFactory = new SchemaPropertyProcessorFactory();

        foreach (array_keys($structure) as $schemaProperty) {
            $schemaPropertyProcessorFactory
                ->getSchemaPropertyProcessor($schemaProperty)
                ->process($this, $schema, $structure);
        }

        $this->generateModelDirectory($classPath);
        $class = $this->renderClass($classPath, $className, $schema);

        $fileName = join(
            DIRECTORY_SEPARATOR,
            [$this->destination, str_replace('\\', DIRECTORY_SEPARATOR, $classPath), $className]
        ) . '.php';

        if (!file_put_contents($fileName, $class)) {
            throw new FileSystemException("Can't write class $classPath\\$className");
        }

        if ($this->generatorConfiguration->isOutputEnabled()) {
            echo "Generated class $className\n";
        }
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
     * Generate the directory structure for saving a generated class
     *
     * @param string $classPath
     *
     * @throws FileSystemException
     */
    protected function generateModelDirectory(string $classPath): void
    {
        $subDirectoryPath = '';
        foreach (explode('\\', $classPath) as $directory) {
            $subDirectoryPath .= "/$directory";
            $fullPath = $this->destination . $subDirectoryPath;

            if (!is_dir($fullPath) && !mkdir($fullPath)) {
                throw new FileSystemException("Can't create path $fullPath");
            }
        }
    }

    /**
     * Render a class. Returns the php code of the class
     *
     * @param string $classPath The relative path of the class for namespace generation
     * @param string $className The class name
     * @param Schema $schema    The Schema object which holds properties and validators
     *
     * @return string
     *
     * @throws RenderException
     */
    protected function renderClass(string $classPath, string $className, Schema $schema): string {
        $render = new Render(__DIR__ . "/../Templates/");

        $namespace = trim($this->generatorConfiguration->getNamespacePrefix() . $classPath, '\\');
        $use = $schema->getUseList(empty($namespace));

        try {
            $class = $render->renderTemplate(
                'Model.phptpl',
                [
                    'namespace'              => empty($namespace) ? '' : "namespace $namespace;",
                    'use'                    => empty($use) ? '' : 'use ' . join(";\nuse ", array_unique($use)) . ';',
                    'class'                  => $className,
                    'baseValidators'         => $schema->getBaseValidators(),
                    'properties'             => $schema->getProperties(),
                    'generatorConfiguration' => $this->generatorConfiguration,
                    'viewHelper'             => new RenderHelper(),
                ]
            );
        } catch (PHPMicroTemplateException $exception) {
            throw new RenderException("Can't render class $classPath\\$className", 0, $exception);
        }

        return $class;
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
}
