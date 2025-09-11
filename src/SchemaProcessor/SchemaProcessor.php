<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\RenderJob;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\SchemaDefinition\SchemaDefinitionDictionary;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\ObjectInstantiationDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\SchemaNamespaceTransferDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\CompositionTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\PropertyMetaDataCollection;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;

/**
 * Class SchemaProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor
 */
class SchemaProcessor
{
    /** @var string */
    protected $currentClassPath;
    /** @var string */
    protected $currentClassName;

    /** @var Schema[] Collect processed schemas to avoid duplicated classes */
    protected $processedSchema = [];
    /** @var PropertyInterface[] Collect processed schemas to avoid duplicated classes */
    protected $processedMergedProperties = [];
    /** @var string[] */
    protected $generatedFiles = [];

    /**
     * SchemaProcessor constructor.
     */
    public function __construct(
        protected string $baseSource,
        protected string $destination,
        protected GeneratorConfiguration $generatorConfiguration,
        protected RenderQueue $renderQueue,
    ) {}

    /**
     * Process a given json schema file
     *
     * @throws SchemaException
     */
    public function process(JsonSchema $jsonSchema): void
    {
        $this->setCurrentClassPath($jsonSchema->getFile());
        $this->currentClassName = $this->generatorConfiguration->getClassNameGenerator()->getClassName(
            str_ireplace('.json', '', basename($jsonSchema->getFile())),
            $jsonSchema,
            false,
        );

        $this->processSchema(
            $jsonSchema,
            $this->currentClassPath,
            $this->currentClassName,
            new SchemaDefinitionDictionary(dirname($jsonSchema->getFile())),
            true,
        );
    }

    /**
     * Process a JSON schema stored as an associative array
     *
     * @param SchemaDefinitionDictionary $dictionary   If a nested object of a schema is processed import the
     *                                                 definitions of the parent schema to make them available for the
     *                                                 nested schema as well
     * @param bool                       $initialClass Is it an initial class or a nested class?
     *
     * @throws SchemaException
     */
    public function processSchema(
        JsonSchema $jsonSchema,
        string $classPath,
        string $className,
        SchemaDefinitionDictionary $dictionary,
        bool $initialClass = false,
    ): ?Schema {
        if ((!isset($jsonSchema->getJson()['type']) || $jsonSchema->getJson()['type'] !== 'object') &&
            !array_intersect(array_keys($jsonSchema->getJson()), ['anyOf', 'allOf', 'oneOf', 'if', '$ref'])
        ) {
            // skip the JSON schema as neither an object, a reference nor a composition is defined on the root level
            return null;
        }

        return $this->generateModel($classPath, $className, $jsonSchema, $dictionary, $initialClass);
    }

    /**
     * Generate a model and store the model to the file system
     *
     * @throws SchemaException
     */
    protected function generateModel(
        string $classPath,
        string $className,
        JsonSchema $jsonSchema,
        SchemaDefinitionDictionary $dictionary,
        bool $initialClass,
    ): Schema {
        $schemaSignature = $jsonSchema->getSignature();

        if (!$initialClass && isset($this->processedSchema[$schemaSignature])) {
            if ($this->generatorConfiguration->isOutputEnabled()) {
                echo "Duplicated signature $schemaSignature for class $className." .
                    " Redirecting to {$this->processedSchema[$schemaSignature]->getClassName()}\n";
            }

            return $this->processedSchema[$schemaSignature];
        }

        $schema = new Schema(
            $this->getTargetFileName($classPath, $className),
            $classPath,
            $className,
            $jsonSchema,
            $dictionary,
            $initialClass,
        );

        $this->processedSchema[$schemaSignature] = $schema;
        $json = $jsonSchema->getJson();
        $json['type'] = 'base';

        (new PropertyFactory(new PropertyProcessorFactory()))->create(
            new PropertyMetaDataCollection($jsonSchema->getJson()['required'] ?? []),
            $this,
            $schema,
            $className,
            $jsonSchema->withJson($json),
        );

        $this->generateClassFile($schema);

        return $schema;
    }

    /**
     * Attach a new class file render job to the render proxy
     */
    public function generateClassFile(Schema $schema): void {
        $this->renderQueue->addRenderJob(new RenderJob($schema));

        if ($this->generatorConfiguration->isOutputEnabled()) {
            echo sprintf(
                "Generated class %s\n",
                join(
                    '\\',
                    array_filter([
                        $this->generatorConfiguration->getNamespacePrefix(),
                        $schema->getClassPath(),
                        $schema->getClassName(),
                    ]),
                ),
            );
        }

        $this->generatedFiles[] = $schema->getTargetFileName();
    }


