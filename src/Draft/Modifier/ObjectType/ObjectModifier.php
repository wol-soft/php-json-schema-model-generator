<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft\Modifier\ObjectType;

use PHPModelGenerator\Draft\Modifier\ModifierInterface;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\BaseProperty;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\InstanceOfValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\ObjectInstantiationDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\SchemaNamespaceTransferDecorator;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

class ObjectModifier implements ModifierInterface
{
    /**
     * @param bool $asserting Whether the property's value is guaranteed to be an object. An
     *                        object-describing schema (object-constraining keywords with no
     *                        `type`) is vacuously satisfied by non-object values, so it gets the
     *                        instantiation linkage without the assertion that would reject them.
     */
    public function __construct(private readonly bool $asserting = true)
    {
    }

    /**
     * Wire the instantiation linkage for a nested object property.
     *
     * The nested Schema is already set on the property by PropertyFactory before the modifier
     * list runs. This modifier reads it and attaches ObjectInstantiationDecorator,
     * InstanceOfValidator, PropertyType, and namespace transfer decorators to the outer property.
     *
     * Skips for BaseProperty (root-level schema) — no instantiation is needed there.
     *
     * @throws SchemaException
     */
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        if ($property instanceof BaseProperty) {
            return;
        }

        $nestedSchema = $property->getNestedSchema();

        if (
            $nestedSchema->getClassPath() !== $schema->getClassPath() ||
            $nestedSchema->getClassName() !== $schema->getClassName()
        ) {
            $schema->addUsedClass(
                join(
                    '\\',
                    array_filter([
                        $schemaProcessor->getGeneratorConfiguration()->getNamespacePrefix(),
                        $nestedSchema->getClassPath(),
                        $nestedSchema->getClassName(),
                    ]),
                )
            );

            $schema->addNamespaceTransferDecorator(new SchemaNamespaceTransferDecorator($nestedSchema));
        }

        $property->addDecorator(
            new ObjectInstantiationDecorator(
                $nestedSchema->getClassName(),
                $schemaProcessor->getGeneratorConfiguration(),
            )
        );

        if (!$this->asserting) {
            // The value is not exclusively object-valued - a non-object passes through unchanged -
            // so it must stay untyped and unasserted. Typing it as the representation class would
            // make the getter's return type reject the passed-through value at read time.
            return;
        }

        $property->setType(new PropertyType($nestedSchema->getClassName()));

        $property->addValidator(
            (new InstanceOfValidator($property))->withJsonPointer($propertySchema->getPointer() . '/type'),
            3,
        );
    }
}
