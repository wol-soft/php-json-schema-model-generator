<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model;

use PHPMicroTemplate\Exception\PHPMicroTemplateException;
use PHPMicroTemplate\Render;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\SchemaProcessor\Hook\SchemaHookResolver;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessorInterface;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class RenderJob
 *
 * @package PHPModelGenerator\Model
 */
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

    /**
     * Create a new class render job
     *
     * @param string $fileName  The file name
     * @param string $classPath The relative path of the class for namespace generation
     * @param string $className The class name
     * @param Schema $schema    The Schema object which holds properties and validators
     */
    public function __construct(
        string $fileName,
        string $classPath,
        string $className,
        Schema $schema
    ) {
        $this->fileName = $fileName;
        $this->classPath = $classPath;
        $this->className = $className;
        $this->schema = $schema;
    }

    /**
     * @param PostProcessorInterface[] $postProcessors
     * @param GeneratorConfiguration $generatorConfiguration
     */
    public function postProcess(array $postProcessors, GeneratorConfiguration $generatorConfiguration)
    {
        foreach ($postProcessors as $postProcessor) {
            $postProcessor->process($this->schema, $generatorConfiguration);
        }
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
            echo "Rendered class {$generatorConfiguration->getNamespacePrefix()}\\$this->classPath\\$this->className\n";
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
        $render = new Render(__DIR__ . '/../Templates/');
        $namespace = trim(join('\\', [$generatorConfiguration->getNamespacePrefix(), $this->classPath]), '\\');

        $use = array_unique(
            array_merge(
                $this->schema->getUsedClasses(),
                $generatorConfiguration->collectErrors()
                    ? [$generatorConfiguration->getErrorRegistryClass()]
                    : [ValidationException::class]
            )
        );

        // filter out non-compound uses and uses which link to the current namespace
        $use = array_filter($use, function ($classPath) use ($namespace) {
            return strstr(trim(str_replace("$namespace", '', $classPath), '\\'), '\\') ||
                (!strstr($classPath, '\\') && !empty($namespace));
        });

        try {
            $class = $render->renderTemplate(
                'Model.phptpl',
                [
                    'namespace'                         => $namespace,
                    'use'                               => $use,
                    'class'                             => $this->className,
                    'schema'                            => $this->schema,
                    'schemaHookResolver'                => new SchemaHookResolver($this->schema),
                    'generatorConfiguration'            => $generatorConfiguration,
                    'viewHelper'                        => new RenderHelper($generatorConfiguration),
                    // one hack a day keeps the problems away. Make true literal available for the templating. Easy fix
                    'true'                              => true,
                    'baseValidatorsWithoutCompositions' => array_filter(
                        $this->schema->getBaseValidators(),
                        function ($validator) {
                            return !is_a($validator, AbstractComposedPropertyValidator::class);
                        }
                    ),
                ]
            );
        } catch (PHPMicroTemplateException $exception) {
            throw new RenderException("Can't render class $this->classPath\\$this->className", 0, $exception);
        }

        return $class;
    }
}