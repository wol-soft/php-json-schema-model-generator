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

        $classPath = $this->getClassPath($jsonSchemaFile);
        $className = ucfirst($jsonSchema['id'] ?? str_ireplace('.json', '', basename($jsonSchemaFile)));

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

        echo "Generated class $className\n";
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
                ->getPropertyProcessor($property['type'], $propertyCollectionProcessor)
                ->process($propertyName, $property);
        }

        return $properties;
    }

    /**
     * Get the class path out of the file path of a schema file
     *
     * @param string $jsonSchemaFile
     *
     * @return string
     */
    protected function getClassPath(string $jsonSchemaFile): string
    {
        $path = str_replace($this->source, '', dirname($jsonSchemaFile));
        $pieces = array_map(
            function ($directory) {
                return ucfirst($directory);
            },
            explode(DIRECTORY_SEPARATOR, $path)
        );

        return join('\\', $pieces);
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
        $use = $this->getUseList($properties);

        try {
            $class = $render->renderTemplate(
                'Model.phptpl',
                [
                    'namespace'              => empty($namespace) ? '' : "namespace $namespace;",
                    'use'                    => empty($use) ? '' : 'use ' . join(";\nuse ", array_unique($use)) . ';',
                    'class'                  => $className,
                    'properties'             => $properties,
                    'generatorConfiguration' => $this->generatorConfiguration,
                    'viewHelper'             => new class () {
                        public function ucfirst(string $value): string
                        {
                            return ucfirst($value);
                        }
                    }
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
     *
     * @return array
     */
    protected function getUseList(array $properties): array
    {
        $use = [];

        foreach ($properties as $property) {
            if (!empty($property->getValidators())) {
                $use[] = Exception::class;

                foreach ($property->getValidators() as $validator) {
                    $use[] = $validator->getExceptionClass();
                }
            }
        }

        return $use;
    }
}
