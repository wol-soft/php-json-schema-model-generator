<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPModelGenerator\Accessor\ImmutableUnevaluatedPropertiesAccessor;
use PHPModelGenerator\Accessor\UnevaluatedPropertiesAccessor;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\Object\MinPropertiesException;
use PHPModelGenerator\Exception\Object\RegularPropertyAsUnevaluatedPropertyException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\UnevaluatedPropertiesValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\ArrayTypeHintDecorator;
use PHPModelGenerator\SchemaProcessor\Hook\SchemaHookResolver;
use PHPModelGenerator\Utils\RenderHelper;
use ReflectionClass;

/**
 * Renders the `unevaluatedProperties()` accessor method on schemas declaring
 * `unevaluatedProperties: <true | schema>` where `additionalProperties` does not already claim
 * every extra key at the same level.
 *
 * Mirrors AdditionalPropertiesAccessorPostProcessor: emits a public getter that returns a
 * cached accessor instance (either the bare production-library class for untyped schemas, or a
 * generated companion class with narrowed types for typed schemas), plus private mutator shims
 * `_setUnevaluatedProperty` / `_removeUnevaluatedProperty` invoked by the accessor's closures.
 *
 * Emission policy:
 *   - additionalProperties absent (denyAdditionalProperties off) / additionalProperties: true → emit
 *   - additionalProperties: false                                                                → skip
 *   - additionalProperties: {schema}                                                             → skip
 * In the skip cases, additionalProperties already either rejects every extra key or claims and
 * validates it, so _unevaluatedProperties would always be empty — exposing an accessor would
 * be misleading. Skipping is total: no backing field, no accessor method, no shims, no
 * companion, no setCollectUnevaluatedProperties(true) call. The validator continues to run as
 * a pure assertion.
 */
class UnevaluatedPropertiesAccessorPostProcessor extends PostProcessor
{
    use CompanionGeneratorTrait;

    /**
     * Add the unevaluatedProperties() accessor method to the provided schema.
     *
     * @throws SchemaException
     */
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        if (!$this->shouldEmitAccessor($schema, $generatorConfiguration)) {
            return;
        }

        $validator = $this->locateUnevaluatedValidator($schema);
        if ($validator === null) {
            return;
        }

        $validator->setCollectUnevaluatedProperties(true);
        $validationProperty = $validator->getValidationProperty();

        $this->addBackingField($schema, $validationProperty);
        $this->addAccessorCacheField($schema);

        $hasCompanion = $validationProperty->getType() !== null;
        $isImmutable = $generatorConfiguration->isImmutable();

        $this->addAccessorMethod($schema, $generatorConfiguration, $hasCompanion);

