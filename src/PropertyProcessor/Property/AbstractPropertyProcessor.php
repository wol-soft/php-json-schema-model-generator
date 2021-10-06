<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\BaseProperty;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\EnumValidator;
use PHPModelGenerator\Model\Validator\PropertyDependencyValidator;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\Model\Validator\SchemaDependencyValidator;
use PHPModelGenerator\PropertyProcessor\ComposedValueProcessorFactory;
use PHPModelGenerator\PropertyProcessor\Decorator\SchemaNamespaceTransferDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintTransferDecorator;
use PHPModelGenerator\PropertyProcessor\PropertyMetaDataCollection;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorInterface;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\TypeConverter;

/**
 * Class AbstractPropertyProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
abstract class AbstractPropertyProcessor implements PropertyProcessorInterface
{
    /** @var PropertyMetaDataCollection */
    protected $propertyMetaDataCollection;
    /** @var SchemaProcessor */
    protected $schemaProcessor;
    /** @var Schema */
    protected $schema;

    /**
     * AbstractPropertyProcessor constructor.
     *
     * @param PropertyMetaDataCollection $propertyMetaDataCollection
     * @param SchemaProcessor            $schemaProcessor
     * @param Schema                     $schema
     */
    public function __construct(
        PropertyMetaDataCollection $propertyMetaDataCollection,
        SchemaProcessor $schemaProcessor,
        Schema $schema
    ) {
        $this->propertyMetaDataCollection = $propertyMetaDataCollection;
        $this->schemaProcessor = $schemaProcessor;
        $this->schema = $schema;
    }

    /**
     * Generates the validators for the property
     *
     * @param PropertyInterface $property
     * @param JsonSchema $propertySchema
     *
     * @throws SchemaException
     */
    protected function generateValidators(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        if ($dependencies = $this->propertyMetaDataCollection->getAttributeDependencies($property->getName())) {
            $this->addDependencyValidator($property, $dependencies);
        }

        if ($property->isRequired()) {
            $property->addValidator(new RequiredPropertyValidator($property), 1);
        }

        if (isset($propertySchema->getJson()['enum'])) {
            $this->addEnumValidator($property, $propertySchema->getJson()['enum']);
        }

        $this->addComposedValueValidator($property, $propertySchema);
    }

    /**
     * Add a validator to a property which validates the value against a list of allowed values
     *
     * @param PropertyInterface $property
     * @param array             $allowedValues
     *
     * @throws SchemaException
     */
    protected function addEnumValidator(PropertyInterface $property, array $allowedValues): void
    {
        if (empty($allowedValues)) {
            throw new SchemaException(
                sprintf(
                    "Empty enum property %s in file %s",
                    $property->getName(),
                    $property->getJsonSchema()->getFile()
                )
            );
        }

        $allowedValues = array_unique($allowedValues);

        // no type information provided - inherit the types from the enum values
        if (!$property->getType()) {
            $typesOfEnum = array_unique(array_map(
                function ($value): string {
                    return TypeConverter::gettypeToInternal(gettype($value));
                },
                $allowedValues
            ));

            if (count($typesOfEnum) === 1) {
                $property->setType(new PropertyType($typesOfEnum[0]));
            }
            $property->addTypeHintDecorator(new TypeHintDecorator($typesOfEnum));
        }

        if ($this->isImplicitNullAllowed($property) && !in_array(null, $allowedValues)) {
            $allowedValues[] = null;
        }

        $property->addValidator(new EnumValidator($property, $allowedValues), 3);
    }

    /**
     * @param PropertyInterface $property
     * @param array $dependencies
     *
     * @throws SchemaException
     */
    protected function addDependencyValidator(PropertyInterface $property, array $dependencies): void
    {
        // check if we have a simple list of properties which must be present if the current property is present
        $propertyDependency = true;

        array_walk(
            $dependencies,
            function ($dependency, $index) use (&$propertyDependency): void {
                $propertyDependency = $propertyDependency && is_int($index) && is_string($dependency);
            }
        );

        if ($propertyDependency) {
            $property->addValidator(new PropertyDependencyValidator($property, $dependencies));

            return;
        }

        if (!isset($dependencies['type'])) {
            $dependencies['type'] = 'object';
        }

        $dependencySchema = $this->schemaProcessor->processSchema(
            new JsonSchema($this->schema->getJsonSchema()->getFile(), $dependencies),
            $this->schema->getClassPath(),
            "{$this->schema->getClassName()}_{$property->getName()}_Dependency",
            $this->schema->getSchemaDictionary()
        );

        $property->addValidator(new SchemaDependencyValidator($this->schemaProcessor, $property, $dependencySchema));
        $this->schema->addNamespaceTransferDecorator(new SchemaNamespaceTransferDecorator($dependencySchema));

        $this->transferDependentPropertiesToBaseSchema($dependencySchema);
    }

    /**
     * Transfer all properties from $dependencySchema to the base schema of the current property
     *
     * @param Schema $dependencySchema
     */
    private function transferDependentPropertiesToBaseSchema(Schema $dependencySchema): void
    {
        foreach ($dependencySchema->getProperties() as $property) {
            $this->schema->addProperty(
                // validators and types must not be transferred as any value is acceptable for the property if the
                // property defining the dependency isn't present
                (clone $property)
                    ->setRequired(false)
                    ->setType(null)
                    ->filterValidators(function (): bool {
                        return false;
                    })
            );
        }
    }

    /**
     * @param PropertyInterface $property
     * @param JsonSchema $propertySchema
     *
     * @throws SchemaException
     */
    protected function addComposedValueValidator(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        $composedValueKeywords = ['allOf', 'anyOf', 'oneOf', 'not', 'if'];
        $propertyFactory = new PropertyFactory(new ComposedValueProcessorFactory($property instanceof BaseProperty));

        foreach ($composedValueKeywords as $composedValueKeyword) {
            if (!isset($propertySchema->getJson()[$composedValueKeyword])) {
                continue;
            }

            $propertySchema = $this->inheritPropertyType($propertySchema, $composedValueKeyword);

            $composedProperty = $propertyFactory
                ->create(
                    $this->propertyMetaDataCollection,
                    $this->schemaProcessor,
                    $this->schema,
                    $property->getName(),
                    $propertySchema->withJson([
                        'type' => $composedValueKeyword,
                        'propertySchema' => $propertySchema,
                        'onlyForDefinedValues' => !($this instanceof BaseProcessor) && !$property->isRequired(),
                    ])
                );

            foreach ($composedProperty->getValidators() as $validator) {
                $property->addValidator($validator->getValidator(), $validator->getPriority());
            }

            $property->addTypeHintDecorator(new TypeHintTransferDecorator($composedProperty));

            if (!$property->getType() && $composedProperty->getType()) {
                $property->setType($composedProperty->getType(), $composedProperty->getType(true));
            }
        }
    }

    /**
     * If the type of a property containing a composition is defined outside of the composition make sure each
     * composition which doesn't define a type inherits the type
     *
     * @param JsonSchema $propertySchema
     * @param string $composedValueKeyword
     *
     * @return JsonSchema
     */
    protected function inheritPropertyType(JsonSchema $propertySchema, string $composedValueKeyword): JsonSchema
    {
        $json = $propertySchema->getJson();

        if (!isset($json['type'])) {
            return $propertySchema;
        }

        if ($json['type'] === 'base') {
            $json['type'] = 'object';
        }

        switch ($composedValueKeyword) {
            case 'not':
                if (!isset($json[$composedValueKeyword]['type'])) {
                    $json[$composedValueKeyword]['type'] = $json['type'];
                }
                break;
            case 'if':
                return $this->inheritIfPropertyType($propertySchema->withJson($json));
            default:
                foreach ($json[$composedValueKeyword] as &$composedElement) {
                    if (!isset($composedElement['type'])) {
                        $composedElement['type'] = $json['type'];
                    }
                }
        }

        return $propertySchema->withJson($json);
    }

    /**
     * Inherit the type of a property into all composed components of a conditional composition
     *
     * @param JsonSchema $propertySchema
     *
     * @return JsonSchema
     */
    protected function inheritIfPropertyType(JsonSchema $propertySchema): JsonSchema
    {
        $json = $propertySchema->getJson();

        foreach (['if', 'then', 'else'] as $composedValueKeyword) {
            if (!isset($json[$composedValueKeyword])) {
                continue;
            }

            if (!isset($json[$composedValueKeyword]['type'])) {
                $json[$composedValueKeyword]['type'] = $json['type'];
            }
        }

        return $propertySchema->withJson($json);
    }

    /**
     * Check if implicit null values are allowed for the given property (a not required property which has no
     * explicit null type and is passed with a null value will be accepted)
     *
     * @param PropertyInterface $property
     *
     * @return bool
     */
    protected function isImplicitNullAllowed(PropertyInterface $property): bool
    {
        return $this->schemaProcessor->getGeneratorConfiguration()->isImplicitNullAllowed() && !$property->isRequired();
    }
}
