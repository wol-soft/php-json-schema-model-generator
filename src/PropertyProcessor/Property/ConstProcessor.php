<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\Generic\InvalidConstException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorInterface;

/**
 * Class ConstProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class ConstProcessor implements PropertyProcessorInterface
{
    /**
     * @inheritdoc
     */
    public function process(string $propertyName, JsonSchema $propertySchema): PropertyInterface
    {
        $json = $propertySchema->getJson();

        $property = new Property(
            $propertyName,
            gettype($json['const']),
            $propertySchema,
            $json['description'] ?? ''
        );

        return $property
            ->setRequired(true)
            ->addValidator(new PropertyValidator(
                $property,
                '$value !== ' . var_export($json['const'], true),
                InvalidConstException::class,
                [$json['const']]
            ));
    }
}
