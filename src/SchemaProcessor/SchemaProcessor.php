<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor;

use Exception;
use PHPMicroTemplate\Render;
use PHPModelGenerator\Exception\FileSystemException;
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
     * @param string $jsonSchemaFile
     *
     * @throws FileSystemException
     * @throws SchemaException
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
     * @param string $classPath
     * @param string $className
     * @param array  $structure
     *
     * @throws FileSystemException
     * @throws SchemaException
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
     * @param array $structure
     *
     * @return array
     * @throws SchemaException
     */
    protected function getModelProperties(array $structure):array
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
     * @param string $classPath
     * @param string $className
     * @param array  $properties
     *
     * @return string
     * @throws FileSystemException
     */
    protected function renderClass(string $classPath, string $className, array $properties): string
    {
        $modelAttributes = '';
        $modelMethods    = '';
        $assignments     = '';
        $use             = [];

        $render = new Render(__DIR__ . "/../Templates/");

        /** @var Property $property */
        foreach ($properties as $property) {
            $modelAttributes .=
                "    /** @var {$property->getType()} */\n" .
                "    protected \${$property->getAttribute()};\n";

            $assignments .= "        \$this->{$property->getAttribute()} = \$modelData['{$property->getName()}'];\n";

            $templates = ['Getter.phptpl'];
            if (!$this->generatorConfiguration->isImmutable()) {
                $templates[] = 'Setter.phptpl';
            }

            foreach ($templates as $template) {
                $modelMethods .= $render->renderTemplate(
                    $template,
                    [
                        'className'     => $className,
                        'type'          => $property->getType(),
                        'functionName'  => ucfirst($property->getAttribute()),
                        'attributeName' => $property->getAttribute()
                    ]
                );
            }

            if (!empty($property->getValidators())) {
                $validatorImplementation = '';
                $use[] = Exception::class;

                foreach ($property->getValidators() as $validator) {
                    $validatorImplementation .=
                        "        if ({$validator->getCheck()}) {\n" .
                        "            throw new {$validator->getExceptionClass()}('{$validator->getExceptionMessage()}');\n" .
                        "        }\n";

                    $use[] = $validator->getExceptionClass();
                }

                $validationMethod = "validate" . ucfirst($property->getAttribute());
                $assignments .= "        \$this->$validationMethod(\$this->{$property->getAttribute()});\n\n";

                $modelMethods .= $render->renderTemplate(
                    'Validator.phptpl',
                    [
                        'type'                    => $property->getType(),
                        'functionName'            => $validationMethod,
                        'validatorImplementation' => $validatorImplementation
                    ]
                );
            }
        }

        $namespace = trim($this->generatorConfiguration->getNamespacePrefix() . $classPath, '\\');

        return $render->renderTemplate(
            'Model.phptpl',
            [
                'namespace'           => empty($namespace) ? '' : "namespace $namespace;",
                'use'                 => empty($use) ? '' : 'use ' . join(";\nuse ", array_unique($use)) . ';',
                'class'               => $className,
                'properties'          => $modelAttributes,
                'property-assignment' => $assignments,
                'getter'              => $modelMethods
            ]
        );
    }
}
