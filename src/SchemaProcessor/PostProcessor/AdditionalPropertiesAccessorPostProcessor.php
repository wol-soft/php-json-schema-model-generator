<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPModelGenerator\Accessor\AdditionalPropertiesAccessor;
use PHPModelGenerator\Accessor\ImmutableAdditionalPropertiesAccessor;
use PHPModelGenerator\Attributes\JsonPointer;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\Object\MinPropertiesException;
use PHPModelGenerator\Exception\Object\RegularPropertyAsAdditionalPropertyException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\AdditionalPropertiesValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\SchemaProcessor\Hook\SchemaHookResolver;
use PHPModelGenerator\SchemaProcessor\PostProcessor\Internal\AdditionalPropertiesPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\Internal\SerializationPostProcessor;
use PHPModelGenerator\Utils\RenderHelper;
use ReflectionClass;

class AdditionalPropertiesAccessorPostProcessor extends PostProcessor
{
    use CompanionGeneratorTrait;

    /**
     * @param bool $addForModelsWithoutAdditionalPropertiesDefinition By default, the accessor is added only to schemas
     *             that declare an additionalProperties constraint. Set to true to also add it to schemas that don't.
     */
    public function __construct(private readonly bool $addForModelsWithoutAdditionalPropertiesDefinition = false)
    {}

    /**
     * Add the additionalProperties() accessor method to the provided schema.
     *
     * @throws SchemaException
     */
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $json = $schema->getJsonSchema()->getJson();

        if (
            (!$this->addForModelsWithoutAdditionalPropertiesDefinition && !isset($json['additionalProperties']))
            || (isset($json['additionalProperties']) && $json['additionalProperties'] === false)
            || (!isset($json['additionalProperties']) && $generatorConfiguration->denyAdditionalProperties())
        ) {
            return;
        }

        $validationProperty = null;
        foreach ($schema->getBaseValidators() as $validator) {
            if (is_a($validator, AdditionalPropertiesValidator::class)) {
                $validationProperty = $validator->getValidationProperty();
            }
        }

        // When the flag is set and no additionalProperties is defined in the schema, ensure the infrastructure exists.
        if ($this->addForModelsWithoutAdditionalPropertiesDefinition && !isset($json['additionalProperties'])) {
            (new AdditionalPropertiesPostProcessor())->addAdditionalPropertiesCollectionProperty($schema);
        }
        if (
            $generatorConfiguration->hasSerializationEnabled() &&
            $this->addForModelsWithoutAdditionalPropertiesDefinition &&
            !isset($json['additionalProperties'])
        ) {
            (new SerializationPostProcessor())->addAdditionalPropertiesTransformingFilterSerializer(
                $schema,
                $generatorConfiguration,
            );
        }

        $hasCompanion = $validationProperty && $validationProperty->getType();
        $isImmutable = $generatorConfiguration->isImmutable();

        $schema->addProperty(
            (new Property(
                'additionalPropertiesAccessor',
                null,
                $schema->getJsonSchema(),
                'Cached accessor instance for additional properties',
            ))
                ->setDefaultValue('null', true)
                ->setInternal(true),
        );
        $schema->addAccessorCacheProperty('_additionalPropertiesAccessor');

        $this->addAccessorMethod($schema, $generatorConfiguration, $hasCompanion);

        if (!$isImmutable) {
            $this->addSetAdditionalPropertyMethod($schema, $generatorConfiguration, $validationProperty);
            $this->addRemoveAdditionalPropertyMethod($schema, $generatorConfiguration);
        }

