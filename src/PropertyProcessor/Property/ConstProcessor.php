<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\Generic\InvalidConstException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Utils\RenderHelper;
use PHPModelGenerator\Utils\TypeConverter;

/**
 * Class ConstProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class ConstProcessor extends AbstractPropertyProcessor
{
    /**
     * @inheritdoc
     */
    public function process(string $propertyName, JsonSchema $propertySchema): PropertyInterface
    {
        $json = $propertySchema->getJson();

        $property = new Property(
            $propertyName,
            new PropertyType(TypeConverter::gettypeToInternal(gettype($json['const']))),
            $propertySchema,
            $json['description'] ?? '',
        );

        $isAttributeRequired = $this->propertyMetaDataCollection->isAttributeRequired($propertyName);
        $isImplicitNullAllowed = $this->schemaProcessor->getGeneratorConfiguration()->isImplicitNullAllowed();
        $property->setRequired($isAttributeRequired || !$isImplicitNullAllowed);

        $check = $property->isRequired()
            ? '$value !== ' . var_export($json['const'], true)
            : '!in_array($value, ' . RenderHelper::varExportArray([$json['const'], null]) . ', true)';

        return $property->addValidator(new PropertyValidator(
            $property,
            $check,
            InvalidConstException::class,
            [$json['const']],
        ));
    }
}
