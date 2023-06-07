<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\ComposedValue;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\ObjectInstantiationDecorator;
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
    /** @var bool */
    private $rootLevelComposition;
    /** @var PropertyInterface|null */
    private $mergedProperty = null;

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

        $resolvedCompositions = 0;
        foreach ($compositionProperties as $compositionProperty) {
            $compositionProperty->onResolve(
                function () use (&$resolvedCompositions, $property, $compositionProperties, $propertySchema): void {
                    if (++$resolvedCompositions === count($compositionProperties)) {
                        $this->transferPropertyType($property, $compositionProperties);

                        $this->mergedProperty = !$this->rootLevelComposition
                            && $this instanceof MergedComposedPropertiesInterface
                                ? $this->schemaProcessor->createMergedProperty(
                                    $this->schema,
                                    $property,
                                    $compositionProperties,
                                    $propertySchema
                                  )
                                : null;

                        if ($this->mergedProperty) {
                            $property->setNestedSchema($this->mergedProperty->getNestedSchema());
                        }
                    }
                }
            );
        }

        $availableAmount = count($compositionProperties);

        $property->addValidator(
            new ComposedPropertyValidator(
                $this->schemaProcessor->getGeneratorConfiguration(),
                $property,
                $compositionProperties,
                static::class,
                [
                    'compositionProperties' => $compositionProperties,
                    'schema' => $this->schema,
                    'generatorConfiguration' => $this->schemaProcessor->getGeneratorConfiguration(),
                    'viewHelper' => new RenderHelper($this->schemaProcessor->getGeneratorConfiguration()),
                    'availableAmount' => $availableAmount,
                    'composedValueValidation' => $this->getComposedValueValidation($availableAmount),
                    // if the property is a composed property the resulting value of a validation must be proposed
                    // to be the final value after the validations (e.g. object instantiations may be performed).
                    // Otherwise (eg. a NotProcessor) the value must be proposed before the validation
                    'postPropose' => $this instanceof ComposedPropertiesInterface,
                    'mergedProperty' => &$this->mergedProperty,
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

            $compositionProperty->onResolve(function () use ($compositionProperty, $property): void {
                $compositionProperty->filterValidators(static function (Validator $validator): bool {
                    return !is_a($validator->getValidator(), RequiredPropertyValidator::class) &&
                        !is_a($validator->getValidator(), ComposedPropertyValidator::class);
                });

                // only create a composed type hint if we aren't a AnyOf or an AllOf processor and the
                // compositionProperty contains no object. This results in objects being composed each separately for a
                // OneOf processor (e.g. string|ObjectA|ObjectB). For a merged composed property the objects are merged
                // together, so it results in string|MergedObject
                if (!($this instanceof MergedComposedPropertiesInterface && $compositionProperty->getNestedSchema())) {
                    $property->addTypeHintDecorator(new CompositionTypeHintDecorator($compositionProperty));
                }
            });

            if ($compositionProperty->isResolved()
                && $this instanceof OneOfProcessor
                && count($compositionProperty->getJsonSchema()->getJson()) === 1
                && array_key_exists('example', $compositionProperty->getJsonSchema()->getJson())
            ) {
                if ($this->schemaProcessor->getGeneratorConfiguration()->isOutputEnabled()) {
                    // @codeCoverageIgnoreStart
                    echo "Warning: example OneOf branch for {$property->getName()} may lead to unexpected results, skipping branch\n";
                    // @codeCoverageIgnoreEnd
                }

                continue;
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
                    static function (CompositionPropertyDecorator $property): string {
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
     * @param int $composedElements The amount of elements which are composed together
     *
     * @return string
     */
    abstract protected function getComposedValueValidation(int $composedElements): string;
}
