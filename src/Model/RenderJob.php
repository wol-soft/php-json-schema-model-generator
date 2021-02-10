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
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
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
     * @param PostProcessor[] $postProcessors
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
     * @param GeneratorConfiguration $generatorConfiguration
     *
     * @throws FileSystemException
     * @throws RenderException
     */
    public function render(GeneratorConfiguration $generatorConfiguration): void
    {
        $this->generateModelDirectory();

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
            echo sprintf(
                "Rendered class %s\n",
                join(
                    '\\',
                    array_filter([$generatorConfiguration->getNamespacePrefix(), $this->classPath, $this->className])
                )
            );
        }
    }

    /**
     * Generate the directory structure for saving a generated class
     *
     * @throws FileSystemException
     */
    protected function generateModelDirectory(): void
    {
        $destination = dirname($this->fileName);
        if (!is_dir($destination) && !mkdir($destination, 0777, true)) {
            throw new FileSystemException("Can't create path $destination");
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
        $namespace = trim(join('\\', [$generatorConfiguration->getNamespacePrefix(), $this->classPath]), '\\');

        try {
            $class = (new Render(__DIR__ . '/../Templates/'))->renderTemplate(
                'Model.phptpl',
                [
                    'namespace'                         => $namespace,
                    'use'                               => $this->getUseForSchema($generatorConfiguration, $namespace),
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

    /**
     * @param GeneratorConfiguration $generatorConfiguration
     * @param string $namespace
     *
     * @return string[]
     */
    protected function getUseForSchema(GeneratorConfiguration $generatorConfiguration, string $namespace): array
    {
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

        return $use;
    }
}
