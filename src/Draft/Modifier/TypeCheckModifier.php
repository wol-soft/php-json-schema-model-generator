<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft\Modifier;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\TypeCheckInterface;
use PHPModelGenerator\Model\Validator\TypeCheckValidator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

class TypeCheckModifier implements ModifierInterface
{
    public function __construct(private readonly string $type)
    {}

    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        foreach ($property->getValidators() as $validator) {
            if (
                $validator->getValidator() instanceof TypeCheckInterface &&
                in_array($this->type, $validator->getValidator()->getTypes(), true)
            ) {
                return;
            }
        }

        $property->addValidator(
            new TypeCheckValidator(
                $this->type,
                $property,
                $schemaProcessor->getGeneratorConfiguration()->isImplicitNullAllowed() && !$property->isRequired(),
            ),
            2,
        );
    }
}
