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
use PHPModelGenerator\SchemaProcessor\Hook\ConstructorBeforeValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;

class PatternPropertiesPostProcessor extends PostProcessor
{
    /**
     * @throws SchemaException
     */
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $json = $schema->getJsonSchema()->getJson();

        $patternHashes = [];
        $schemaProperties = array_keys($json['properties'] ?? []);

        foreach ($schema->getPatternPropertiesValidators() as $validator) {
            if (array_key_exists($validator->getKey(), $patternHashes)) {
                $key = $json['patternProperties'][$validator->getPattern()]['key'] ?? $validator->getPattern();

                throw new SchemaException(
                    "Duplicate pattern property access key '$key' in file {$schema->getJsonSchema()->getFile()}",
                    $schema->getJsonSchema(),
                );
            }

            $patternHashes[$validator->getKey()] = array_reduce(
                $schema->getProperties(),
                static function (
                    array $carry,
                    PropertyInterface $property,
                ) use (
                    $schemaProperties,
                    $validator
                ): array {
                    if (
                        in_array($property->getName(), $schemaProperties) &&
                        preg_match('/' . addcslashes($validator->getPattern(), '/') . '/', $property->getName())
                    ) {
                        $carry[] = $property;
                    }

                    return $carry;
                },
                [],
            );
        }

        // Driven by the validators rather than by the schema's own `patternProperties` keyword: a
        // base-level `$ref` transfers the referenced schema's base validators onto the referencing
        // schema, so a PatternPropertiesValidator can run on a class whose own JSON never mentions
        // the keyword. The field it writes has to follow the validator, otherwise the generated
        // class creates `$_patternProperties` dynamically — deprecated since PHP 8.2 and an error
        // in PHP 9. Keying off the keyword also kept the fields off every unrelated class, which
        // the emptiness check preserves.
        //
        // A class that only *reads* the field without any validator writing it (the pattern
        // properties accessor of a schema whose patterns are all boolean) declares it itself, see
        // declareCollectionProperty().
        if ($patternHashes === []) {
            return;
        }

        $this->initObjectPropertiesMatchingPatternProperties($schema, $patternHashes);

        self::declareCollectionProperty($schema, array_keys($patternHashes));
        $this->addPatternPropertiesMapProperty($schema);
    }

    /**
     * Adds an internal array property to the schema which holds all pattern properties grouped by key.
     *
     * Public so that generated code which reads the field without a validator writing it can declare it as well:
     * whoever emits code that reads `$_patternProperties` must make sure the class declares it.
     *
     * @param string[] $patternHashes
     *
     * @throws SchemaException
     */
    public static function declareCollectionProperty(
        Schema $schema,
        array $patternHashes,
    ): void {
        $schema->addProperty(
            (new Property(
                'patternProperties',
                new PropertyType('array'),
                new JsonSchema(__FILE__, []),
                'Collect all pattern properties provided to the schema grouped by hashed pattern',
            ))
                ->setDefaultValue(array_fill_keys($patternHashes, []))
                ->setInternal(true),
        );
    }

    /**
     * Adds an internal array property to the schema which holds all pattern properties grouped by key
     *
     * @throws SchemaException
     */
    private function addPatternPropertiesMapProperty(Schema $schema): void
    {
        $properties = [];

        foreach ($schema->getProperties() as $property) {
            if (!$property->isInternal()) {
                $properties[$property->getName()] = $property->getAttribute(true);
            }
        }

        $schema->addProperty(
            (new Property(
                'patternPropertiesMap',
                new PropertyType('array'),
                new JsonSchema(__FILE__, []),
                'Maps all pattern properties which are also defined properties of the object to their attribute',
            ))
                ->setDefaultValue($properties)
                ->setInternal(true),
        );
    }

    /**
     * The internal array $_patternProperties keeps track of all properties matching a pattern. To track properties
     * which not only match a pattern but are also properties of the object (eg. the pattern is "^n" and the object
     * contains a property "name") initialize the corresponding field for each matching property in the array with a
     * reference to the object attribute representing the property (in the example case reference "$this->name").
     */
    private function initObjectPropertiesMatchingPatternProperties(Schema $schema, array $patternHashes): void
    {
        $schema->addSchemaHook(new class ($patternHashes) implements ConstructorBeforeValidationHookInterface {
            public function __construct(private readonly array $patternHashes)
            {}

            public function getCode(): string
            {
                $code = '';

                foreach ($this->patternHashes as $hash => $matchingProperties) {
                    if (empty($matchingProperties)) {
                        continue;
                    }

                    foreach ($matchingProperties as $matchingProperty) {
                        $code .= sprintf(
                            '$this->_patternProperties["%s"]["%s"] = &$this->%s;' . PHP_EOL,
                            $hash,
                            $matchingProperty->getName(),
                            $matchingProperty->getAttribute(true),
                        );
                    }
                }

                return $code;
            }
        });
    }
}
