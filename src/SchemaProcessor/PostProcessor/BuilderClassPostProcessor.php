<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPMicroTemplate\Render;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\FilterValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintTransferDecorator;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class PopulatePostProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor\PostProcessor
 */
class BuilderClassPostProcessor extends PostProcessor
{
    /** @var Schema[] */
    private array $schemas = [];
    private GeneratorConfiguration $generatorConfiguration;

    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $this->schemas[] = $schema;
        $this->generatorConfiguration = $generatorConfiguration;
    }

    public function postProcess(): void
    {
        parent::postProcess();

        // TODO: implicit null?
        // TODO: nested objects

        foreach ($this->schemas as $schema) {
            $properties = [];
            foreach ($schema->getProperties() as $property) {
                if (!$property->isInternal()) {
                    $properties[] = (clone $property)
                        ->setReadOnly(false)
                        ->setType($property->getType(), new PropertyType($property->getType(true)->getName(), true))
                        ->addTypeHintDecorator(new TypeHintTransferDecorator($property))
                        ->filterValidators(static fn(Validator $validator): bool
                            => is_a($validator->getValidator(), FilterValidator::class)
                        );
                }
            }

            $this->generateModelDirectory($schema->getTargetFileName());

            $namespace = trim(
                join('\\', [$this->generatorConfiguration->getNamespacePrefix(), $schema->getClassPath()]),
                '\\',
            );

            $result = file_put_contents(
                str_replace('.php', 'Builder.php', $schema->getTargetFileName()),
                (new Render(__DIR__ . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR))->renderTemplate(
                    'BuilderClass.phptpl',
                    [
                        'namespace'              => $namespace,
                        'class'                  => $schema->getClassName(),
                        'schema'                 => $schema,
                        'properties'             => $properties,
                        'use'                    => $this->getBuilderClassImports($properties, $schema->getUsedClasses(), $namespace),
                        'generatorConfiguration' => $this->generatorConfiguration,
                        'viewHelper'             => new RenderHelper($this->generatorConfiguration),
                    ],
                )
            );

            $fqcn = "{$schema->getClassPath()}\\{$schema->getClassName()}Builder";

            if ($result === false) {
                // @codeCoverageIgnoreStart
                throw new FileSystemException("Can't write builder class $fqcn.",);
                // @codeCoverageIgnoreEnd
            }

            if ($this->generatorConfiguration->isOutputEnabled()) {
                // @codeCoverageIgnoreStart
                echo "Rendered builder class $fqcn\n";
                // @codeCoverageIgnoreEnd
            }
        }
    }

    protected function generateModelDirectory(string $targetFileName): void
    {
        $destination = dirname($targetFileName);
        if (!is_dir($destination) && !mkdir($destination, 0777, true)) {
            throw new FileSystemException("Can't create path $destination");
        }
    }

    /**
     * @param PropertyInterface[] $properties
     *
     * @return string[]
     */
    private function getBuilderClassImports(array $properties, array $originalClassImports, string $namespace): array
    {
        $imports = [];

        if ($this->generatorConfiguration->collectErrors()) {
            $imports[] = $this->generatorConfiguration->getErrorRegistryClass();
        }

        foreach ($properties as $property) {
            foreach (array_unique(
                [...explode('|', $property->getTypeHint()), ...explode('|', $property->getTypeHint(true))]
            ) as $type) {
                // as the typehint only knows the class name but not the fqcn, lookup in the original imports
                foreach ($originalClassImports as $originalClassImport) {
                    if (str_ends_with($originalClassImport, "\\$type")) {
                        $type = $originalClassImport;
                    }
                }

                if (class_exists($type)) {
                    $imports[] = $type;
                }
            }
        }

        return RenderHelper::filterClassImports(array_unique($imports), $namespace);
    }
}
