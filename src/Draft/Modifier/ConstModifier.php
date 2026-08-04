<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft\Modifier;

use PHPModelGenerator\Exception\Generic\InvalidConstException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
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

        $property->setType(
            new PropertyType(TypeConverter::gettypeToInternal(gettype($json['const']))),
        );

        $check = match (true) {
            $property->isRequired()
                => '$value !== ' . RenderHelper::varExportArray($json['const']),
            $schemaProcessor->getGeneratorConfiguration()->isImplicitNullAllowed() && !$property->isRequired()
                => '!in_array($value, ' . RenderHelper::varExportArray([$json['const'], null]) . ', true)',
            default
                => "array_key_exists('" . addslashes($property->getName()) . "', \$modelData) && \$value !== "
                    . RenderHelper::varExportArray($json['const']),
        };

        $property->addValidator(
            (new PropertyValidator(
                $property,
                $check,
                InvalidConstException::class,
                [$json['const']],
            ))->withJsonPointer($propertySchema->getPointer() . '/const'),
        );
    }
}
