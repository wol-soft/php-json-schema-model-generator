<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPMicroTemplate\Render;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Interfaces\JSONModelInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\Utils\NormalizedName;
use PHPModelGenerator\Utils\RenderHelper;

class TypeScriptTypesPostProcessor extends PostProcessor
{
    private readonly Render $renderer;

    private GeneratorConfiguration $generatorConfiguration;
    private RenderHelper $renderHelper;

    /** @var Schema[] */
    private array $schemas = [];

    public function __construct(
        private readonly string $targetDirectory,
        private readonly bool $renderEnums = true,
    ) {
        (new ModelGenerator())->generateModelDirectory($targetDirectory);

        $this->renderer = new Render(__DIR__ . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR);
    }

    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $this->generatorConfiguration ??= $generatorConfiguration;
        $this->renderHelper ??= new RenderHelper($generatorConfiguration);

        $this->schemas[] = $schema;
    }

    public function postProcess(): void
    {
        parent::postProcess();

        foreach ($this->schemas as $schema) {
            $enumMap = [];

            foreach ($schema->getProperties() as $property) {
                if (
                    $this->renderEnums
                    && isset($property->getJsonSchema()->getJson()['enum'])
                    && $this->validateEnum($property)
                ) {
                    // TODO: deduplicate
                    $enumMap[$property->getName()] = $this->renderEnum($schema, $property);
                }
            }

            $result = file_put_contents(
                // TODO nested directory structure from namespaces
                $this->targetDirectory . DIRECTORY_SEPARATOR . $schema->getClassName() . '.ts',
                $this->renderer->renderTemplate(
                    'TypeScriptType.tstpl',
                    [
                        'name' => $schema->getClassName(),
                        'properties' => array_filter(
                            $schema->getProperties(),
                            fn(PropertyInterface $property): bool => !$property->isInternal(),
                        ),
                        'imports' => [
                            ...$this->getTypeScriptImports($schema),
                            ...array_map(fn (string $enum): array => ['name' => $enum, 'path' => "./$enum"], $enumMap),
                        ],
                        'typescriptType' => fn (PropertyInterface $property): string => join(
                            ' | ',
                            array_unique(
                                array_map(
                                    function (string $type) use ($property, $enumMap): string {
                                        if (isset($enumMap[$property->getName()]) && $type !== 'null') {
                                            return $enumMap[$property->getName()];
                                        }

                                        return match (str_replace('[]', '', $type)) {
                                            'null' => 'null',
                                            'string' => 'string',
                                            'int', 'float' => 'number',
                                            'bool' => 'boolean',
                                            '', 'mixed' => 'any',
                                            default => $property->getType()->getName(),
                                        } . (str_contains($type, '[]') ? '[]' : '');
                                    },
                                    explode('|', $this->renderHelper->getTypeHintAnnotation($property)),
                                ),
                            ),
                        ),
                    ],
                ),
            );

            if ($result === false) {
                // @codeCoverageIgnoreStart
                throw new FileSystemException("Can't write TypeScript type {$schema->getClassName()}.");
                // @codeCoverageIgnoreEnd
            }

            $this->generatorConfiguration->getLogger()->info(
                'Rendered TypeScript type {type}',
                ['type' => $schema->getClassName()],
            );
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
            foreach (
                array_unique(
                    [...explode('|', $property->getTypeHint()), ...explode('|', $property->getTypeHint(true))],
                ) as $type
            ) {
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

        $index = 0;
        $maxSharedSegments = min(count($baseParts), count($targetParts));
        while ($index < $maxSharedSegments && $baseParts[$index] === $targetParts[$index]) {
            $index++;
        }

        $upSegments = array_fill(0, max(count($baseParts) - $index, 0), '..');
        $downSegments = array_slice($targetParts, $index);
        $pathSegments = array_merge($upSegments, $downSegments);

        return './' . implode('/', $pathSegments);
    }

    private function renderEnum(Schema $schema, PropertyInterface $property): string
    {
        $json = $property->getJsonSchema()->getJson();
        $enumName = $json['$id'] ?? $schema->getClassName() . ucfirst($property->getName());
        $cases = [];

        foreach ($json['enum'] as $value) {
            $caseName = $this->getCaseName($value, $json['enum-map'] ?? null, $property->getJsonSchema());
            $cases[$caseName] = var_export($value, true);
        }

        $result = file_put_contents(
            $this->targetDirectory . DIRECTORY_SEPARATOR . $enumName . '.ts',
            $this->renderer->renderTemplate(
                'TypeScriptEnum.tstpl',
                ['name' => $enumName, 'cases' => $cases],
            ),
        );

        if ($result === false) {
            // @codeCoverageIgnoreStart
            throw new FileSystemException("Can't write TypeScript enum $enumName.");
            // @codeCoverageIgnoreEnd
        }

        $this->generatorConfiguration->getLogger()->info('Rendered TypeScript enum {enum}', ['enum' => $enumName]);

        return $enumName;
    }

    /**
     * @throws SchemaException
     */
    private function validateEnum(PropertyInterface $property): bool
    {
        $json = $property->getJsonSchema()->getJson();

        $types = $this->getArrayTypes($json['enum']);

        // the enum must contain either only string values or provide a value map to resolve the values
        if ($types !== ['string'] && !isset($json['enum-map'])) {
            throw new SchemaException(
                sprintf(
                    'Unmapped enum %s in file %s',
                    $property->getName(),
                    $property->getJsonSchema()->getFile(),
                ),
                $property->getJsonSchema(),
            );
        }

        if (isset($json['enum-map'])) {
            $sortedValues = $json['enum'];
            asort($sortedValues);
            $enumMap = $json['enum-map'];
            if (is_array($enumMap)) {
                asort($enumMap);
            }

            if (
                !is_array($enumMap)
                || $this->getArrayTypes(array_keys($enumMap)) !== ['string']
                || count(array_uintersect(
                    $enumMap,
                    $sortedValues,
                    fn($first, $second): int => $first === $second ? 0 : 1,
                )) !== count($sortedValues)
            ) {
                throw new SchemaException(
                    sprintf(
                        'invalid enum map %s in file %s',
                        $property->getName(),
                        $property->getJsonSchema()->getFile(),
                    ),
                    $property->getJsonSchema(),
                );
            }
        }

        return true;
    }

    private function getArrayTypes(array $array): array
    {
        return array_unique(array_map(
            static fn($item): string => gettype($item),
            $array,
        ));
    }

    private function getCaseName(mixed $value, ?array $map, JsonSchema $jsonSchema): string
    {
        $caseName = ucfirst(NormalizedName::from($map ? array_search($value, $map, true) : $value, $jsonSchema));

        if (preg_match('/^\d/', $caseName) === 1) {
            $caseName = "_$caseName";
        }

        return $caseName;
    }
}
