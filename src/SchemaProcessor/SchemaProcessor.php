<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor;

use Exception;
use PHPMicroTemplate\Exception\PHPMicroTemplateException;
use PHPMicroTemplate\Render;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
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
        $this->generateModelDirectory($classPath);
        $class = $this->renderClass($classPath, $className, $this->getModelProperties($structure));

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
     * Get the properties of a model out of the json schema
     *
     * @param array $structure
     *
     * @return Property[]
     *
     * @throws SchemaException
     */
    protected function getModelProperties(array $structure): array
    {
        $properties = [];
        $propertyProcessorFactory = new PropertyProcessorFactory();

        $propertyCollectionProcessor = (new PropertyCollectionProcessor())
            ->setRequiredAttributes($structure['required'] ?? []);

        foreach ($structure['properties'] as $propertyName => $property) {
            $properties[] = $propertyProcessorFactory
                ->getPropertyProcessor($property['type'] ?? 'any', $propertyCollectionProcessor, $this)
                ->process($propertyName, $property);
        }

        return $properties;
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
     * @param string     $classPath  The relative path of the class for namespace generation
     * @param string     $className  The class name
     * @param Property[] $properties The properties which are part of the class
     *
     * @return string
     *
     * @throws RenderException
     */
    protected function renderClass(string $classPath, string $className, array $properties): string
    {
        $render = new Render(__DIR__ . "/../Templates/");

        $namespace = trim($this->generatorConfiguration->getNamespacePrefix() . $classPath, '\\');
        $use = $this->getUseList($properties, empty($namespace));

        try {
            $class = $render->renderTemplate(
                'Model.phptpl',
                [
                    'namespace'              => empty($namespace) ? '' : "namespace $namespace;",
                    'use'                    => empty($use) ? '' : 'use ' . join(";\nuse ", array_unique($use)) . ';',
                    'class'                  => $className,
                    'properties'             => $properties,
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
     * Extract all required uses for a given list of properties
     *
     * @param Property[] $properties
     * @param bool       $skipGlobalNamespace
     *
     * @return array
     */
    protected function getUseList(array $properties, bool $skipGlobalNamespace): array
    {
        $use = [];

        foreach ($properties as $property) {
            if (empty($property->getValidators())) {
                continue;
            }

            $use = array_merge($use, [Exception::class], $property->getClasses());
        }

        if ($skipGlobalNamespace) {
            $use = array_filter($use, function ($namespace) {
                return strstr($namespace, '\\');
            });
        }

        return $use;
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
