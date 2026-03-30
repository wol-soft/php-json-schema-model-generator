<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft;

use PHPModelGenerator\Draft\Element\Type;
use PHPModelGenerator\Draft\Modifier\DefaultArrayToEmptyArrayModifier;
use PHPModelGenerator\Model\Validator\Factory\Composition\AllOfValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Composition\AnyOfValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Composition\IfValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Composition\NotValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Composition\OneOfValidatorFactory;
use PHPModelGenerator\Draft\Modifier\ConstModifier;
use PHPModelGenerator\Draft\Modifier\DefaultValueModifier;
use PHPModelGenerator\Draft\Modifier\IntToFloatModifier;
use PHPModelGenerator\Draft\Modifier\NullModifier;
use PHPModelGenerator\Draft\Modifier\ObjectType\ObjectModifier;
use PHPModelGenerator\Model\Validator\Factory\Any\EnumValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Any\FilterValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Arrays\ContainsValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Arrays\ItemsValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Arrays\MaxItemsValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Arrays\MinItemsValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Arrays\UniqueItemsValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Number\ExclusiveMaximumValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Number\ExclusiveMinimumValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Number\MaximumValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Number\MinimumValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Number\MultipleOfPropertyValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Object\AdditionalPropertiesValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Object\PropertiesValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Object\MaxPropertiesValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Object\MinPropertiesValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Object\PatternPropertiesValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Object\PropertyNamesValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\String\FormatValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\String\MaxLengthValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\String\MinLengthPropertyValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\String\PatternPropertyValidatorFactory;

class Draft_07 implements DraftInterface
{
    public function getDefinition(): DraftBuilder
    {
        return (new DraftBuilder())
            ->addType((new Type('object', false))
                ->addValidator('properties', new PropertiesValidatorFactory())
                ->addValidator('propertyNames', new PropertyNamesValidatorFactory())
                ->addValidator('patternProperties', new PatternPropertiesValidatorFactory())
                ->addValidator('additionalProperties', new AdditionalPropertiesValidatorFactory())
                ->addValidator('minProperties', new MinPropertiesValidatorFactory())
                ->addValidator('maxProperties', new MaxPropertiesValidatorFactory())
                ->addModifier(new ObjectModifier()))
            ->addType((new Type('array'))
                ->addValidator('items', new ItemsValidatorFactory())
                ->addValidator('minItems', new MinItemsValidatorFactory())
                ->addValidator('maxItems', new MaxItemsValidatorFactory())
                ->addValidator('uniqueItems', new UniqueItemsValidatorFactory())
                ->addValidator('contains', new ContainsValidatorFactory())
                ->addModifier(new DefaultArrayToEmptyArrayModifier()))
            ->addType((new Type('string'))
                ->addValidator('pattern', new PatternPropertyValidatorFactory())
                ->addValidator('minLength', new MinLengthPropertyValidatorFactory())
                ->addValidator('maxLength', new MaxLengthValidatorFactory())
                ->addValidator('format', new FormatValidatorFactory()))
            ->addType((new Type('integer'))
                ->addValidator('minimum', new MinimumValidatorFactory('is_int'))
                ->addValidator('maximum', new MaximumValidatorFactory('is_int'))
                ->addValidator('exclusiveMinimum', new ExclusiveMinimumValidatorFactory('is_int'))
                ->addValidator('exclusiveMaximum', new ExclusiveMaximumValidatorFactory('is_int'))
                ->addValidator('multipleOf', new MultipleOfPropertyValidatorFactory('is_int', true)))
            ->addType((new Type('number'))
                ->addValidator('minimum', new MinimumValidatorFactory('is_float'))
                ->addValidator('maximum', new MaximumValidatorFactory('is_float'))
                ->addValidator('exclusiveMinimum', new ExclusiveMinimumValidatorFactory('is_float'))
                ->addValidator('exclusiveMaximum', new ExclusiveMaximumValidatorFactory('is_float'))
                ->addValidator('multipleOf', new MultipleOfPropertyValidatorFactory('is_float', false))
                ->addModifier(new IntToFloatModifier()))
            ->addType(new Type('boolean'))
            ->addType((new Type('null'))
                ->addModifier(new NullModifier()))
            ->addType((new Type('any', false))
                ->addValidator('enum', new EnumValidatorFactory())
                ->addValidator('filter', new FilterValidatorFactory())
                ->addValidator('allOf', new AllOfValidatorFactory())
                ->addValidator('anyOf', new AnyOfValidatorFactory())
                ->addValidator('oneOf', new OneOfValidatorFactory())
                ->addValidator('not', new NotValidatorFactory())
                ->addValidator('if', new IfValidatorFactory())
                ->addModifier(new DefaultValueModifier())
                ->addModifier(new ConstModifier()));
    }
}
