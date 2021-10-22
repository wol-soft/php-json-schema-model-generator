<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\ComposedValue;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\ObjectInstantiationDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\SchemaNamespaceTransferDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\ClearTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\CompositionTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\Property\AbstractValueProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyMetaDataCollection;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class AbstractComposedValueProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\ComposedValue
 */
abstract class AbstractComposedValueProcessor extends AbstractValueProcessor
{
    /** @var PropertyInterface[] */
    private static $generatedMergedProperties = [];
    /** @var bool */
    private $rootLevelComposition;

    /**
     * AbstractComposedValueProcessor constructor.
     *
     * @param PropertyMetaDataCollection $propertyMetaDataCollection
     * @param SchemaProcessor $schemaProcessor
     * @param Schema $schema
     * @param bool $rootLevelComposition
     */
    public function __construct(
        PropertyMetaDataCollection $propertyMetaDataCollection,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        bool $rootLevelComposition
    ) {
        parent::__construct($propertyMetaDataCollection, $schemaProcessor, $schema);

        $this->rootLevelComposition = $rootLevelComposition;
    }

    /**
     * @inheritdoc
     */
    protected function generateValidators(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        $json = $propertySchema->getJson()['propertySchema']->getJson();

        if (empty($json[$propertySchema->getJson()['type']]) &&
            $this->schemaProcessor->getGeneratorConfiguration()->isOutputEnabled()
        ) {
            // @codeCoverageIgnoreStart
            echo "Warning: empty composition for {$property->getName()} may lead to unexpected results\n";
            // @codeCoverageIgnoreEnd
        }

        $compositionProperties = $this->getCompositionProperties($property, $propertySchema);

        $this->transferPropertyType($property, $compositionProperties);

        $availableAmount = count($compositionProperties);

        $property->addValidator(
            new ComposedPropertyValidator(
                $property,
                $compositionProperties,
                static::class,
                [
                    'compositionProperties' => $compositionProperties,
                    'generatorConfiguration' => $this->schemaProcessor->getGeneratorConfiguration(),
                    'viewHelper' => new RenderHelper($this->schemaProcessor->getGeneratorConfiguration()),
                    'availableAmount' => $availableAmount,
                    'composedValueValidation' => $this->getComposedValueValidation($availableAmount),
                    // if the property is a composed property the resulting value of a validation must be proposed
                    // to be the final value after the validations (eg. object instantiations may be performed).
                    // Otherwise (eg. a NotProcessor) the value must be proposed before the validation
                    'postPropose' => $this instanceof ComposedPropertiesInterface,
                    'mergedProperty' =>
                        !$this->rootLevelComposition && $this instanceof MergedComposedPropertiesInterface
                            ? $this->createMergedProperty($property, $compositionProperties, $propertySchema)
                            : null,
                    'onlyForDefinedValues' =>
                        $propertySchema->getJson()['onlyForDefinedValues']
                        && $this instanceof ComposedPropertiesInterface,
                ]
            ),
            100
        );
    }