        if ($hasCompanion) {
            $this->pendingCompanions[] = [
                'schema' => $schema,
                'generatorConfiguration' => $generatorConfiguration,
                'validationProperty' => $validationProperty,
            ];
        }
    }

    /**
     * @throws FileSystemException
     */
    protected function renderCompanionFromEntry(array $entry): void
    {
        $this->renderCompanionClass($entry['schema'], $entry['generatorConfiguration'], $entry['validationProperty']);
    }

    private function addAccessorMethod(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        bool $hasCompanion,
    ): void {
        $isImmutable = $generatorConfiguration->isImmutable();

        if (!$hasCompanion) {
            $schema->addUsedClass($isImmutable
                ? ImmutableAdditionalPropertiesAccessor::class
                : AdditionalPropertiesAccessor::class);
        }

        $accessorType = $hasCompanion
            ? $schema->getClassName() . 'AdditionalProperties'
            : (new ReflectionClass($isImmutable
                ? ImmutableAdditionalPropertiesAccessor::class
                : AdditionalPropertiesAccessor::class))->getShortName();

        $schema->addMethod(
            'additionalProperties',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'AdditionalProperties/AdditionalPropertiesAccessorMethod.phptpl',
                [
                    'accessorType' => $accessorType,
                    'immutable' => $isImmutable,
                ],
            )
        );
    }

    private function addSetAdditionalPropertyMethod(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        ?PropertyInterface $validationProperty,
    ): void {
        $nonInternalProperties = array_filter(
            $schema->getProperties(),
            static fn(PropertyInterface $property): bool => !$property->isInternal(),
        );

        $objectProperties = array_map(
            static fn(PropertyInterface $property): string => $property->getName(),
            $nonInternalProperties,
        );

        $objectPropertyPointers = array_combine(
            array_map(static fn(PropertyInterface $property): string => $property->getName(), $nonInternalProperties),
            array_map(
                static fn(PropertyInterface $property): string => self::resolvePrimaryJsonPointer($property),
                $nonInternalProperties,
            ),
        );

        $hasObjectProperties = $objectProperties !== [];
        if ($hasObjectProperties) {
            $schema->addUsedClass(RegularPropertyAsAdditionalPropertyException::class);
        }

        $schema->addMethod(
            '_setAdditionalProperty',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'AdditionalProperties/SetAdditionalProperty.phptpl',
                [
                    'validationProperty' => $validationProperty,
                    'hasObjectProperties' => $hasObjectProperties,
                    'objectProperties' => RenderHelper::varExportArray($objectProperties),
                    'objectPropertyPointers' => RenderHelper::varExportArray($objectPropertyPointers),
                    'schemaHookResolver' => new SchemaHookResolver($schema),
                ],
            )
        );
    }

    /**
     * Resolve the pointer to report when a property name collides with a regular property.
     *
     * A property merged from multiple composition branches (e.g. declared in both the root
     * properties block and an allOf branch) carries one #[JsonPointer] attribute per defining
     * location, synthesized by PropertyAttributeSynthesizer. Reading that attribute data ties
     * this pointer to the same single source of truth used for the generated #[JsonPointer]
     * attributes, instead of independently recomputing it from
     * $property->getJsonSchema()->getPointer() — a second computation that would silently
     * diverge from the attribute data if PropertyMerger's choice of JsonSchema ever changes.
     *
     * RegularPropertyAsAdditionalPropertyException carries a single pointer, so when a property
     * has multiple declaration sites only the first (root-preferred) one is reported — knowing
     * any one true location is sufficient to explain the name collision.
     */
    private static function resolvePrimaryJsonPointer(PropertyInterface $property): string
    {
        foreach ($property->getAttributes() as $attribute) {
            if ($attribute->getFqcn() === JsonPointer::class) {
                return (string) $attribute->getArguments()[0];
            }
        }

        return $property->getJsonSchema()->getPointer();
    }

    /**
     * @throws SchemaException
     */
    private function addRemoveAdditionalPropertyMethod(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
    ): void {
        $minPropertyValidator = null;
        $json = $schema->getJsonSchema()->getJson();
        if (isset($json['minProperties'])) {
            $minPropertyValidator = (new PropertyValidator(
                new Property($schema->getClassName(), null, $schema->getJsonSchema()),
                sprintf(
                    '($updatedPropertiesCount = count($this->_rawModelDataInput) - 1) < %d',
                    $json['minProperties'],
                ),
                MinPropertiesException::class,
                [$json['minProperties'], '&$updatedPropertiesCount'],
            ))->withJsonPointer($schema->getJsonSchema()->getPointer() . '/minProperties');
        }

        $schema->addMethod(
            '_removeAdditionalProperty',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'AdditionalProperties/RemoveAdditionalProperty.phptpl',
                ['minPropertyValidator' => $minPropertyValidator],
            )
        );
    }

    /**
     * Render and write the typed companion class for the given schema.
     *
     * @throws FileSystemException
     */
    private function renderCompanionClass(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        PropertyInterface $validationProperty,
    ): void {
        $renderHelper = new RenderHelper($generatorConfiguration);
        $isImmutable = $generatorConfiguration->isImmutable();
        $companionClassName = $schema->getClassName() . 'AdditionalProperties';

        $namespace = $this->resolveCompanionNamespace($schema, $generatorConfiguration);

        // Nullable clone for get() — the property might not exist, so null is always possible.
        $nullableProperty = (clone $validationProperty)->setType(
            $validationProperty->getType(),
            new PropertyType($validationProperty->getType(true)->getNames(), true),
        );

        $use = RenderHelper::filterClassImports(
            $isImmutable ? [] : ['Closure'],
            $namespace,
        );

        $this->writeAndRequireCompanionFile(
            $schema,
            $companionClassName,
            $namespace,
            join(DIRECTORY_SEPARATOR, ['Companion', 'AdditionalPropertiesCompanion.phptpl']),
            [
                'namespace' => $namespace,
                'use' => $use,
                'companionClassName' => $companionClassName,
                'immutable' => $isImmutable,
                'getReturnType' => $renderHelper->getTypeHintAnnotation($nullableProperty, true),
                'getNullablePhpType' => $renderHelper->getType($nullableProperty, true),
                'getAllReturnAnnotation' => $this->buildGetAllReturnAnnotation(
                    $renderHelper->getTypeHintAnnotation($validationProperty, true),
                ),
                'setParameterType' => $renderHelper->getType($validationProperty),
                'setParameterAnnotation' => $renderHelper->getTypeHintAnnotation($validationProperty),
            ],
        );
    }

    private function buildGetAllReturnAnnotation(string $typeAnnotation): string
    {
        // Wrap union types in parentheses so that e.g. 'DateTime|null' becomes '(DateTime|null)[]',
        // not 'DateTime[]|null[]' — the latter means "an array of nulls" to static analysers.
        if (str_contains($typeAnnotation, '|')) {
            return '(' . $typeAnnotation . ')[]';
        }

        return $typeAnnotation . '[]';
    }
}
