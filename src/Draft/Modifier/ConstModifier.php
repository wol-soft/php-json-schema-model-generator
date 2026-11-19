<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft\Modifier;

use PHPModelGenerator\Exception\Generic\InvalidConstException;
use PHPModelGenerator\Model\Property\BaseProperty;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\IntegralFloatToIntCastDecorator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\JsonValueCheck;
use PHPModelGenerator\Utils\RenderHelper;
use PHPModelGenerator\Utils\TypeConverter;

class ConstModifier implements ModifierInterface
{
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $json = $propertySchema->getJson();

        if (!array_key_exists('const', $json)) {
            return;
        }

        // The type of the property decides how a number is represented: a number property compares a float
        $const = JsonValueCheck::alignNumbers([$json['const']], $property->getType())[0];

        // A property which transforms the provided value (eg. into the model of a nested class) already carries the
        // type of the value it holds, the const compares the provided data. Deriving the type from the const would
        // type the property as array although the property holds the model. Testing for a nested schema instead was
        // rejected: the object variant of a multi type property holds the model without owning the nested schema.
        if (!$property instanceof BaseProperty && $property->getDecorators() === []) {
            $type = TypeConverter::gettypeToInternal(gettype($const));
            $property->setType(new PropertyType($type));

            // the derived int type accepts an integral float like an integer property does
            if ($type === 'int') {
                $property->addDecorator(new IntegralFloatToIntCastDecorator());
            }
        }

        $check = match (true) {
            $property->isRequired()
                => JsonValueCheck::notEqualTo($const, $property->getType()),
            $schemaProcessor->getGeneratorConfiguration()->isImplicitNullAllowed()
                => JsonValueCheck::notOneOf([$const, null], $property->getType()),
            default
                => "array_key_exists('" . RenderHelper::escapeSingleQuoted($property->getName()) . "', \$modelData) && "
                    . JsonValueCheck::notEqualTo($const, $property->getType()),
        };

        $validator = (new PropertyValidator(
            $property,
            $check,
            InvalidConstException::class,
            [$const],
            JsonValueCheck::providedValue([$const]),
        ))->withJsonPointer($propertySchema->getPointer() . '/const');

        // The const of the schema of a class (the root of a schema file) constrains the object itself. No property of
        // a class holds the object, so the validator is rendered with the validators of the object. It compares the
        // data of the whole object: as a post composition validator it validates the state of the object after an
        // update. An object level validator (eg. maxProperties) only receives the updated key of an update.
        if ($property instanceof BaseProperty) {
            $schema->addPostCompositionValidator($validator);

            return;
        }

        $property->addValidator($validator);
    }
}
