<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPModelGenerator\Exception\Object\UnknownPatternPropertyException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\PatternPropertiesValidator;

/**
 * Class PatternPropertiesAccessorPostProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor\PostProcessor
 */
class PatternPropertiesAccessorPostProcessor extends PostProcessor
{
    /**
     * Add methods to handle pattern properties
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

        $this->addGetPatternPropertiesMethod($schema, $generatorConfiguration, $patternTypes);
    }

    /**
     * Adds a method to get a list of pattern properties by property key or pattern
     *
     * @param PropertyType[] $patternTypes
     */
    private function addGetPatternPropertiesMethod(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        array $patternTypes,
    ): void {
        $schema
            ->addUsedClass(UnknownPatternPropertyException::class)
            ->addMethod(
                'getPatternProperties',
                new RenderedMethod(
                    $schema,
                    $generatorConfiguration,
                    'PatternProperties/GetPatternProperties.phptpl',
                    [
                        'returnTypeAnnotation' => $this->getReturnTypeAnnotationForGetPatternProperties($patternTypes),
                    ],
                )
            );
    }

    /**
     * @param PropertyType[] $patternTypes
     */
    private function getReturnTypeAnnotationForGetPatternProperties(array $patternTypes): string
    {
        $baseTypes = array_unique(
            array_map(
                static fn(PropertyType $type): string => $type->getName(),
                $patternTypes,
            )
        );

        $nullable = array_reduce(
            $patternTypes,
            static fn(bool $carry, PropertyType $type): bool => $carry || $type->isNullable(),
            false,
        );

        if ($nullable) {
            $baseTypes[] = 'null';
        }

        return join('[]|', $baseTypes) . '[]';
    }
}
