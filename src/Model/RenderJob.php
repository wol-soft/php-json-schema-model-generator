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
    /**
     * Create a new class render job
     *
     * @param Schema $schema The Schema object which holds properties and validators
     */
    public function __construct(
        protected Schema $schema,
    ) {}

    /**
     * @param PostProcessor[] $postProcessors
     */
    public function executePostProcessors(array $postProcessors, GeneratorConfiguration $generatorConfiguration): void
    {
        foreach ($postProcessors as $postProcessor) {
            $postProcessor->process($this->schema, $generatorConfiguration);
        }
    }

    /**
     * Execute the render job and render the class
     *
     * @throws FileSystemException
     * @throws RenderException
     */
    public function render(GeneratorConfiguration $generatorConfiguration): void
    {
        $this->generateModelDirectory();

        $class = $this->renderClass($generatorConfiguration);

        if (file_exists($this->schema->getTargetFileName())) {
            throw new FileSystemException(
                "File {$this->schema->getTargetFileName()} already exists. Make sure object IDs are unique.",
            );
        }

        if (!file_put_contents($this->schema->getTargetFileName(), $class)) {
            // @codeCoverageIgnoreStart
            throw new FileSystemException(
                "Can't write class {$this->schema->getClassPath()}\\{$this->schema->getClassName()}.",
            );
            // @codeCoverageIgnoreEnd
        }

        require $this->schema->getTargetFileName();

        if ($generatorConfiguration->isOutputEnabled()) {
            echo sprintf(
                "Rendered class %s\n",
                join(
                    '\\',
                    array_filter([
                        $generatorConfiguration->getNamespacePrefix(),
                        $this->schema->getClassPath(),
                        $this->schema->getClassName(),
                    ]),
                ),
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
        $destination = dirname($this->schema->getTargetFileName());
        if (!is_dir($destination) && !mkdir($destination, 0777, true)) {
            throw new FileSystemException("Can't create path $destination");
        }
    }

    /**
     * Render a class. Returns the php code of the class
     *
     * @throws RenderException
     */
    protected function renderClass(GeneratorConfiguration $generatorConfiguration): string
    {
        $namespace = trim(
            join('\\', [$generatorConfiguration->getNamespacePrefix(), $this->schema->getClassPath()]),
            '\\',
        );

        try {
            $class = (new Render(__DIR__ . '/../Templates/'))->renderTemplate(
                'Model.phptpl',
                [
                    'namespace'                         => $namespace,
                    'use'                               => $this->getUseForSchema($generatorConfiguration, $namespace),
                    'schema'                            => $this->schema,
                    'schemaHookResolver'                => new SchemaHookResolver($this->schema),
                    'generatorConfiguration'            => $generatorConfiguration,
                    'viewHelper'                        => new RenderHelper($generatorConfiguration),
                    // one hack a day keeps the problems away. Make true literal available for the templating. Easy fix
                    'true'                              => true,
                    'baseValidatorsWithoutCompositions' => array_filter(
                        $this->schema->getBaseValidators(),
                        static fn($validator): bool => !is_a($validator, AbstractComposedPropertyValidator::class),
                    ),
                ],
            );
        } catch (PHPMicroTemplateException $exception) {
            throw new RenderException(
                "Can't render class {$this->schema->getClassPath()}\\{$this->schema->getClassName()}",
                0,
                $exception,
            );
        }

        return $class;
    }

    /**
     * @return string[]
     */
    protected function getUseForSchema(GeneratorConfiguration $generatorConfiguration, string $namespace): array
    {
        return RenderHelper::filterClassImports(
            array_unique(
                array_merge(
                    $this->schema->getUsedClasses(),
                    $generatorConfiguration->collectErrors()
                        ? [$generatorConfiguration->getErrorRegistryClass()]
                        : [ValidationException::class],
                ),
            ),
            $namespace,
        );
    }
}
