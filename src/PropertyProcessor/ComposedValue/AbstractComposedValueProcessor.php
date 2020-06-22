<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\ComposedValue;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\ObjectInstantiationDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\SchemaNamespaceTransferDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\CompositionTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\Property\AbstractValueProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyMetaDataCollection;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class AbstractComposedValueProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\ComposedValue
 */
abstract class AbstractComposedValueProcessor extends AbstractValueProcessor
{
    private static $generatedMergedProperties = [];

    /**
     * @inheritdoc
     */
    protected function generateValidators(PropertyInterface $property, array $propertyData): void
    {
        if (empty($propertyData['propertyData'][$propertyData['type']]) &&
            $this->schemaProcessor->getGeneratorConfiguration()->isOutputEnabled()
        ) {
            // @codeCoverageIgnoreStart
            echo "Warning: empty composition for {$property->getName()} may lead to unexpected results\n";
            // @codeCoverageIgnoreEnd
        }

        $properties = $this->getCompositionProperties($property, $propertyData);
        $availableAmount = count($properties);

        $property->addValidator(
            new ComposedPropertyValidator(
                $property,
                $properties,
                static::class,
                [
                    'properties' => $properties,
                    'generatorConfiguration' => $this->schemaProcessor->getGeneratorConfiguration(),
                    'viewHelper' => new RenderHelper($this->schemaProcessor->getGeneratorConfiguration()),
                    'availableAmount' => $availableAmount,
                    'composedValueValidation' => $this->getComposedValueValidation($availableAmount),
                    // if the property is a composed property the resulting value of a validation must be proposed
                    // to be the final value after the validations (eg. object instantiations may be performed).
                    // Otherwise (eg. a NotProcessor) the value must be proposed before the validation
                    'postPropose' => $this instanceof ComposedPropertiesInterface,
                    'mergedProperty' =>
                        $this instanceof MergedComposedPropertiesInterface
                            ? $this->createMergedProperty($property, $properties, $propertyData)
                            : null,
                    'onlyForDefinedValues' =>
                        $propertyData['onlyForDefinedValues'] && $this instanceof ComposedPropertiesInterface,
                ]
            ),
            100
        );
    }

    /**
     * Set up composition properties for the given propertyData schema
     *
     * @param PropertyInterface $property
     * @param array             $propertyData
     *
     * @return CompositionPropertyDecorator[]
     *
     * @throws SchemaException
     */
    protected function getCompositionProperties(PropertyInterface $property, array $propertyData): array
    {
        $propertyFactory = new PropertyFactory(new PropertyProcessorFactory());
        $properties = [];

        foreach ($propertyData['propertyData'][$propertyData['type']] as $compositionElement) {
            $compositionProperty = new CompositionPropertyDecorator(
                $propertyFactory
                    ->create(
                        new PropertyMetaDataCollection([$property->getName() => $property->isRequired()]),
                        $this->schemaProcessor,
                        $this->schema,
                        $property->getName(),
                        $compositionElement
                    )
            );

            $compositionProperty->filterValidators(function (Validator $validator): bool {
                return !is_a($validator->getValidator(), RequiredPropertyValidator::class) &&
                    !is_a($validator->getValidator(), ComposedPropertyValidator::class);
            });

            // only create a composed type hint if we aren't a AnyOf or an AllOf processor and the compositionProperty
            // contains no object. This results in objects being composed each separately for a OneOf processor
            // (eg. string|ObjectA|ObjectB). For a merged composed property the objects are merged together so it
            // results in string|MergedObject
            if (!($this instanceof MergedComposedPropertiesInterface && $compositionProperty->getNestedSchema())) {
                $property->addTypeHintDecorator(new CompositionTypeHintDecorator($compositionProperty));
            }

            $properties[] = $compositionProperty;
        }

        return $properties;
    }

    /**
     * TODO: no nested properties --> cancel, only one --> use original model
     *
     * Gather all nested object properties and merge them together into a single merged property
     *
     * @param PropertyInterface              $compositionProperty
     * @param CompositionPropertyDecorator[] $properties
     * @param array                          $propertyData
     *
     * @return PropertyInterface
     *
     * @throws SchemaException
     */
    private function createMergedProperty(
        PropertyInterface $compositionProperty,
        array $properties,
        array $propertyData
    ): PropertyInterface {
        $mergedClassName = $this->schemaProcessor->getGeneratorConfiguration()->getClassNameGenerator()->getClassName(
            $compositionProperty->getName(),
            $propertyData['propertyData'],
            true,
            $this->schemaProcessor->getCurrentClassName()
        );

        // check if the merged property already has been generated
        if (isset(self::$generatedMergedProperties[$mergedClassName])) {
            return self::$generatedMergedProperties[$mergedClassName];
        }

        $mergedPropertySchema = new Schema($this->schema->getClassPath(), $mergedClassName);
        $mergedProperty = new Property('MergedProperty', $mergedClassName);
        self::$generatedMergedProperties[$mergedClassName] = $mergedProperty;

        $this->transferPropertiesToMergedSchema($mergedPropertySchema, $properties);

        $this->schemaProcessor->generateClassFile(
            $this->schemaProcessor->getCurrentClassPath(),
            $mergedClassName,
            $mergedPropertySchema
        );

        $compositionProperty->addTypeHintDecorator(new CompositionTypeHintDecorator($mergedProperty));

        return $mergedProperty
            ->addDecorator(
                new ObjectInstantiationDecorator($mergedClassName, $this->schemaProcessor->getGeneratorConfiguration())
            )
            ->setNestedSchema($mergedPropertySchema);
    }

    /**
     * @param Schema              $mergedPropertySchema
     * @param PropertyInterface[] $properties
     */
    protected function transferPropertiesToMergedSchema(Schema $mergedPropertySchema, array $properties): void
    {
        foreach ($properties as $property) {
            if (!$property->getNestedSchema()) {
                continue;
            }

            foreach ($property->getNestedSchema()->getProperties() as $nestedProperty) {
                $mergedPropertySchema->addProperty(
                    // don't validate fields in merged properties. All fields were validated before corresponding to
                    // the defined constraints of the composition property.
                    (clone $nestedProperty)->filterValidators(function (): bool {
                        return false;
                    })
                );

                // the parent schema needs to know about all imports of the nested classes as all properties of the
                // nested classes are available in the parent schema (combined schema merging)
                $this->schema->addNamespaceTransferDecorator(
                    new SchemaNamespaceTransferDecorator($property->getNestedSchema())
                );
            }

            // make sure the merged schema knows all imports of the parent schema
            $mergedPropertySchema->addNamespaceTransferDecorator(new SchemaNamespaceTransferDecorator($this->schema));
        }
    }

    /**
     * @param int $composedElements The amount of elements which are composed together
     *
     * @return string
     */
    abstract protected function getComposedValueValidation(int $composedElements): string;
}
