<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\String\MaxLengthException;
use PHPModelGenerator\Exception\String\MinLengthException;
use PHPModelGenerator\Exception\String\PatternException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\FormatValidator;
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
    protected const JSON_FIELD_FORMAT = 'format';
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
     *
     * @throws SchemaException
     */
    protected function addPatternValidator(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        $json = $propertySchema->getJson();

        if (!isset($json[static::JSON_FIELD_PATTERN])) {
            return;
        }

        $escapedPattern = addcslashes($json[static::JSON_FIELD_PATTERN], '/');

        if (@preg_match("/$escapedPattern/", '') === false) {
            throw new SchemaException(
                sprintf(
                    "Invalid pattern '%s' for property '%s' in file %s",
                    $json[static::JSON_FIELD_PATTERN],
                    $property->getName(),
                    $propertySchema->getFile()
                )
            );
        }

        $encodedPattern = base64_encode("/$escapedPattern/");

        $property->addValidator(
            new PropertyValidator(
                $property,
                $this->getTypeCheck() . "!preg_match(base64_decode('$encodedPattern'), \$value)",
                PatternException::class,
                [$json[static::JSON_FIELD_PATTERN]]
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
                    $property,
                    $this->getTypeCheck() . "mb_strlen(\$value) < {$json[static::JSON_FIELD_MIN_LENGTH]}",
                    MinLengthException::class,
                    [$json[static::JSON_FIELD_MIN_LENGTH]]
                )
            );
        }

        if (isset($json[static::JSON_FIELD_MAX_LENGTH])) {
            $property->addValidator(
                new PropertyValidator(
                    $property,
                    $this->getTypeCheck() . "mb_strlen(\$value) > {$json[static::JSON_FIELD_MAX_LENGTH]}",
                    MaxLengthException::class,
                    [$json[static::JSON_FIELD_MAX_LENGTH]]
                )
            );
        }
    }

    /**
     * @param PropertyInterface $property
     * @param JsonSchema $propertySchema
     *
     * @throws SchemaException
     */
    protected function addFormatValidator(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        if (!isset($propertySchema->getJson()[self::JSON_FIELD_FORMAT])) {
            return;
        }

        $formatValidator = $this->schemaProcessor
            ->getGeneratorConfiguration()
            ->getFormat($propertySchema->getJson()[self::JSON_FIELD_FORMAT]);

        if (!$formatValidator) {
            throw new SchemaException(
                sprintf(
                    'Unsupported format %s for property %s in file %s',
                    $propertySchema->getJson()[self::JSON_FIELD_FORMAT],
                    $property->getName(),
                    $propertySchema->getFile()
                )
            );
        }

        $property->addValidator(
            new FormatValidator(
                $property,
                $formatValidator,
                [$propertySchema->getJson()[self::JSON_FIELD_FORMAT]]
            )
        );
    }
}
