<?php

declare(strict_types=1);

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
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\ConditionalPropertyValidator;
use PHPModelGenerator\Model\Validator\PropertyTemplateValidator;
use PHPModelGenerator\PropertyProcessor\ComposedValue\AllOfProcessor;
use PHPModelGenerator\PropertyProcessor\ComposedValue\ComposedPropertiesInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\ObjectInstantiationDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\SchemaNamespaceTransferDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\CompositionTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\SchemaProvider\SchemaProviderInterface;

/**
 * Class SchemaProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor
 */
class SchemaProcessor
{
    protected string $currentClassPath;
    protected string $currentClassName;

    /** @var Schema[] Collect processed schemas to avoid duplicated classes */
    protected array $processedSchema = [];
    /** @var PropertyInterface[] Collect processed schemas to avoid duplicated classes */
    protected array $processedMergedProperties = [];
    /**
     * Global index of schemas keyed by the canonical file path or URL returned by
     * SchemaProviderInterface::getRef(). Used to deduplicate external $ref resolutions across
     * all schema processings, making class generation order-independent.
     *
     * When a $ref triggers processTopLevelSchema() for a file that the provider has not yet
     * reached, the canonical Schema is registered here before property processing begins. If
     * the provider later iterates the same file, generateModel() detects the match via the
     * combined file-path + content-signature check and returns the already-registered Schema
     * without creating a duplicate render job.
     *
     * Note: for providers such as OpenAPIv3Provider that yield multiple distinct schemas from
     * a single source file, each schema has a unique content signature; the signature check
     * prevents false-positive deduplication across schemas that merely share the same file.
     *
     * @var Schema[]
     */
    protected array $processedFileSchemas = [];
    /** @var string[] */
    protected array $generatedFiles = [];

