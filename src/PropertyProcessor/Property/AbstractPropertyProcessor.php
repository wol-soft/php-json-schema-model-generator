<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\PropertyDependencyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\Model\Validator\SchemaDependencyValidator;
use PHPModelGenerator\PropertyProcessor\ComposedValueProcessorFactory;
use PHPModelGenerator\PropertyProcessor\Decorator\SchemaNamespaceTransferDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintTransferDecorator;
use PHPModelGenerator\PropertyProcessor\PropertyMetaDataCollection;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorInterface;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

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
     * @param array $propertyData
     *
     * @throws SchemaException
     */
    protected function generateValidators(PropertyInterface $property, array $propertyData): void
    {
        if ($dependencies = $this->propertyMetaDataCollection->getAttributeDependencies($property->getName())) {
            $this->addDependencyValidator($property, $dependencies);
        }

        if ($property->isRequired()) {
            $property->addValidator(new RequiredPropertyValidator($property), 1);
        }

        if (isset($propertyData['enum'])) {
            $this->addEnumValidator($property, $propertyData['enum']);
        }

        $this->addComposedValueValidator($property, $propertyData);
    }

    /**
     * Add a validator to a property which validates the value against a list of allowed values
     *
     * @param PropertyInterface $property
     * @param array             $allowedValues
     */
    protected function addEnumValidator(PropertyInterface $property, array $allowedValues): void
    {
        if (!$property->isRequired()) {
            $allowedValues[] = null;
        }

        $property->addValidator(
            new PropertyValidator(
                '!in_array($value, ' .
                    preg_replace('(\d+\s=>)', '', var_export(array_unique($allowedValues), true)) .
                    ', true)',
                "Invalid value for {$property->getName()} declined by enum constraint"
            ),
            3
        );
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
            $dependencies,
            $this->schema->getClassPath(),
            ucfirst("{$property->getName()}_Dependency_" . uniqid()),
            $this->schema->getSchemaDictionary()
        );

        $property->addValidator(new SchemaDependencyValidator($this->schemaProcessor, $property, $dependencySchema));
        $this->schema->addNamespaceTransferDecorator(new SchemaNamespaceTransferDecorator($dependencySchema));

        $this->transferDependendPropertiesToBaseSchema($dependencySchema);
    }

    /**
     * Transfer all properties from $dependencySchema to the base schema of the current property
     *
     * @param Schema $dependencySchema
     */
    private function transferDependendPropertiesToBaseSchema(Schema $dependencySchema): void
    {
        foreach ($dependencySchema->getProperties() as $property) {
            $this->schema->addProperty(
                // validators and types must not be transferred as any value is acceptable for the property if the
                // property defining the dependency isn't present
                (clone $property)
                    ->setRequired(false)
                    ->setType('')
                    ->filterValidators(function (): bool {
                        return false;
                    })
            );
        }
    }

    /**
     * @param PropertyInterface $property
     * @param array             $propertyData
     *
     * @throws SchemaException
     */
    protected function addComposedValueValidator(PropertyInterface $property, array $propertyData): void
    {
        $composedValueKeywords = ['allOf', 'anyOf', 'oneOf', 'not', 'if'];
        $propertyFactory = new PropertyFactory(new ComposedValueProcessorFactory());

        foreach ($composedValueKeywords as $composedValueKeyword) {
            if (!isset($propertyData[$composedValueKeyword])) {
                continue;
            }

            $propertyData = $this->inheritPropertyType($propertyData, $composedValueKeyword);

            $composedProperty = $propertyFactory
                ->create(
                    $this->propertyMetaDataCollection,
                    $this->schemaProcessor,
                    $this->schema,
                    $property->getName(),
                    [
                        'type' => $composedValueKeyword,
                        'propertyData' => $propertyData,
                        'onlyForDefinedValues' => !($this instanceof BaseProcessor) && !$property->isRequired(),
                    ]
                );

            foreach ($composedProperty->getValidators() as $validator) {
                $property->addValidator($validator->getValidator(), $validator->getPriority());
            }

            $property->addTypeHintDecorator(new TypeHintTransferDecorator($composedProperty));
        }
    }

    /**
     * @param array $propertyData
     * @param string $composedValueKeyword
     *
     * @return array
     */
    protected function inheritPropertyType(array $propertyData, string $composedValueKeyword): array
    {
        if (!isset($propertyData['type'])) {
            return $propertyData;
        }

        if ($propertyData['type'] === 'base') {
            $propertyData['type'] = 'object';
        }

        switch ($composedValueKeyword) {
            case 'not':
                if (!isset($propertyData[$composedValueKeyword]['type'])) {
                    $propertyData[$composedValueKeyword]['type'] = $propertyData['type'];
                }
                break;
            case 'if':
                return $this->inheritIfPropertyType($propertyData);
            default:
                foreach ($propertyData[$composedValueKeyword] as &$composedElement) {
                    if (!isset($composedElement['type'])) {
                        $composedElement['type'] = $propertyData['type'];
                    }
                }
        }

        return $propertyData;
    }

    /**
     * Inherit the type of a property into all composed components of a conditional composition
     *
     * @param array $propertyData
     *
     * @return array
     */
    protected function inheritIfPropertyType(array $propertyData): array
    {
        foreach (['if', 'then', 'else'] as $composedValueKeyword) {
            if (!isset($propertyData[$composedValueKeyword])) {
                continue;
            }

            if (!isset($propertyData[$composedValueKeyword]['type'])) {
                $propertyData[$composedValueKeyword]['type'] = $propertyData['type'];
            }
        }

        return $propertyData;
    }

    /**
     * Check if implicit null values are allowed for the given property (a not required property which has no
     * explicit null type and is passed with a null value will be accepted)
     *
     * @param GeneratorConfiguration $configuration
     * @param PropertyInterface $property
     *
     * @return bool
     */
    protected function isImplicitNullAllowed(GeneratorConfiguration $configuration, PropertyInterface $property): bool
    {
        return $configuration->isImplicitNullAllowed() && !$property->isRequired();
    }
}
