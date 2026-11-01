<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Any;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\BaseProperty;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\EnumValidator;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintDecorator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\JsonValue;
use PHPModelGenerator\Utils\TypeConverter;

class EnumValidatorFactory extends AbstractValidatorFactory
{
    /**
     * @throws SchemaException
     */
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $json = $propertySchema->getJson();

        if (!isset($json[$this->key])) {
            return;
        }

        $allowedValues = $json[$this->key];

        if (empty($allowedValues)) {
            throw new SchemaException(
                sprintf(
                    "Empty enum property %s in file %s",
                    $property->getName(),
                    $propertySchema->getFile(),
                ),
                $propertySchema,
            );
        }

        $allowedValues = JsonValue::unique($allowedValues);

        // no type information provided - inherit the types from the enum values. A property which transforms the
        // provided value (eg. into the model of a nested class) stays untyped: the property holds the transformed
        // value, the enum compares the provided data.
        if (!$property->getType() && $property->getDecorators() === []) {
            $typesOfEnum = array_unique(array_map(
                static fn($value): string => TypeConverter::gettypeToInternal(gettype($value)),
                $allowedValues,
            ));

            if (count($typesOfEnum) === 1) {
                $property->setType(new PropertyType($typesOfEnum[0]));
            } else {
                $hasNull = in_array('null', $typesOfEnum, true);
                $nonNullTypes = array_values(array_filter(
                    $typesOfEnum,
                    static fn(string $type): bool => $type !== 'null',
                ));

                if ($nonNullTypes) {
                    $propertyType = new PropertyType($nonNullTypes, $hasNull ? true : null);
                    $property->setType($propertyType, $propertyType);
                }
            }

            $property->addTypeHintDecorator(new TypeHintDecorator($typesOfEnum));
        }

        $allowedValues = JsonValue::alignNumbers($allowedValues, $property->getType());

        if (array_key_exists('default', $json)) {
            $default = JsonValue::alignNumbers([$json['default']], $property->getType())[0];

            if (!JsonValue::contains($allowedValues, $default)) {
                throw new SchemaException(
                    sprintf(
                        "Invalid default value %s for enum property %s in file %s",
                        var_export($json['default'], true),
                        $property->getName(),
                        $propertySchema->getFile(),
                    ),
                    $propertySchema,
                );
            }
        }

        $implicitNullAllowed = $schemaProcessor->getGeneratorConfiguration()->isImplicitNullAllowed()
            && !$property->isRequired();

        if ($implicitNullAllowed && !in_array(null, $allowedValues, true)) {
            $allowedValues[] = null;
        }

        $validator = (new EnumValidator($property, $allowedValues))->withJsonPointer(
            $propertySchema->getPointer() . '/enum',
        );

        // The enum of the schema of a class (the root of a schema file) constrains the object itself. No property of
        // a class holds the object, so the validator is rendered with the validators of the object. It compares the
        // data of the whole object: as a post composition validator it validates the state of the object after an
        // update. An object level validator (eg. maxProperties) only receives the updated key of an update.
        if ($property instanceof BaseProperty) {
            $schema->addPostCompositionValidator($validator);

            return;
        }

        $property->addValidator($validator, 3);
    }
}
