<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor\Internal;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\PatternPropertiesValidator;
use PHPModelGenerator\SchemaProcessor\Hook\ConstructorBeforeValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;

/**
 * Class PatternPropertiesPostProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor\PostProcessor\Internal
 */
class PatternPropertiesPostProcessor extends PostProcessor
{
    /**
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
                if (array_key_exists($validator->getKey(), $patternHashes)) {
                    $key = $json['patternProperties'][$validator->getPattern()]['key'] ?? $validator->getPattern();

                    throw new SchemaException(
                        "Duplicate pattern property access key '$key' in file {$schema->getJsonSchema()->getFile()}"
                    );
                }

                $patternHashes[$validator->getKey()] = array_reduce(
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

        $this->addPatternPropertiesCollectionProperty($schema, array_keys($patternHashes));
        $this->addPatternPropertiesMapProperty($schema);
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
     *
     * @throws SchemaException
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
}
