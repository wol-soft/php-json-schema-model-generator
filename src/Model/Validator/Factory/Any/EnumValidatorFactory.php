<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Any;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\EnumValidator;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintDecorator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
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
            );
        }

        $allowedValues = array_unique($allowedValues);

        if (array_key_exists('default', $json)) {
            if (!in_array($json['default'], $allowedValues, true)) {
                throw new SchemaException(
                    sprintf(
                        "Invalid default value %s for enum property %s in file %s",
                        var_export($json['default'], true),
                        $property->getName(),
                        $propertySchema->getFile(),
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

        $implicitNullAllowed = $schemaProcessor->getGeneratorConfiguration()->isImplicitNullAllowed()
            && !$property->isRequired();

        if ($implicitNullAllowed && !in_array(null, $allowedValues, true)) {
            $allowedValues[] = null;
        }

        $property->addValidator(new EnumValidator($property, $allowedValues), 3);
    }
}
