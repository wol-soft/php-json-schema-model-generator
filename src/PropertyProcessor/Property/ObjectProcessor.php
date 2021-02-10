<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\InstanceOfValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\ObjectInstantiationDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\SchemaNamespaceTransferDecorator;

/**
 * Class ObjectProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class ObjectProcessor extends AbstractTypedValueProcessor
{
    protected const TYPE = 'object';

    /**
     * @inheritdoc
     *
     * @throws SchemaException
     */
    public function process(string $propertyName, JsonSchema $propertySchema): PropertyInterface
    {
        $property = parent::process($propertyName, $propertySchema);

        $className = $this->schemaProcessor->getGeneratorConfiguration()->getClassNameGenerator()->getClassName(
            $propertyName,
            $propertySchema,
            false,
            $this->schemaProcessor->getCurrentClassName()
        );

        $schema = $this->schemaProcessor->processSchema(
            $propertySchema,
            $this->schemaProcessor->getCurrentClassPath(),
            $className,
            $this->schema->getSchemaDictionary()
        );

        // if the generated schema is located in a different namespace (the schema for the given structure in
        // $propertySchema is duplicated) add used classes to the current schema. By importing the class which is
        // represented by $schema and by transferring all imports of $schema as well as imports for all properties
        // of $schema to $this->schema the already generated schema can be used
        if ($schema->getClassPath() !== $this->schema->getClassPath() ||
            $schema->getClassName() !== $this->schema->getClassName()
        ) {
            $this->schema->addUsedClass(
                join(
                    '\\',
                    array_filter([
                        $this->schemaProcessor->getGeneratorConfiguration()->getNamespacePrefix(),
                        $schema->getClassPath(),
                        $schema->getClassName(),
                    ])
                )
            );

            $this->schema->addNamespaceTransferDecorator(new SchemaNamespaceTransferDecorator($schema));
        }

        $property
            ->addDecorator(
                new ObjectInstantiationDecorator(
                    $schema->getClassName(),
                    $this->schemaProcessor->getGeneratorConfiguration()
                )
            )
            ->setType(new PropertyType($schema->getClassName()))
            ->setNestedSchema($schema);

        return $property->addValidator(new InstanceOfValidator($property), 3);
    }
}
