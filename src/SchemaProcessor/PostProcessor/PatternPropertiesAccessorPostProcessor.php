<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPModelGenerator\Accessor\PatternPropertiesAccessor;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\Object\UnknownPatternPropertyException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\PatternPropertiesValidator;
use PHPModelGenerator\Utils\RenderHelper;
use ReflectionClass;

class PatternPropertiesAccessorPostProcessor extends PostProcessor
{
    use CompanionGeneratorTrait;

    /**
     * Add the patternProperties() accessor method to the provided schema.
     *
     * @throws SchemaException
     */
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $json = $schema->getJsonSchema()->getJson();

        if (!isset($json['patternProperties'])) {
            return;
        }

        $patternTypes = [];

        foreach ($schema->getBaseValidators() as $validator) {
            if (is_a($validator, PatternPropertiesValidator::class)) {
                $patternTypes[] = $validator->getValidationProperty()->getType(true);
            }
        }

        $hasCompanion = $this->shouldGenerateCompanion($patternTypes);

        $schema->addProperty(
            (new Property(
                'patternPropertiesAccessor',
                null,
                $schema->getJsonSchema(),
                'Cached accessor instance for pattern properties',
            ))
                ->setDefaultValue('null', true)
                ->setInternal(true),
        );
        $schema->addAccessorCacheProperty('_patternPropertiesAccessor');

        $this->addAccessorMethod($schema, $generatorConfiguration, $hasCompanion);

        if ($hasCompanion) {
            $this->pendingCompanions[] = [
                'schema' => $schema,
                'generatorConfiguration' => $generatorConfiguration,
                'patternTypes' => $patternTypes,
            ];
        }
    }

    /**
     * @throws FileSystemException
     */
    protected function renderCompanionFromEntry(array $entry): void
    {
        $this->renderCompanionClass($entry['schema'], $entry['generatorConfiguration'], $entry['patternTypes']);
    }

    private function addAccessorMethod(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        bool $hasCompanion,
    ): void {
        $accessorType = $hasCompanion
            ? $schema->getClassName() . 'PatternProperties'
            : (new ReflectionClass(PatternPropertiesAccessor::class))->getShortName();

        if (!$hasCompanion) {
            $schema->addUsedClass(PatternPropertiesAccessor::class);
        }

        $schema->addMethod(
            'patternProperties',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'PatternProperties/PatternPropertiesAccessorMethod.phptpl',
                ['accessorType' => $accessorType],
            )
        );
    }

    /**
     * A companion is generated when at least one pattern property type is not the bare mixed/untyped case,
     * giving the get() return annotation a more specific type.
     *
     * @param PropertyType[] $patternTypes
     */
    private function shouldGenerateCompanion(array $patternTypes): bool
    {
        foreach ($patternTypes as $patternType) {
            if ($patternType !== null && $patternType->getNames() !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param PropertyType[] $patternTypes
     *
     * @throws FileSystemException
     */
    private function renderCompanionClass(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        array $patternTypes,
    ): void {
        $companionClassName = $schema->getClassName() . 'PatternProperties';
        $namespace = $this->resolveCompanionNamespace($schema, $generatorConfiguration);

        $use = RenderHelper::filterClassImports(
            [UnknownPatternPropertyException::class],
            $namespace,
        );

        $this->writeAndRequireCompanionFile(
            $schema,
            $companionClassName,
            $namespace,
            join(DIRECTORY_SEPARATOR, ['Companion', 'PatternPropertiesCompanion.phptpl']),
            [
                'namespace' => $namespace,
                'use' => $use,
                'companionClassName' => $companionClassName,
                'getReturnAnnotation' => $this->buildReturnAnnotation($patternTypes),
            ],
        );
    }

    /**
     * @param PropertyType[] $patternTypes
     */
    private function buildReturnAnnotation(array $patternTypes): string
    {
        $baseTypes = array_unique(
            array_merge(
                ...array_map(
                    static fn(PropertyType $type): array => $type->getNames(),
                    array_filter($patternTypes),
                )
            )
        );

        $nullable = array_reduce(
            $patternTypes,
            static fn(bool $carry, ?PropertyType $type): bool => $carry || ($type !== null && $type->isNullable()),
            false,
        );

        if ($nullable) {
            $baseTypes[] = 'null';
        }

        // Wrap union types in parentheses so that e.g. ['DateTime', 'null'] becomes '(DateTime|null)[]',
        // not 'DateTime[]|null[]' — the latter means "an array of nulls" to static analysers.
        if (count($baseTypes) > 1) {
            return '(' . implode('|', $baseTypes) . ')[]';
        }

        return $baseTypes[0] . '[]';
    }
}
