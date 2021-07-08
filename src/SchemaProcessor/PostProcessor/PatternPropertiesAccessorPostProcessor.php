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
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
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
                array_push($patternTypes, $validator->getValidationProperty()->getType(true));
            }
        }

        $this->addGetPatternPropertiesMethod($schema, $generatorConfiguration, $patternTypes);
    }

    /**
     * Adds a method to get a list of pattern properties by property key or pattern
     *
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
     * @param PropertyType[] $patternTypes
     */
    private function addGetPatternPropertiesMethod(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        array $patternTypes
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
                    ]
                )
            );
    }

    /**
     * @param PropertyType[] $patternTypes
     *
     * @return string
     */
    private function getReturnTypeAnnotationForGetPatternProperties(array $patternTypes): string
    {
        $baseTypes = array_unique(array_map(
                function (PropertyType $type): string {
                    return $type->getName();
                },
                $patternTypes)
        );

        $nullable = array_reduce(
            $patternTypes,
            function (bool $carry, PropertyType $type): bool {
                return $carry || $type->isNullable();
            },
            false
        );

        if ($nullable) {
            array_push($baseTypes, 'null');
        }

        return join('[]|', $baseTypes) . '[]';
    }
}
