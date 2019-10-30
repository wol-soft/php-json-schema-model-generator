<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model;

use PHPMicroTemplate\Exception\PHPMicroTemplateException;
use PHPMicroTemplate\Render;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Utils\RenderHelper;

class RenderJob
{
    /** @var Schema */
    protected $schema;
    /** @var string */
    protected $className;
    /** @var string */
    protected $classPath;
    /** @var string */
    protected $fileName;
    /** @var bool */
    protected $initialClass;

    /**
     * Create a new class render job
     *
     * @param string $fileName     The file name
     * @param string $classPath    The relative path of the class for namespace generation
     * @param string $className    The class name
     * @param Schema $schema       The Schema object which holds properties and validators
     * @param bool   $initialClass Render job for an initial class or render job for a nested class?
     */
    public function __construct(
        string $fileName,
        string $classPath,
        string $className,
        Schema $schema,
        bool $initialClass
    ) {
        $this->fileName = $fileName;
        $this->classPath = $classPath;
        $this->className = $className;
        $this->schema = $schema;
        $this->initialClass = $initialClass;
    }

    /**
     * Execute the render job and render the class
     *
     * @param string                 $destination
     * @param GeneratorConfiguration $generatorConfiguration
     *
     * @throws FileSystemException
     * @throws RenderException
     */
    public function render(string $destination, GeneratorConfiguration $generatorConfiguration): void
    {
        $this->generateModelDirectory($destination, $this->classPath);

        $class = $this->renderClass($generatorConfiguration);

        if (file_exists($this->fileName)) {
            throw new FileSystemException("File {$this->fileName} already exists. Make sure object IDs are unique.");
        }

        if (!file_put_contents($this->fileName, $class)) {
            // @codeCoverageIgnoreStart
            throw new FileSystemException("Can't write class $this->classPath\\$this->className.");
            // @codeCoverageIgnoreEnd
        }

        if ($generatorConfiguration->isOutputEnabled()) {
            echo "Rendered class {$generatorConfiguration->getNamespacePrefix()}$this->classPath\\$this->className\n";
        }
    }

    /**
     * Generate the directory structure for saving a generated class
     *
     * @param string $destination
     * @param string $classPath
     *
     * @throws FileSystemException
     */
    protected function generateModelDirectory(string $destination, string $classPath): void
    {
        $subDirectoryPath = '';
        foreach (explode('\\', $classPath) as $directory) {
            $subDirectoryPath .= "/$directory";
            $fullPath = $destination . $subDirectoryPath;

            if (!is_dir($fullPath) && !mkdir($fullPath)) {
                throw new FileSystemException("Can't create path $fullPath");
            }
        }
    }

    /**
     * Render a class. Returns the php code of the class
     *
     * @param GeneratorConfiguration $generatorConfiguration
     *
     * @return string
     * @throws RenderException
     */
    protected function renderClass(GeneratorConfiguration $generatorConfiguration): string
    {
        $getFullNamespace = function (string $classPath) use ($generatorConfiguration): string {
            return trim($generatorConfiguration->getNamespacePrefix() . $classPath, '\\');
        };

        $render = new Render(__DIR__ . "/../Templates/");
        $namespace = $getFullNamespace($this->classPath);

        $use = array_merge(
            array_map($getFullNamespace, $this->schema->getUsedClasses()),
            $generatorConfiguration->collectErrors()
                ? [$generatorConfiguration->getErrorRegistryClass()]
                : [$generatorConfiguration->getExceptionClass()]
        );

        // filter out non-compound namespaces
        $use = array_filter($use, function ($classPath) {
            return strstr($classPath, '\\');
        });

        try {
            $class = $render->renderTemplate(
                'Model.phptpl',
                [
                    'namespace'              => $namespace,
                    'use'                    => empty($use) ? '' : 'use ' . join(";\nuse ", array_unique($use)) . ';',
                    'class'                  => $this->className,
                    'baseValidators'         => $this->schema->getBaseValidators(),
                    'properties'             => $this->schema->getProperties(),
                    'generatorConfiguration' => $generatorConfiguration,
                    'viewHelper'             => new RenderHelper($generatorConfiguration),
                    'initialClass'           => $this->initialClass,
                ]
            );
        } catch (PHPMicroTemplateException $exception) {
            throw new RenderException("Can't render class $this->classPath\\$this->className", 0, $exception);
        }

        return $class;
    }
}