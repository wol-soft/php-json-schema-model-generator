<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPModelGenerator\Exception\Object\UnknownPatternPropertyException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\PatternPropertiesValidator;
use PHPModelGenerator\SchemaProcessor\Hook\ConstructorBeforeValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\Hook\SetterBeforeValidationHookInterface;

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

        $patternHashes = [];
        $schemaProperties = array_keys($json['properties'] ?? []);

        foreach ($schema->getBaseValidators() as $validator) {
            if (is_a($validator, PatternPropertiesValidator::class)) {
                $validator->setCollectPatternProperties(true);

                $key = $json['patternProperties'][$validator->getPattern()]['key'] ?? $validator->getPattern();
                $hash = md5($key);

                if (array_key_exists($hash, $patternHashes)) {
                    throw new SchemaException(
                        "Duplicate pattern property access key '$key' in file {$schema->getJsonSchema()->getFile()}"
                    );
                }

                $patternHashes[$hash] = array_reduce(
                    $schema->getProperties(),
                    function (array $carry, PropertyInterface $property) use ($schemaProperties, $validator): array {
                        if (in_array($property->getName(), $schemaProperties) &&
                            preg_match("/{$validator->getPattern()}/", $property->getName())
                        ) {
                            $carry[] = $property;
                        }

                        return $carry;
                    },
                    []
                );
            }
        }

        $this->initObjectPropertiesMatchingPatternProperties($schema, $patternHashes);
        $this->extendObjectPropertiesMatchingPatternValidation($schema);

        $this->addPatternPropertiesCollectionProperty($schema, array_keys($patternHashes));
        $this->addPatternPropertiesMapProperty($schema);
        $this->addGetPatternPropertiesMethod($schema, $generatorConfiguration);
    }

    /**
     * Adds an internal array property to the schema which holds all pattern properties grouped by key
     *
     * @param Schema $schema
     * @param array $patternHashes
     *
     * @throws SchemaException
     */
    private function addPatternPropertiesCollectionProperty(
        Schema $schema,
        array $patternHashes
    ): void {
        $schema->addProperty(
            (new Property(
                'patternProperties',
                new PropertyType('array'),
                new JsonSchema(__FILE__, []),
                'Collect all pattern properties provided to the schema grouped by hashed pattern'
            ))
                ->setDefaultValue(array_fill_keys($patternHashes, []))
                ->setInternal(true)
        );
    }

    /**
     * Adds an internal array property to the schema which holds all pattern properties grouped by key
     *
     * @param Schema $schema
     */
    private function addPatternPropertiesMapProperty(Schema $schema): void {
        $properties = [];

        foreach ($schema->getProperties() as $property) {
            if (!$property->isInternal()) {
                $properties[$property->getName()] = $property->getAttribute();
            }
        }

        $schema->addProperty(
            (new Property(
                'patternPropertiesMap',
                new PropertyType('array'),
                new JsonSchema(__FILE__, []),
                'Maps all pattern properties which are also defined properties of the object to their attribute'
            ))
                ->setDefaultValue($properties)
                ->setInternal(true)
        );
    }

    /**
     * Adds a method to get a list of pattern properties by property key or pattern
     *
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
     */
    private function addGetPatternPropertiesMethod(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration
    ): void {
        $schema
            ->addUsedClass(UnknownPatternPropertyException::class)
            ->addMethod(
                'getPatternProperties',
                new RenderedMethod(
                    $schema,
                    $generatorConfiguration,
                    'PatternProperties/GetPatternProperties.phptpl'
                )
            );
    }

    /**
     * The internal array $_patternProperties keeps track of all properties matching a pattern. To track properties
     * which not only match a pattern but are also properties of the object (eg. the pattern is "^n" and the object
     * contains a property "name") initialize the corresponding field for each matching property in the array with a
     * reference to the object attribute representing the property (in the example case reference "$this->name").
     *
     * @param Schema $schema
     * @param array $patternHashes
     */
    private function initObjectPropertiesMatchingPatternProperties(Schema $schema, array $patternHashes): void
    {
        $schema->addSchemaHook(new class ($patternHashes) implements ConstructorBeforeValidationHookInterface {
            private $patternHashes;

            public function __construct(array $patternHashes)
            {
                $this->patternHashes = $patternHashes;
            }

            public function getCode(): string
            {
                $code = '';

                foreach ($this->patternHashes as $hash => $matchingProperties) {
                    if (empty($matchingProperties)) {
                        continue;
                    }

                    /** @var PropertyInterface $matchingProperty */
                    foreach ($matchingProperties as $matchingProperty) {
                        $code .= sprintf(
                            '$this->_patternProperties["%s"]["%s"] = &$this->%s;' . PHP_EOL,
                            $hash,
                            $matchingProperty->getName(),
                            $matchingProperty->getAttribute()
                        );
                    }
                }

                return $code;
            }
        });
    }

    /**
     * Each setter call
     * @param Schema $schema
     */
    private function extendObjectPropertiesMatchingPatternValidation(Schema $schema): void
    {
        $schema->addSchemaHook(
            new class (
                array_keys($schema->getJsonSchema()->getJson()['patternProperties'])
            ) implements SetterBeforeValidationHookInterface {
                /** @var array */
                private $pattern;

                public function __construct(array $pattern)
                {
                    $this->pattern = $pattern;
                }

                public function getCode(PropertyInterface $property): string
                {
                    $matchesAnyPattern = false;
                    foreach ($this->pattern as $pattern) {
                        if (preg_match("/$pattern/", $property->getName())) {
                            $matchesAnyPattern = true;
                            break;
                        }
                    }

                    // TODO: extract pattern property validation from the base validator into a separate method and
                    // TODO: call only the pattern property validation at this location to avoid executing unnecessary
                    // TODO: validators
                    return $matchesAnyPattern ? sprintf('
                        if (!isset($hasExecutedBaseValidators)) {
                            $modelData = array_merge($this->_rawModelDataInput, ["%s" => $value]);
                            $this->executeBaseValidators($modelData);
                        }',
                        $property->getName()
                    ) : '';
                }
            }
        );
    }
}