        if (!$isImmutable) {
            $this->addSetUnevaluatedPropertyMethod($schema, $generatorConfiguration, $validationProperty);
            $this->addRemoveUnevaluatedPropertyMethod($schema, $generatorConfiguration);
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
     * Render the accessor iff unevaluatedProperties is declared as a non-false value AND
     * additionalProperties at the same level does not already claim every extra.
     */
    private function shouldEmitAccessor(Schema $schema, GeneratorConfiguration $generatorConfiguration): bool
    {
        $json = $schema->getJsonSchema()->getJson();

        // unevaluatedProperties: false produces a NoUnevaluatedPropertiesValidator with no
        // backing field — there is nothing to expose.
        if (!array_key_exists('unevaluatedProperties', $json) || $json['unevaluatedProperties'] === false) {
            return false;
        }

        // additionalProperties: false / {schema} both leave _unevaluatedProperties permanently
        // empty (the first rejects every extra, the second validates and claims every extra) —
        // exposing an accessor with no possible contents would be misleading.
        if (array_key_exists('additionalProperties', $json) && $json['additionalProperties'] !== true) {
            return false;
        }

        // denyAdditionalProperties() flips a missing additionalProperties to false → still dead.
        if (!array_key_exists('additionalProperties', $json) && $generatorConfiguration->denyAdditionalProperties()) {
            return false;
        }

        return true;
    }

    /**
     * The factory attaches the UnevaluatedPropertiesValidator via
     * Schema::addPostCompositionValidator() — not addBaseValidator() — because spec ordering
     * requires the unevaluated check to run after every adjacent composition has had a chance
     * to claim keys. The accessor post processor must look in the same bucket.
     */
    private function locateUnevaluatedValidator(Schema $schema): ?UnevaluatedPropertiesValidator
    {
        foreach ($schema->getPostCompositionValidators() as $validator) {
            if ($validator instanceof UnevaluatedPropertiesValidator) {
                return $validator;
            }
        }

        return null;
    }

    /**
     * Recursively walks every composition branch on the schema and harvests the property
     * names and pattern regexes those branches declare. A key matching either set must not be
     * routed through the unevaluated accessor because it belongs to a composition contract
     * (a typed inline branch member or a pattern-matched branch property), not to the
     * unevaluated bucket.
     *
     * Walks composition validators registered on the schema's base validators and recurses
     * through nested allOf/anyOf/oneOf/if-then-else by inspecting each branch's raw JSON.
     *
     * @return array{0: string[], 1: string[]} [propertyNames, patternRegexes]
     */
    private function harvestCompositionPropertyNames(Schema $schema): array
    {
        $propertyNames = [];
        $patternRegexes = [];

        foreach ($schema->getBaseValidators() as $baseValidator) {
            if ($baseValidator instanceof AbstractComposedPropertyValidator) {
                foreach ($baseValidator->getComposedProperties() as $branch) {
                    $this->collectBranchPropertyNames(
                        $branch->getBranchSchema()->getJson(),
                        $propertyNames,
                        $patternRegexes,
                    );
                }
            }
        }

        return [array_values(array_unique($propertyNames)), array_values(array_unique($patternRegexes))];
    }

    /**
     * Walks a single branch's raw JSON schema and appends its `properties` keys and
     * `patternProperties` regexes to the accumulators. Recurses into nested composition
     * keywords because a branch may itself contain allOf/anyOf/oneOf whose sub-branches
     * declare further properties.
     *
     * @param string[] $propertyNames   accumulator for collected property names
     * @param string[] $patternRegexes  accumulator for collected pattern regexes
     */
    private function collectBranchPropertyNames(array $branchJson, array &$propertyNames, array &$patternRegexes): void
    {
        foreach (array_keys($branchJson['properties'] ?? []) as $name) {
            $propertyNames[] = (string) $name;
        }

        foreach (array_keys($branchJson['patternProperties'] ?? []) as $pattern) {
            $patternRegexes[] = (string) $pattern;
        }

        foreach (['allOf', 'anyOf', 'oneOf'] as $compositionKey) {
            foreach ($branchJson[$compositionKey] ?? [] as $nestedBranchJson) {
                if (is_array($nestedBranchJson)) {
                    $this->collectBranchPropertyNames($nestedBranchJson, $propertyNames, $patternRegexes);
                }
            }
        }

        foreach (['if', 'then', 'else'] as $conditionalKey) {
            if (isset($branchJson[$conditionalKey]) && is_array($branchJson[$conditionalKey])) {
                $this->collectBranchPropertyNames($branchJson[$conditionalKey], $propertyNames, $patternRegexes);
            }
        }
    }

    /**
     * @throws SchemaException
     */
    private function addBackingField(Schema $schema, PropertyInterface $validationProperty): void
    {
        $backingField = (new Property(
            'unevaluatedProperties',
            new PropertyType('array'),
            new JsonSchema(__FILE__, []),
            'Collect all unevaluated properties provided to the schema',
        ))
            ->setDefaultValue([])
            ->setInternal(true);

        if ($validationProperty->getType()) {
            $backingField->addTypeHintDecorator(new ArrayTypeHintDecorator($validationProperty));
        }

        $schema->addProperty($backingField);
        $schema->addRollbackProperty('_unevaluatedProperties');
    }

    /**
     * @throws SchemaException
     */
    private function addAccessorCacheField(Schema $schema): void
    {
        $schema->addProperty(
            (new Property(
                'unevaluatedPropertiesAccessor',
                null,
                $schema->getJsonSchema(),
                'Cached accessor instance for unevaluated properties',
            ))
                ->setDefaultValue('null', true)
                ->setInternal(true),
        );
        $schema->addAccessorCacheProperty('_unevaluatedPropertiesAccessor');
    }

    private function addAccessorMethod(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        bool $hasCompanion,
    ): void {
        $isImmutable = $generatorConfiguration->isImmutable();

        if (!$hasCompanion) {
            $schema->addUsedClass($isImmutable
                ? ImmutableUnevaluatedPropertiesAccessor::class
                : UnevaluatedPropertiesAccessor::class);
        }

        $accessorType = $hasCompanion
            ? $schema->getClassName() . 'UnevaluatedProperties'
            : (new ReflectionClass($isImmutable
                ? ImmutableUnevaluatedPropertiesAccessor::class
                : UnevaluatedPropertiesAccessor::class))->getShortName();

        $schema->addMethod(
            'unevaluatedProperties',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'UnevaluatedProperties/UnevaluatedPropertiesAccessorMethod.phptpl',
                [
                    'accessorType' => $accessorType,
                    'immutable' => $isImmutable,
                ],
            ),
        );
    }

