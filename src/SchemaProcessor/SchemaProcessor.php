<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\RenderJob;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\SchemaDefinition\SchemaDefinitionDictionary;
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
    private const SCHEMA_SIGNATURE_RELEVANT_FIELDS = [
        'type',
        'properties',
        '$ref',
        'allOf',
        'anyOf',
        'oneOf',
        'not',
        'if',
        'then',
        'else',
        'additionalProperties',
        'required',
        'propertyNames',
        'minProperties',
        'maxProperties',
        'dependencies',
        'patternProperties',
    ];

    /** @var GeneratorConfiguration */
    protected $generatorConfiguration;
    /** @var RenderQueue */
    protected $renderQueue;
    /** @var string */
    protected $baseSource;
    /** @var string */
    protected $destination;

    /** @var string */
    protected $currentClassPath;
    /** @var string */
    protected $currentClassName;

    /** @var array Collect processed schemas to avoid duplicated classes */
    protected $processedSchema = [];
    /** @var array */
    protected $generatedFiles = [];

    /**
     * SchemaProcessor constructor.
     *
     * @param string                 $baseSource
     * @param string                 $destination
     * @param GeneratorConfiguration $generatorConfiguration
     * @param RenderQueue            $renderQueue
     */
    public function __construct(
        string $baseSource,
        string $destination,
        GeneratorConfiguration $generatorConfiguration,
        RenderQueue $renderQueue
    ) {
        $this->baseSource = $baseSource;
        $this->destination = $destination;
        $this->generatorConfiguration = $generatorConfiguration;
        $this->renderQueue = $renderQueue;
    }

    /**
     * Process a given json schema file
     *
     * @param JsonSchema $jsonSchema
     *
     * @throws SchemaException
     */
    public function process(JsonSchema $jsonSchema): void
    {
        $this->setCurrentClassPath($jsonSchema->getFile());
        $this->currentClassName = $this->generatorConfiguration->getClassNameGenerator()->getClassName(
            str_ireplace('.json', '', basename($jsonSchema->getFile())),
            $jsonSchema,
            false
        );

        $this->processSchema(
            $jsonSchema,
            $this->currentClassPath,
            $this->currentClassName,
            new SchemaDefinitionDictionary(dirname($jsonSchema->getFile())),
            true
        );
    }

    /**
     * Process a JSON schema stored as an associative array
     *
     * @param JsonSchema                 $jsonSchema
     * @param string                     $classPath
     * @param string                     $className
     * @param SchemaDefinitionDictionary $dictionary   If a nested object of a schema is processed import the
     *                                                 definitions of the parent schema to make them available for the
     *                                                 nested schema as well
     * @param bool                       $initialClass Is it an initial class or a nested class?
     *
     * @return Schema|null
     *
     * @throws SchemaException
     */
    public function processSchema(
        JsonSchema $jsonSchema,
        string $classPath,
        string $className,
        SchemaDefinitionDictionary $dictionary,
        bool $initialClass = false
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
     * @param string                     $classPath
     * @param string                     $className
     * @param JsonSchema                 $jsonSchema
     * @param SchemaDefinitionDictionary $dictionary
     * @param bool                       $initialClass
     *
     * @return Schema
     *
     * @throws SchemaException
     */
    protected function generateModel(
        string $classPath,
        string $className,
        JsonSchema $jsonSchema,
        SchemaDefinitionDictionary $dictionary,
        bool $initialClass
    ): Schema {
        // create the signature from all fields which are directly relevant for the created object. Additional fields
        // can be ignored as the resulting code will be identical
        $schemaSignature = md5(
            json_encode(
                array_intersect_key(
                    $jsonSchema->getJson(),
                    array_fill_keys(self::SCHEMA_SIGNATURE_RELEVANT_FIELDS, null)
                )
            )
        );

        if (!$initialClass && isset($this->processedSchema[$schemaSignature])) {
            if ($this->generatorConfiguration->isOutputEnabled()) {
                echo "Duplicated signature $schemaSignature for class $className." .
                    " Redirecting to {$this->processedSchema[$schemaSignature]->getClassName()}\n";
            }

            return $this->processedSchema[$schemaSignature];
        }

        $schema = new Schema($classPath, $className, $jsonSchema, $dictionary, $initialClass);

        $this->processedSchema[$schemaSignature] = $schema;
        $json = $jsonSchema->getJson();
        $json['type'] = 'base';

        (new PropertyFactory(new PropertyProcessorFactory()))->create(
            new PropertyMetaDataCollection($jsonSchema->getJson()['required'] ?? []),
            $this,
            $schema,
            $className,
            $jsonSchema->withJson($json)
        );

        $this->generateClassFile($classPath, $className, $schema);

        return $schema;
    }

    /**
     * Attach a new class file render job to the render proxy
     *
     * @param string $classPath
     * @param string $className
     * @param Schema $schema
     */
    public function generateClassFile(
        string $classPath,
        string $className,
        Schema $schema
    ): void {
        $fileName = join(
            DIRECTORY_SEPARATOR,
            array_filter([$this->destination, str_replace('\\', DIRECTORY_SEPARATOR, $classPath), $className])
        ) . '.php';

        $this->renderQueue->addRenderJob(new RenderJob($fileName, $classPath, $className, $schema));

        if ($this->generatorConfiguration->isOutputEnabled()) {
            echo sprintf(
                "Generated class %s\n",
                join('\\', array_filter([$this->generatorConfiguration->getNamespacePrefix(), $classPath, $className]))
            );
        }

        $this->generatedFiles[] = $fileName;
    }

    /**
     * Get the class path out of the file path of a schema file
     *
     * @param string $jsonSchemaFile
     */
    protected function setCurrentClassPath(string $jsonSchemaFile): void
    {
        $path = str_replace($this->baseSource, '', dirname($jsonSchemaFile));
        $pieces = array_map(
            function ($directory) {
                return ucfirst($directory);
            },
            explode(DIRECTORY_SEPARATOR, $path)
        );

        $this->currentClassPath = join('\\', array_filter($pieces));
    }

    /**
     * @return string
     */
    public function getCurrentClassPath(): string
    {
        return $this->currentClassPath;
    }

    /**
     * @return string
     */
    public function getCurrentClassName(): string
    {
        return $this->currentClassName;
    }

    /**
     * @return array
     */
    public function getGeneratedFiles(): array
    {
        return $this->generatedFiles;
    }

    /**
     * @return GeneratorConfiguration
     */
    public function getGeneratorConfiguration(): GeneratorConfiguration
    {
        return $this->generatorConfiguration;
    }
}
