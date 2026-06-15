<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPMicroTemplate\Render;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Interfaces\BuilderInterface;
use PHPModelGenerator\Interfaces\JSONModelInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintTransferDecorator;
use PHPModelGenerator\Utils\RenderHelper;
use ReflectionClass;

class BuilderClassPostProcessor extends PostProcessor
{
    /** @var array<string, Schema> */
    private array $schemas = [];
    private ?GeneratorConfiguration $generatorConfiguration = null;
    private ?RenderHelper $renderHelper = null;

    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        // Collect schemas, deduplicating by target filename to avoid generating builder
        // classes multiple times for shared schemas (e.g. $defs referenced from multiple
        // composition branches).
        $target = $schema->getTargetFileName();
        if (!isset($this->schemas[$target])) {
            $this->schemas[$target] = $schema;
        }

        $this->generatorConfiguration ??= $generatorConfiguration;
        $this->renderHelper ??= new RenderHelper($generatorConfiguration);
    }

    public function preProcess(): void
    {
        $this->generatorConfiguration = null;
        $this->renderHelper = null;
    }

    public function postProcess(): void
    {
        parent::postProcess();

        foreach ($this->schemas as $schema) {
            $properties = [];
            foreach ($schema->getProperties() as $property) {
                if (!$property->isInternal()) {
                    $properties[] = (clone $property)
                        ->setReadOnly(false)
                        ->addTypeHintDecorator(new TypeHintTransferDecorator($property))
                        ->filterValidators(static fn(Validator $validator): bool => false);
                }
            }

            $namespace = trim(
                join('\\', [$this->generatorConfiguration->getNamespacePrefix(), $schema->getClassPath()]),
                '\\',
            );

            $result = file_put_contents(
                $filename = str_replace('.php', 'Builder.php', $schema->getTargetFileName()),
                (new Render(__DIR__ . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR))->renderTemplate(
                    'BuilderClass.phptpl',
                    [
                        'namespace'              => $namespace,
                        'class'                  => $schema->getClassName(),
                        'schema'                 => $schema,
                        'properties'             => $properties,
                        'use'                    => $this->getBuilderClassImports(
                            $properties,
                            $schema->getUsedClasses(),
                            $namespace,
                        ),
                        'generatorConfiguration' => $this->generatorConfiguration,
                        'viewHelper'             => new RenderHelper($this->generatorConfiguration),
                    ],
                )
            );

            $fqcn = "$namespace\\{$schema->getClassName()}Builder";

            if ($result === false) {
                // @codeCoverageIgnoreStart
                throw new FileSystemException("Can't write builder class $fqcn.",);
                // @codeCoverageIgnoreEnd
            }

            if (!class_exists($fqcn, false)) {
                require $filename;
            }

            if ($this->generatorConfiguration->isOutputEnabled()) {
                // @codeCoverageIgnoreStart
                echo "Rendered builder class $fqcn\n";
                // @codeCoverageIgnoreEnd
            }
        }
    }

    /**
     * @param PropertyInterface[] $properties
     *
     * @return string[]
     */
    private function getBuilderClassImports(array $properties, array $originalClassImports, string $namespace): array
    {
        $imports = [BuilderInterface::class];
        $imports[] = $this->generatorConfiguration->collectErrors()
            ? $this->generatorConfiguration->getErrorRegistryClass()
            : ValidationException::class;

        foreach ($properties as $property) {
            // use typehint instead of type to cover multi-types
            foreach (
                array_unique([
                ...explode('|', $this->renderHelper->getTypeHintAnnotation($property)),
                ...explode('|', $this->renderHelper->getTypeHintAnnotation($property, true)),
                ]) as $typeAnnotation
            ) {
                $type = str_replace('[]', '', $typeAnnotation);

                // as the typehint only knows the class name but not the fqcn, lookup in the original imports
                foreach ($originalClassImports as $originalClassImport) {
                    if (str_ends_with((string) $originalClassImport, "\\$type")) {
                        $type = $originalClassImport;
                    }
                }

                if (class_exists($type) || enum_exists($type)) {
                    $imports[] = $type;

                    // for nested objects, allow additionally to pass an instance of the nested model also just plain
                    // arrays which will result in an object instantiation and validation during the build process
                    if (in_array(JSONModelInterface::class, class_implements($type))) {
                        $builderClassName = (new ReflectionClass($type))->getShortName() . 'Builder';
                        $property->addTypeHintDecorator(new TypeHintDecorator(
                            [$builderClassName . (str_contains($typeAnnotation, '[]') ? '[]' : ''), 'array'],
                        ));

                        $property->setType();

                        $imports[] = $type . 'Builder';
                    }
                }
            }
        }

        return RenderHelper::filterClassImports(array_unique($imports), $namespace);
    }
}
