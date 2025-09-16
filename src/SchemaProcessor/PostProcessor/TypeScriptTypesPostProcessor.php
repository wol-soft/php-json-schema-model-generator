<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPMicroTemplate\Render;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Interfaces\JSONModelInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\ModelGenerator;

class TypeScriptTypesPostProcessor extends PostProcessor
{
    private string $targetDirectory;
    private Render $renderer;
    private GeneratorConfiguration $generatorConfiguration;
    /** @var Schema[] */
    private array $schemas = [];

    public function __construct(string $targetDirectory)
    {
        (new ModelGenerator())->generateModelDirectory($targetDirectory);

        $this->targetDirectory = $targetDirectory;
        $this->renderer = new Render(__DIR__ . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR);
    }

    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $this->generatorConfiguration = $generatorConfiguration;
        $this->schemas[] = $schema;
    }

    public function postProcess(): void
    {
        parent::postProcess();

        foreach ($this->schemas as $schema) {
            $result = file_put_contents(
                $this->targetDirectory . DIRECTORY_SEPARATOR . $schema->getClassName() . '.ts',
                $this->renderer->renderTemplate(
                    'TypeScriptType.tstpl',
                    [
                        'name' => $schema->getClassName(),
                        'properties' => array_filter(
                            $schema->getProperties(),
                            fn(PropertyInterface $property): bool => !$property->isInternal(),
                        ),
                        'imports' => $this->getTypeScriptImports($schema),
                        'typescriptType' => fn(PropertyInterface $property): string => join(
                            ' | ',
                            array_map(
                                fn(string $type): string => match (str_replace('[]', '', $type)) {
                                        'string'       => 'string',
                                        'int', 'float' => 'number',
                                        'bool'         => 'boolean',
                                        '', 'mixed'    => 'any',
                                        default        => $property->getType()->getName(),
                                    } . (str_contains($type, '[]') ? '[]' : ''),
                                explode('|', $property->getTypeHint()),
                            ),
                        ),
                    ],
                )
            );

            if ($result === false) {
                // @codeCoverageIgnoreStart
                throw new FileSystemException("Can't write TypeScript type {$schema->getClassName()}.",);
                // @codeCoverageIgnoreEnd
            }

            if ($this->generatorConfiguration->isOutputEnabled()) {
                // @codeCoverageIgnoreStart
                echo "Rendered TypeScript type {$schema->getClassName()}\n";
                // @codeCoverageIgnoreEnd
            }
        }
    }

    /**
     * @return string[]
     */
    private function getTypeScriptImports(Schema $schema): array
    {
        $imports = [];

        foreach ($schema->getProperties() as $property) {
            // use typehint instead of type to cover multi-types
            foreach (array_unique(
                         [...explode('|', $property->getTypeHint()), ...explode('|', $property->getTypeHint(true))]
                     ) as $type) {
                // as the typehint only knows the class name but not the fqcn, lookup in the original imports
                foreach ($schema->getUsedClasses() as $originalClassImport) {
                    if (str_ends_with($originalClassImport, "\\$type")) {
                        $type = $originalClassImport;
                    }
                }

                if (class_exists($type) && in_array(JSONModelInterface::class, class_implements($type))) {
                    $imports[] = [
                        'name' => basename($type),
                        'path' => $this->relativeNamespacePath($schema, $type),
                    ];
                }
            }
        }

        return array_filter(array_unique($imports));
    }

    private function relativeNamespacePath(Schema $schema, string $targetNS): string
    {
        $baseParts = preg_split(
            '/\\\\+/',
            trim($this->generatorConfiguration->getNamespacePrefix() . '\\' . $schema->getClassPath(), '\\'),
        ) ?: [];
        $targetParts = preg_split('/\\\\+/', trim($targetNS, '\\')) ?: [];

        $i = 0;
        $max = min(count($baseParts), count($targetParts));
        while ($i < $max && $baseParts[$i] === $targetParts[$i]) {
            $i++;
        }

        $ups   = array_fill(0, max(count($baseParts) - $i, 0), '..');
        $downs = array_slice($targetParts, $i);
        $parts = array_merge($ups, $downs);
        $rel   = implode('/', $parts);

        return './' . $rel;
    }
}
