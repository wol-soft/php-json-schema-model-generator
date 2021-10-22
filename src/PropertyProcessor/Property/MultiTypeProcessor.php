<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\MultiTypeCheckValidator;
use PHPModelGenerator\Model\Validator\TypeCheckInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\PropertyTransferDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\PropertyMetaDataCollection;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorInterface;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use ReflectionException;

/**
 * Class MultiTypePropertyProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class MultiTypeProcessor extends AbstractValueProcessor
{
    /** @var PropertyProcessorInterface[] */
    protected $propertyProcessors = [];
    /** @var string[] */
    protected $allowedPropertyTypes = [];
    /** @var string[] */
    protected $checks = [];

    /**
     * MultiTypePropertyProcessor constructor.
     *
     * @param PropertyProcessorFactory   $propertyProcessorFactory
     * @param array                      $types
     * @param PropertyMetaDataCollection $propertyMetaDataCollection
     * @param SchemaProcessor            $schemaProcessor
     * @param Schema                     $schema
     *
     * @throws SchemaException
     */
    public function __construct(
        PropertyProcessorFactory $propertyProcessorFactory,
        array $types,
        PropertyMetaDataCollection $propertyMetaDataCollection,
        SchemaProcessor $schemaProcessor,
        Schema $schema
    ) {
        parent::__construct($propertyMetaDataCollection, $schemaProcessor, $schema);

        foreach ($types as $type) {
            $this->propertyProcessors[$type] = $propertyProcessorFactory->getProcessor(
                $type,
                $propertyMetaDataCollection,
                $schemaProcessor,
                $schema
            );
        }
    }

    /**
     * Process a property
     *
     * @param string $propertyName The name of the property
     * @param JsonSchema $propertySchema The schema of the property
     *
     * @return PropertyInterface
     *
     * @throws SchemaException
     * @throws ReflectionException
     */
    public function process(string $propertyName, JsonSchema $propertySchema): PropertyInterface
    {
        $property = parent::process($propertyName, $propertySchema);

        foreach ($property->getValidators() as $validator) {
            $this->checks[] = $validator->getValidator()->getCheck();
        }

        $subProperties = $this->processSubProperties($propertyName, $propertySchema, $property);

        if (empty($this->allowedPropertyTypes)) {
            return $property;
        }

        $property->addTypeHintDecorator(
            new TypeHintDecorator(
                array_map(
                    function (PropertyInterface $subProperty): string {
                        return $subProperty->getTypeHint();
                    },
                    $subProperties
                )
            )
        );

        return $property->addValidator(
            new MultiTypeCheckValidator(
                array_unique($this->allowedPropertyTypes),
                $property,
                $this->isImplicitNullAllowed($property)
            ),
            2
        );
    }

    /**
     * Move validators from the $source property to the $destination property
     *
     * @param PropertyInterface $source
     * @param PropertyInterface $destination
     */
    protected function transferValidators(PropertyInterface $source, PropertyInterface $destination)
    {
        foreach ($source->getValidators() as $validatorContainer) {
            $validator = $validatorContainer->getValidator();

            // filter out type checks to create a single type check which covers all allowed types
            if ($validator instanceof TypeCheckInterface) {
                array_push($this->allowedPropertyTypes, ...$validator->getTypes());

                continue;
            }

            // remove duplicated checks like an isset check
            if (in_array($validator->getCheck(), $this->checks)) {
                continue;
            }

            $destination->addValidator($validator, $validatorContainer->getPriority());
            $this->checks[] = $validator->getCheck();
        }
    }

    /**
     * @param string            $propertyName
     * @param JsonSchema        $propertySchema
     * @param PropertyInterface $property
     *
     * @return PropertyInterface[]
     *
     * @throws SchemaException
     */
    protected function processSubProperties(
        string $propertyName,
        JsonSchema $propertySchema,
        PropertyInterface $property
    ): array {
        $defaultValue = null;
        $invalidDefaultValueException = null;
        $invalidDefaultValues = 0;
        $subProperties = [];
        $json = $propertySchema->getJson();

        if (isset($json['default'])) {
            $defaultValue = $json['default'];
            unset($json['default']);
        }

        foreach ($this->propertyProcessors as $type => $propertyProcessor) {
            $json['type'] = $type;

            $subProperty = $propertyProcessor->process($propertyName, $propertySchema->withJson($json));
            $this->transferValidators($subProperty, $property);

            if ($subProperty->getDecorators()) {
                $property->addDecorator(new PropertyTransferDecorator($subProperty));
            }

            if ($defaultValue !== null && $propertyProcessor instanceof AbstractTypedValueProcessor) {
                try {
                    $propertyProcessor->setDefaultValue($property, $defaultValue, $propertySchema);
                } catch (SchemaException $e) {
                    $invalidDefaultValues++;
                    $invalidDefaultValueException = $e;
                }
            }

            $subProperties[] = $subProperty;
        }

        if ($invalidDefaultValues === count($this->propertyProcessors)) {
            throw $invalidDefaultValueException;
        }

        return $subProperties;
    }
}