    public function __construct(
        protected SchemaProviderInterface $schemaProvider,
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
            new SchemaDefinitionDictionary($jsonSchema),
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
        if (
            (!isset($jsonSchema->getJson()['type']) || $jsonSchema->getJson()['type'] !== 'object') &&
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

        // For initial-class calls: if this exact file+content was already processed eagerly via
        // processTopLevelSchema() (triggered by a $ref resolution), reuse that schema to avoid a
        // duplicate render job. Both checks are required:
        // - The file-path check detects that this file was already processed via a $ref.
        // - The signature check ensures we do not short-circuit when a different schema shares
        //   the same source file (e.g. OpenAPI v3 where all component schemas are yielded from
        //   the same spec file — each has a unique signature).
        if (
            $initialClass
            && isset($this->processedSchema[$schemaSignature])
            && $this->getProcessedFileSchema($jsonSchema->getFile()) !== null
        ) {
            return $this->processedSchema[$schemaSignature];
        }

        $schema = new Schema(
            $this->getTargetFileName($classPath, $className),
            $classPath,
            $className,
            $jsonSchema,
            $dictionary,
            $initialClass,
            $this->generatorConfiguration,
        );

        // Register by content signature (secondary dedup for content-identical inline schemas).
        $this->processedSchema[$schemaSignature] = $schema;
        // Register by canonical file path/URL (primary dedup for external $ref resolutions).
        // Registering here — before property processing — ensures that any $ref back to this
        // file encountered while processing the referencing schema finds this canonical schema
        // immediately, regardless of which schema was discovered first by the provider.
        $this->registerProcessedFileSchema($jsonSchema->getFile(), $schema);
        $json = $jsonSchema->getJson();
        $json['type'] = 'base';

        (new PropertyFactory(new PropertyProcessorFactory()))->create(
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
    public function generateClassFile(Schema $schema): void
    {
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
        $fileDir  = str_replace('\\', '/', dirname($jsonSchemaFile));
        $baseDir  = str_replace('\\', '/', $this->schemaProvider->getBaseDirectory());
        $relative = str_replace($baseDir, '', $fileDir);

        // If the file is outside the provider's base directory, str_replace leaves the absolute
        // path untouched. In that case fall back to using just the last directory component so
        // the generated class path stays sensible rather than encoding an absolute path.
        if ($relative === $fileDir) {
            $relative = basename($fileDir);
        }

        $pieces = array_map(
            static fn(string $directory): string => ucfirst((string) preg_replace('/\W/', '', $directory)),
            explode('/', $relative),
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

    public function getSchemaProvider(): SchemaProviderInterface
    {
        return $this->schemaProvider;
    }

    public function getProcessedFileSchema(string $fileKey): ?Schema
    {
        return $this->processedFileSchemas[$this->normaliseFileKey($fileKey)] ?? null;
    }

    public function registerProcessedFileSchema(string $fileKey, Schema $schema): void
    {
        $this->processedFileSchemas[$this->normaliseFileKey($fileKey)] = $schema;
    }

    /**
     * Normalise a file path or URL to a consistent key for processedFileSchemas.
     * On Windows, RecursiveDirectoryIterator may produce backslash-separated paths while
     * RefResolverTrait produces forward-slash paths for the same file. Normalising to forward
     * slashes ensures the two representations map to the same key.
     */
    private function normaliseFileKey(string $fileKey): string
    {
        return str_replace('\\', '/', $fileKey);
    }

    /**
     * Process an external schema file with its canonical class name and path, exactly as
     * process() would, but without overwriting the current class path / class name context
     * (which belongs to the schema that triggered the $ref resolution).
     *
     * Returns the resulting Schema, or null if the file does not define an object/composition.
     *
     * @throws SchemaException
     */
    public function processTopLevelSchema(JsonSchema $jsonSchema): ?Schema
    {
        $savedClassPath  = $this->currentClassPath;
        $savedClassName  = $this->currentClassName;

        $this->setCurrentClassPath($jsonSchema->getFile());
        $this->currentClassName = $this->generatorConfiguration->getClassNameGenerator()->getClassName(
            str_ireplace('.json', '', basename($jsonSchema->getFile())),
            $jsonSchema,
            false,
        );

        $schema = $this->processSchema(
            $jsonSchema,
            $this->currentClassPath,
            $this->currentClassName,
            new SchemaDefinitionDictionary($jsonSchema),
            true,
        );

        $this->currentClassPath = $savedClassPath;
        $this->currentClassName = $savedClassName;

        return $schema;
    }

    /**
     * Transfer properties of composed properties to the given schema to offer a complete model
     * including all composed properties.
     *
     * This is an internal pipeline mechanic (Q5.1): not a JSON Schema keyword and therefore not
     * a Draft modifier. It is called as an explicit post-step from generateModel after all Draft
     * modifiers have run on the root-level BaseProperty.
     *
     * @throws SchemaException
     */
    public function transferComposedPropertiesToSchema(PropertyInterface $property, Schema $schema): void
    {
        foreach ($property->getValidators() as $validator) {
            $validator = $validator->getValidator();

            if (!is_a($validator, AbstractComposedPropertyValidator::class)) {
                continue;
            }

            // If the transferred validator of the composed property is also a composed property
            // strip the nested composition validations from the added validator. The nested
            // composition will be validated in the object generated for the nested composition
            // which will be executed via an instantiation. Consequently, the validation must not
            // be executed in the outer composition.
            $schema->addBaseValidator(
                ($validator instanceof ComposedPropertyValidator)
                    ? $validator->withoutNestedCompositionValidation()
                    : $validator,
            );

            if (!is_a($validator->getCompositionProcessor(), ComposedPropertiesInterface::class, true)) {
                continue;
            }

            $branchesForValidator = $validator instanceof ConditionalPropertyValidator
                ? $validator->getConditionBranches()
                : $validator->getComposedProperties();

            $totalBranches = count($branchesForValidator);
            $resolvedPropertiesCallbacks = 0;
            $seenBranchPropertyNames = [];

            foreach ($validator->getComposedProperties() as $composedProperty) {
                $composedProperty->onResolve(function () use (
                    $composedProperty,
                    $property,
                    $validator,
                    $branchesForValidator,
                    $totalBranches,
                    $schema,
                    &$resolvedPropertiesCallbacks,
                    &$seenBranchPropertyNames,
                ): void {
                    if (!$composedProperty->getNestedSchema()) {
                        throw new SchemaException(
                            sprintf(
                                "No nested schema for composed property %s in file %s found",
                                $property->getName(),
                                $property->getJsonSchema()->getFile(),
                            )
                        );
                    }

                    $isBranchForValidator = in_array($composedProperty, $branchesForValidator, true);

                    $composedProperty->getNestedSchema()->onAllPropertiesResolved(
                        function () use (
                            $composedProperty,
                            $validator,
                            $isBranchForValidator,
                            $totalBranches,
                            $schema,
                            &$resolvedPropertiesCallbacks,
                            &$seenBranchPropertyNames,
                        ): void {
                            foreach ($composedProperty->getNestedSchema()->getProperties() as $branchProperty) {
                                $schema->addProperty(
                                    $this->cloneTransferredProperty(
                                        $branchProperty,
                                        $composedProperty,
                                        $validator,
                                    ),
                                    $validator->getCompositionProcessor(),
                                );

                                $composedProperty->appendAffectedObjectProperty($branchProperty);
                                $seenBranchPropertyNames[$branchProperty->getName()] = true;
                            }

                            if ($isBranchForValidator && ++$resolvedPropertiesCallbacks === $totalBranches) {
                                foreach (array_keys($seenBranchPropertyNames) as $branchPropertyName) {
                                    $schema->getPropertyMerger()->checkForTotalConflict(
                                        $branchPropertyName,
                                        $totalBranches,
                                    );
                                }
                            }
                        },
                    );
                });
            }
        }
    }

    /**
     * Clone the provided property to transfer it to a schema. Sets the nullability and required
     * flag based on the composition processor used to set up the composition. Widens the type to
     * mixed when the property is exclusive to one anyOf/oneOf branch and at least one other branch
     * allows additional properties, preventing TypeError when raw input values of an arbitrary
     * type are stored in the property slot.
     */
    private function cloneTransferredProperty(
        PropertyInterface $property,
        CompositionPropertyDecorator $sourceBranch,
        AbstractComposedPropertyValidator $validator,
    ): PropertyInterface {
        $compositionProcessor = $validator->getCompositionProcessor();

        $transferredProperty = (clone $property)
            ->filterValidators(static fn(Validator $v): bool =>
                is_a($v->getValidator(), PropertyTemplateValidator::class));

        if (!is_a($compositionProcessor, AllOfProcessor::class, true)) {
            $transferredProperty->setRequired(false);

            if ($transferredProperty->getType()) {
                $transferredProperty->setType(
                    new PropertyType($transferredProperty->getType()->getNames(), true),
                    new PropertyType($transferredProperty->getType(true)->getNames(), true),
                );
            }

            $wideningBranches = $validator instanceof ConditionalPropertyValidator
                ? $validator->getConditionBranches()
                : $validator->getComposedProperties();

            if ($this->exclusiveBranchPropertyNeedsWidening($property->getName(), $sourceBranch, $wideningBranches)) {
                $transferredProperty->setType(null, null, reset: true);
            }
        }

        return $transferredProperty;
    }

    /**
     * Returns true when the property named $propertyName is exclusive to $sourceBranch and at
     * least one other anyOf/oneOf branch allows additional properties (i.e. does NOT declare
     * additionalProperties: false). In that case the property slot can receive an
     * arbitrarily-typed raw input value from a non-matching branch, so the type hint is removed.
     *
     * Returns false when the property appears in another branch too (Schema::addProperty handles
     * that via type merging) or when all other branches have additionalProperties: false (making
     * the property mutually exclusive with the other branches' properties).
     *
     * @param CompositionPropertyDecorator[] $allBranches
     */
    private function exclusiveBranchPropertyNeedsWidening(
        string $propertyName,
        CompositionPropertyDecorator $sourceBranch,
        array $allBranches,
    ): bool {
        foreach ($allBranches as $branch) {
            if ($branch === $sourceBranch) {
                continue;
            }

            $branchPropertyNames = $branch->getNestedSchema()
                ? array_map(
                    static fn(PropertyInterface $p): string => $p->getName(),
                    $branch->getNestedSchema()->getProperties(),
                )
                : [];

            if (in_array($propertyName, $branchPropertyNames, true)) {
                return false;
            }
        }

        foreach ($allBranches as $branch) {
            if ($branch === $sourceBranch) {
                continue;
            }

            if (($branch->getBranchSchema()->getJson()['additionalProperties'] ?? true) !== false) {
                return true;
            }
        }

        return false;
    }

    private function getTargetFileName(string $classPath, string $className): string
    {
        return join(
            DIRECTORY_SEPARATOR,
            array_filter([$this->destination, str_replace('\\', DIRECTORY_SEPARATOR, $classPath), $className]),
        ) . '.php';
    }
}
