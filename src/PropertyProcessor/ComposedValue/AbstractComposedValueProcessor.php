<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\ComposedValue;

use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\ObjectInstantiationDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\CompositionTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\Property\AbstractValueProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
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
        $propertyFactory = new PropertyFactory(new PropertyProcessorFactory());

        $properties = [];
        $createMergedProperty = $this instanceof MergedComposedPropertiesInterface;

        foreach ($propertyData['propertyData'][$propertyData['type']] as $compositionElement) {
            $compositionProperty = new CompositionPropertyDecorator(
                $propertyFactory
                    ->create(
                        new PropertyCollectionProcessor([$property->getName() => $property->isRequired()]),
                        $this->schemaProcessor,
                        $this->schema,
                        $property->getName(),
                        $compositionElement
                    )
            );

            $compositionProperty->filterValidators(function (Validator $validator) {
                return !is_a($validator->getValidator(), RequiredPropertyValidator::class) &&
                    !is_a($validator->getValidator(), ComposedPropertyValidator::class);
            });

            // only create a composed type hint if we aren't a AnyOf or an AllOf processor and the compositionProperty
            // contains no object. This results in objects being composed each separately for a OneOf processor
            // (eg. string|ObjectA|ObjectB). For a merged composed property the objects are merged together so it
            // results in string|MergedObject
            if (!($createMergedProperty && $compositionProperty->getNestedSchema())) {
                $property->addTypeHintDecorator(new CompositionTypeHintDecorator($compositionProperty));
            }

            $properties[] = $compositionProperty;
        }

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
                    'composedErrorMessage' => $this->getComposedValueValidationErrorLabel($availableAmount),
                    // if the property is a composed property the resulting value of a validation must be proposed
                    // to be the final value after the validations (eg. object instantiations may be performed).
                    // Otherwise (eg. a NotProcessor) the value must be proposed before the validation
                    'postPropose' => $this instanceof ComposedPropertiesInterface,
                    'mergedProperty' =>
                        $createMergedProperty
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
     * TODO: no nested properties --> cancel, only one --> use original model
     *
     * Gather all nested object properties and merge them together into a single merged property
     *
     * @param PropertyInterface              $compositionProperty
     * @param CompositionPropertyDecorator[] $properties
     * @param array                          $propertyData
     *
     * @return PropertyInterface
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

        $mergedPropertySchema = new Schema($mergedClassName);
        $mergedProperty = new Property('MergedProperty', $mergedClassName);
        self::$generatedMergedProperties[$mergedClassName] = $mergedProperty;

        foreach ($properties as $property) {
            if ($property->getNestedSchema()) {
                foreach ($property->getNestedSchema()->getProperties() as $nestedProperty) {
                    $mergedPropertySchema->addProperty(
                        // don't validate fields in merged properties. All fields were validated before corresponding to
                        // the defined constraints of the composition property.
                        (clone $nestedProperty)->filterValidators(function () {
                            return false;
                        })
                    );
                }
            }
        }

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
     * @param int $composedElements The amount of elements which are composed together
     *
     * @return string
     */
    abstract protected function getComposedValueValidation(int $composedElements): string;

    /**
     * @param int $composedElements The amount of elements which are composed together
     *
     * @return string
     */
    abstract protected function getComposedValueValidationErrorLabel(int $composedElements): string;
}