    private function addSetUnevaluatedPropertyMethod(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        PropertyInterface $validationProperty,
    ): void {
        $directProperties = array_map(
            static fn(PropertyInterface $property): string => $property->getName(),
            array_filter(
                $schema->getProperties(),
                static fn(PropertyInterface $property): bool => !$property->isInternal(),
            ),
        );

        $directPatterns = array_keys($schema->getJsonSchema()->getJson()['patternProperties'] ?? []);

        // A composition branch's `properties` / `patternProperties` declarations contribute keys
        // to the evaluated set at runtime (when the branch succeeds). Setting such a key via
        // unevaluatedProperties()->set() would bypass the branch's own type/constraint
        // validation, so the shim must reject those keys with the same exception used for
        // directly-declared properties.
        [$compositionProperties, $compositionPatterns] = $this->harvestCompositionPropertyNames($schema);

        $objectProperties = array_values(array_unique(array_merge($directProperties, $compositionProperties)));
        $patternProperties = array_values(array_unique(array_merge($directPatterns, $compositionPatterns)));

        $hasObjectProperties = $objectProperties !== [];
        $hasPatternProperties = $patternProperties !== [];

        if ($hasObjectProperties || $hasPatternProperties) {
            $schema->addUsedClass(RegularPropertyAsUnevaluatedPropertyException::class);
        }

        $schema->addMethod(
            '_setUnevaluatedProperty',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'UnevaluatedProperties/SetUnevaluatedProperty.phptpl',
                [
                    'validationProperty' => $validationProperty,
                    'hasObjectProperties' => $hasObjectProperties,
                    'objectProperties' => RenderHelper::varExportArray($objectProperties),
                    'hasPatternProperties' => $hasPatternProperties,
                    'patternProperties' => $hasPatternProperties
                        ? RenderHelper::varExportPcrePatterns($patternProperties)
                        : null,
                    'schemaHookResolver' => new SchemaHookResolver($schema),
                ],
            ),
        );
    }

    /**
     * @throws SchemaException
     */
    private function addRemoveUnevaluatedPropertyMethod(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
    ): void {
        $minPropertyValidator = null;
        $json = $schema->getJsonSchema()->getJson();
        if (isset($json['minProperties'])) {
            $minPropertyValidator = new PropertyValidator(
                new Property($schema->getClassName(), null, $schema->getJsonSchema()),
                sprintf(
                    '($updatedPropertiesCount = count($this->_rawModelDataInput) - 1) < %d',
                    $json['minProperties'],
                ),
                MinPropertiesException::class,
                [$json['minProperties'], '&$updatedPropertiesCount'],
            );
        }

        $schema->addMethod(
            '_removeUnevaluatedProperty',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'UnevaluatedProperties/RemoveUnevaluatedProperty.phptpl',
                ['minPropertyValidator' => $minPropertyValidator],
            ),
        );
    }

    /**
     * @throws FileSystemException
     */
    protected function renderCompanionFromEntry(array $entry): void
    {
        $this->renderCompanionClass(
            $entry['schema'],
            $entry['generatorConfiguration'],
            $entry['validationProperty'],
        );
    }

    /**
     * @throws FileSystemException
     */
    private function renderCompanionClass(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        PropertyInterface $validationProperty,
    ): void {
        $renderHelper = new RenderHelper($generatorConfiguration);
        $isImmutable = $generatorConfiguration->isImmutable();
        $companionClassName = $schema->getClassName() . 'UnevaluatedProperties';

        $namespace = $this->resolveCompanionNamespace($schema, $generatorConfiguration);

        // get() always returns null when the key is absent, so the companion's get() return
        // type must allow null in addition to the schema-declared type.
        $nullableProperty = (clone $validationProperty)->setType(
            $validationProperty->getType(),
            new PropertyType($validationProperty->getType(true)->getNames(), true),
        );

        // The companion is a stand-alone class that mirrors the production-library accessor's
        // shape with narrowed types — it does not extend the base. This matches the pattern in
        // AdditionalPropertiesCompanion.phptpl. The base remains the fallback when no typed
        // companion is generated.
        $use = RenderHelper::filterClassImports(
            $isImmutable ? [] : ['Closure'],
            $namespace,
        );

        $this->writeAndRequireCompanionFile(
            $schema,
            $companionClassName,
            $namespace,
            join(DIRECTORY_SEPARATOR, ['Companion', 'UnevaluatedPropertiesCompanion.phptpl']),
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
