<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\String\MaxLengthException;
use PHPModelGenerator\Exception\String\MinLengthException;
use PHPModelGenerator\Exception\String\PatternException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\PropertyValidator;

/**
 * Class StringProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class StringProcessor extends AbstractTypedValueProcessor
{
    protected const TYPE = 'string';

    protected const JSON_FIELD_PATTERN = 'pattern';
    protected const JSON_FIELD_MIN_LENGTH = 'minLength';
    protected const JSON_FIELD_MAX_LENGTH = 'maxLength';

    /**
     * @param PropertyInterface $property
     * @param JsonSchema $propertySchema
     *
     * @throws SchemaException
     */
    protected function generateValidators(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        parent::generateValidators($property, $propertySchema);

        $this->addPatternValidator($property, $propertySchema);
        $this->addLengthValidator($property, $propertySchema);
        $this->addFormatValidator($property, $propertySchema);
    }

    /**
     * Add a regex pattern validator
     *
     * @param PropertyInterface $property
     * @param JsonSchema $propertySchema
     */
    protected function addPatternValidator(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        $json = $propertySchema->getJson();

        if (!isset($json[static::JSON_FIELD_PATTERN])) {
            return;
        }

        $json[static::JSON_FIELD_PATTERN] = addcslashes($json[static::JSON_FIELD_PATTERN], "'");

        $property->addValidator(
            new PropertyValidator(
                $this->getTypeCheck() . "!preg_match('/{$json[static::JSON_FIELD_PATTERN]}/', \$value)",
                PatternException::class,
                [$property->getName(), $json[static::JSON_FIELD_PATTERN]]
            )
        );
    }

    /**
     * Add min and max length validator
     *
     * @param PropertyInterface $property
     * @param JsonSchema $propertySchema
     */
    protected function addLengthValidator(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        $json = $propertySchema->getJson();

        if (isset($json[static::JSON_FIELD_MIN_LENGTH])) {
            $property->addValidator(
                new PropertyValidator(
                    $this->getTypeCheck() . "mb_strlen(\$value) < {$json[static::JSON_FIELD_MIN_LENGTH]}",
                    MinLengthException::class,
                    [$property->getName(), $json[static::JSON_FIELD_MIN_LENGTH]]
                )
            );
        }

        if (isset($json[static::JSON_FIELD_MAX_LENGTH])) {
            $property->addValidator(
                new PropertyValidator(
                    $this->getTypeCheck() . "mb_strlen(\$value) > {$json[static::JSON_FIELD_MAX_LENGTH]}",
                    MaxLengthException::class,
                    [$property->getName(), $json[static::JSON_FIELD_MAX_LENGTH]]
                )
            );
        }
    }

    /**
     * TODO: implement format validations
     *
     * @param PropertyInterface $property
     * @param JsonSchema $propertySchema
     *
     * @throws SchemaException
     */
    protected function addFormatValidator(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        if (isset($propertySchema->getJson()['format'])) {
            throw new SchemaException('Format is currently not supported');
        }
    }
}