    /**
     * Set up composition properties for the given property schema
     *
     * @param PropertyInterface $property
     * @param JsonSchema        $propertySchema
     *
     * @return CompositionPropertyDecorator[]
     *
     * @throws SchemaException
     */
    protected function getCompositionProperties(PropertyInterface $property, JsonSchema $propertySchema): array
    {
        $propertyFactory = new PropertyFactory(new PropertyProcessorFactory());
        $compositionProperties = [];
        $json = $propertySchema->getJson()['propertySchema']->getJson();

        // clear the base type of the property to keep only the types of the composition.
        // This avoids e.g. "array|int[]" for a property which is known to contain always an integer array
        $property->addTypeHintDecorator(new ClearTypeHintDecorator());

        foreach ($json[$propertySchema->getJson()['type']] as $compositionElement) {
            $compositionSchema = $propertySchema->getJson()['propertySchema']->withJson($compositionElement);

            $compositionProperty = new CompositionPropertyDecorator(
                $property->getName(),
                $compositionSchema,
                $propertyFactory
                    ->create(
                        new PropertyMetaDataCollection([$property->getName() => $property->isRequired()]),
                        $this->schemaProcessor,
                        $this->schema,
                        $property->getName(),
                        $compositionSchema
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

            $compositionProperties[] = $compositionProperty;
        }

        return $compositionProperties;
    }

    /**
     * Check if the provided property can inherit a single type from the composition properties.
     *
     * @param PropertyInterface $property
     * @param CompositionPropertyDecorator[] $compositionProperties
     */
    private function transferPropertyType(PropertyInterface $property, array $compositionProperties)
    {
        $compositionPropertyTypes = array_values(
            array_unique(
                array_map(
                    function (CompositionPropertyDecorator $property): string {
                        return $property->getType() ? $property->getType()->getName() : '';
                    },
                    $compositionProperties
                )
            )
        );

        $nonEmptyCompositionPropertyTypes = array_values(array_filter($compositionPropertyTypes));

        if (count($nonEmptyCompositionPropertyTypes) === 1 && !($this instanceof NotProcessor)) {
            $property->setType(
                new PropertyType(
                    $nonEmptyCompositionPropertyTypes[0],
                    count($compositionPropertyTypes) > 1 ? true : null
                )
            );
        }
    }

    /**
     * Gather all nested object properties and merge them together into a single merged property
     *
     * @param PropertyInterface              $property
     * @param CompositionPropertyDecorator[] $compositionProperties
     * @param JsonSchema                     $propertySchema
     *
     * @return PropertyInterface|null
     *
     * @throws SchemaException
     */
    private function createMergedProperty(
        PropertyInterface $property,
        array $compositionProperties,
        JsonSchema $propertySchema
    ): ?PropertyInterface {
        $redirectToProperty = $this->redirectMergedProperty($compositionProperties);
        if ($redirectToProperty === null || $redirectToProperty instanceof PropertyInterface) {
            if ($redirectToProperty) {
                $property->addTypeHintDecorator(new CompositionTypeHintDecorator($redirectToProperty));
            }

            return $redirectToProperty;
        }

        $mergedClassName = $this->schemaProcessor
            ->getGeneratorConfiguration()
            ->getClassNameGenerator()
            ->getClassName(
                $property->getName(),
                $propertySchema,
                true,
                $this->schemaProcessor->getCurrentClassName()
            );

        // check if the merged property already has been generated
        if (isset(self::$generatedMergedProperties[$mergedClassName])) {
            return self::$generatedMergedProperties[$mergedClassName];
        }

        $mergedPropertySchema = new Schema($this->schema->getClassPath(), $mergedClassName, $propertySchema);

        $mergedProperty = new Property(
            'MergedProperty',
            new PropertyType($mergedClassName),
            $mergedPropertySchema->getJsonSchema()
        );

        self::$generatedMergedProperties[$mergedClassName] = $mergedProperty;

        $this->transferPropertiesToMergedSchema($mergedPropertySchema, $compositionProperties);

        $this->schemaProcessor->generateClassFile(
            $this->schemaProcessor->getCurrentClassPath(),
            $mergedClassName,
            $mergedPropertySchema
        );

        $property->addTypeHintDecorator(new CompositionTypeHintDecorator($mergedProperty));

        return $mergedProperty
            ->addDecorator(
                new ObjectInstantiationDecorator($mergedClassName, $this->schemaProcessor->getGeneratorConfiguration())
            )
            ->setNestedSchema($mergedPropertySchema);
    }

    /**
     * Check if multiple $compositionProperties contain nested schemas. Only in this case a merged property must be
     * created. If no nested schemas are detected null will be returned. If only one $compositionProperty contains a
     * nested schema the $compositionProperty will be used as a replacement for the merged property.
     *
     * Returns false if a merged property must be created.
     *
     * @param CompositionPropertyDecorator[] $compositionProperties
     *
     * @return PropertyInterface|null|false
     */
    private function redirectMergedProperty(array $compositionProperties)
    {
        $redirectToProperty = null;
        foreach ($compositionProperties as $property) {
            if ($property->getNestedSchema()) {
                if ($redirectToProperty !== null) {
                    return false;
                }

                $redirectToProperty = $property;
            }
        }

        return $redirectToProperty;
    }

    /**
     * @param Schema              $mergedPropertySchema
     * @param PropertyInterface[] $compositionProperties
     */
    private function transferPropertiesToMergedSchema(Schema $mergedPropertySchema, array $compositionProperties): void
    {
        foreach ($compositionProperties as $property) {
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