    /**
     * Gather all nested object properties and merge them together into a single merged property
     *
     * @param CompositionPropertyDecorator[] $compositionProperties
     *
     * @throws SchemaException
     */
    public function createMergedProperty(
        Schema $schema,
        PropertyInterface $property,
        array $compositionProperties,
        JsonSchema $propertySchema,
    ): ?PropertyInterface {
        $redirectToProperty = $this->redirectMergedProperty($compositionProperties);
        if ($redirectToProperty === null || $redirectToProperty instanceof PropertyInterface) {
            if ($redirectToProperty) {
                $property->addTypeHintDecorator(new CompositionTypeHintDecorator($redirectToProperty));
            }

            return $redirectToProperty;
        }

        /** @var JsonSchema $jsonSchema */
        $jsonSchema = $propertySchema->getJson()['propertySchema'];
        $schemaSignature = $jsonSchema->getSignature();

        if (!isset($this->processedMergedProperties[$schemaSignature])) {
            $mergedClassName = $this
                ->getGeneratorConfiguration()
                ->getClassNameGenerator()
                ->getClassName(
                    $property->getName(),
                    $propertySchema,
                    true,
                    $this->getCurrentClassName(),
                );

            $mergedPropertySchema = new Schema(
                $this->getTargetFileName($schema->getClassPath(), $mergedClassName),
                $schema->getClassPath(),
                $mergedClassName,
                $propertySchema,
            );

            $this->processedMergedProperties[$schemaSignature] = (new Property(
                    'MergedProperty',
                    new PropertyType($mergedClassName),
                    $mergedPropertySchema->getJsonSchema(),
                ))
                ->addDecorator(new ObjectInstantiationDecorator($mergedClassName, $this->getGeneratorConfiguration()))
                ->setNestedSchema($mergedPropertySchema);

            $this->transferPropertiesToMergedSchema($schema, $mergedPropertySchema, $compositionProperties);

            // make sure the merged schema knows all imports of the parent schema
            $mergedPropertySchema->addNamespaceTransferDecorator(new SchemaNamespaceTransferDecorator($schema));

            $this->generateClassFile($mergedPropertySchema);
        }

        $mergedSchema = $this->processedMergedProperties[$schemaSignature]->getNestedSchema();
        $schema->addUsedClass(
            join(
                '\\',
                array_filter([
                    $this->generatorConfiguration->getNamespacePrefix(),
                    $mergedSchema->getClassPath(),
                    $mergedSchema->getClassName(),
                ]),
            )
        );

        $property->addTypeHintDecorator(
            new CompositionTypeHintDecorator($this->processedMergedProperties[$schemaSignature]),
        );

        return $this->processedMergedProperties[$schemaSignature];
    }

    /**
     * Check if multiple $compositionProperties contain nested schemas. Only in this case a merged property must be
     * created. If no nested schemas are detected null will be returned. If only one $compositionProperty contains a
     * nested schema the $compositionProperty will be used as a replacement for the merged property.
     *
     * Returns false if a merged property must be created.
     *
     * @param CompositionPropertyDecorator[] $compositionProperties
     *
     * @return PropertyInterface|null|false
     */
    private function redirectMergedProperty(array $compositionProperties)
    {
        $redirectToProperty = null;
        foreach ($compositionProperties as $property) {
            if ($property->getNestedSchema()) {
                if ($redirectToProperty !== null) {
                    return false;
                }

                $redirectToProperty = $property;
            }
        }

        return $redirectToProperty;
    }

    /**
     * @param PropertyInterface[] $compositionProperties
     */
    private function transferPropertiesToMergedSchema(
        Schema $schema,
        Schema $mergedPropertySchema,
        array $compositionProperties,
    ): void {
        foreach ($compositionProperties as $property) {
            if (!$property->getNestedSchema()) {
                continue;
            }

            $property->getNestedSchema()->onAllPropertiesResolved(
                function () use ($property, $schema, $mergedPropertySchema): void {
                    foreach ($property->getNestedSchema()->getProperties() as $nestedProperty) {
                        $mergedPropertySchema->addProperty(
                        // don't validate fields in merged properties. All fields were validated before
                        // corresponding to the defined constraints of the composition property.
                            (clone $nestedProperty)->filterValidators(static fn(): bool => false),
                        );
                    }
                },
            );
        }
    }

    /**
     * Get the class path out of the file path of a schema file
     */
    protected function setCurrentClassPath(string $jsonSchemaFile): void
    {
        $path = str_replace($this->baseSource, '', dirname($jsonSchemaFile));
        $pieces = array_map(
            static fn(string $directory): string => ucfirst($directory),
            explode(DIRECTORY_SEPARATOR, $path),
        );

        $this->currentClassPath = join('\\', array_filter($pieces));
    }

    public function getCurrentClassPath(): string
    {
        return $this->currentClassPath;
    }

    public function getCurrentClassName(): string
    {
        return $this->currentClassName;
    }

    public function getGeneratedFiles(): array
    {
        return $this->generatedFiles;
    }

    public function getGeneratorConfiguration(): GeneratorConfiguration
    {
        return $this->generatorConfiguration;
    }

    private function getTargetFileName(string $classPath, string $className): string
    {
        return join(
            DIRECTORY_SEPARATOR,
            array_filter([$this->destination, str_replace('\\', DIRECTORY_SEPARATOR, $classPath), $className]),
        ) . '.php';
    }
}
