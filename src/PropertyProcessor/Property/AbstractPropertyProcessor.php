<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\BaseProperty;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\EnumValidator;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\PropertyProcessor\ComposedValueProcessorFactory;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintTransferDecorator;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorInterface;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\TypeConverter;

/**
 * Class AbstractPropertyProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
abstract class AbstractPropertyProcessor implements PropertyProcessorInterface
{
    public function __construct(
        protected SchemaProcessor $schemaProcessor,
        protected Schema $schema,
        protected bool $required = false,
    ) {}

    /**
     * Generates the validators for the property
     *
     * @throws SchemaException
     */
    protected function generateValidators(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        if ($property->isRequired() && !str_starts_with($property->getName(), 'item of array ')) {
            $property->addValidator(new RequiredPropertyValidator($property), 1);
        }

        if (isset($propertySchema->getJson()['enum'])) {
            $this->addEnumValidator($property, $propertySchema->getJson()['enum']);
        }

        $this->addComposedValueValidator($property, $propertySchema);
    }

    /**
     * Add a validator to a property which validates the value against a list of allowed values
     *
     * @throws SchemaException
     */
    protected function addEnumValidator(PropertyInterface $property, array $allowedValues): void
    {
        if (empty($allowedValues)) {
            throw new SchemaException(
                sprintf(
                    "Empty enum property %s in file %s",
                    $property->getName(),
                    $property->getJsonSchema()->getFile(),
                )
            );
        }

        $allowedValues = array_unique($allowedValues);

        if (array_key_exists('default', $property->getJsonSchema()->getJson())) {
            if (!in_array($property->getJsonSchema()->getJson()['default'], $allowedValues, true)) {
                throw new SchemaException(
                    sprintf(
                        "Invalid default value %s for enum property %s in file %s",
                        var_export($property->getJsonSchema()->getJson()['default'], true),
                        $property->getName(),
                        $property->getJsonSchema()->getFile(),
                    ),
                );
            }
        }

        // no type information provided - inherit the types from the enum values
        if (!$property->getType()) {
            $typesOfEnum = array_unique(array_map(
                static fn($value): string => TypeConverter::gettypeToInternal(gettype($value)),
                $allowedValues,
            ));

            if (count($typesOfEnum) === 1) {
                $property->setType(new PropertyType($typesOfEnum[0]));
            } else {
                // Multiple types: set a union PropertyType so the native PHP type hint path can
                // emit e.g. string|int instead of falling back to no hint at all.
                // 'NULL' must be expressed as nullable=true rather than kept as a type name.
                $hasNull = in_array('null', $typesOfEnum, true);
                $nonNullTypes = array_values(array_filter(
                    $typesOfEnum,
                    static fn(string $t): bool => $t !== 'null',
                ));

                if ($nonNullTypes) {
                    $propertyType = new PropertyType($nonNullTypes, $hasNull ? true : null);
                    $property->setType($propertyType, $propertyType);
                }
            }
            $property->addTypeHintDecorator(new TypeHintDecorator($typesOfEnum));
        }

        if ($this->isImplicitNullAllowed($property) && !in_array(null, $allowedValues, true)) {
            $allowedValues[] = null;
        }

        $property->addValidator(new EnumValidator($property, $allowedValues), 3);
    }

    /**
     * @throws SchemaException
     */
    protected function addComposedValueValidator(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        // For non-root object-type properties, composition keywords are processed in full
        // inside the nested schema by ObjectProcessor (via processSchema with rootLevelComposition=true).
        // Adding a composition validator here would duplicate the validation at the parent level:
        // by the time this validator runs, $value is already an instantiated object, so branch
        // instanceof checks against branch-specific classes fail, rejecting valid input.
        // It would also inject a _Merged_ class name into the type hint, overriding the correct type.
        if (!($property instanceof BaseProperty) && ($propertySchema->getJson()['type'] ?? '') === 'object') {
            return;
        }

        $composedValueKeywords = ['allOf', 'anyOf', 'oneOf', 'not', 'if'];
        $propertyFactory = new PropertyFactory(new ComposedValueProcessorFactory($property instanceof BaseProperty));

        foreach ($composedValueKeywords as $composedValueKeyword) {
            if (!isset($propertySchema->getJson()[$composedValueKeyword])) {
                continue;
            }

            $propertySchema = $this->inheritPropertyType($propertySchema, $composedValueKeyword);

            $composedProperty = $propertyFactory
                ->create(
                    $this->schemaProcessor,
                    $this->schema,
                    $property->getName(),
                    $propertySchema->withJson([
                        'type' => $composedValueKeyword,
                        'propertySchema' => $propertySchema,
                        'onlyForDefinedValues' => !($this instanceof BaseProcessor) &&
                            (!$property->isRequired()
                                && $this->schemaProcessor->getGeneratorConfiguration()->isImplicitNullAllowed()),
                    ]),
                    $property->isRequired(),
                );

            foreach ($composedProperty->getValidators() as $validator) {
                $property->addValidator($validator->getValidator(), $validator->getPriority());
            }

            $property->addTypeHintDecorator(new TypeHintTransferDecorator($composedProperty));

            if (!$property->getType() && $composedProperty->getType()) {
                $property->setType($composedProperty->getType(), $composedProperty->getType(true));
            }
        }
    }

    /**
     * If the type of a property containing a composition is defined outside of the composition make sure each
     * composition which doesn't define a type inherits the type
     */
    protected function inheritPropertyType(JsonSchema $propertySchema, string $composedValueKeyword): JsonSchema
    {
        $json = $propertySchema->getJson();

        if (!isset($json['type'])) {
            return $propertySchema;
        }

        if ($json['type'] === 'base') {
            $json['type'] = 'object';
        }

        switch ($composedValueKeyword) {
            case 'not':
                if (!isset($json[$composedValueKeyword]['type'])) {
                    $json[$composedValueKeyword]['type'] = $json['type'];
                }
                break;
            case 'if':
                return $this->inheritIfPropertyType($propertySchema->withJson($json));
            default:
                foreach ($json[$composedValueKeyword] as &$composedElement) {
                    if (!isset($composedElement['type'])) {
                        $composedElement['type'] = $json['type'];
                    }
                }
        }

        return $propertySchema->withJson($json);
    }

    /**
     * Inherit the type of a property into all composed components of a conditional composition
     */
    protected function inheritIfPropertyType(JsonSchema $propertySchema): JsonSchema
    {
        $json = $propertySchema->getJson();

        foreach (['if', 'then', 'else'] as $composedValueKeyword) {
            if (!isset($json[$composedValueKeyword])) {
                continue;
            }

            if (!isset($json[$composedValueKeyword]['type'])) {
                $json[$composedValueKeyword]['type'] = $json['type'];
            }
        }

        return $propertySchema->withJson($json);
    }

    /**
     * Check if implicit null values are allowed for the given property (a not required property which has no
     * explicit null type and is passed with a null value will be accepted)
     */
    protected function isImplicitNullAllowed(PropertyInterface $property): bool
    {
        return $this->schemaProcessor->getGeneratorConfiguration()->isImplicitNullAllowed() && !$property->isRequired();
    }
}
