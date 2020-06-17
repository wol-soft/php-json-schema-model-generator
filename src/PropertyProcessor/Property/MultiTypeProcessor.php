<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\MultiTypeCheckValidator;
use PHPModelGenerator\Model\Validator\TypeCheckInterface;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\PropertyTransferDecorator;
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
    protected $allowedPropertyTypeChecks = [];
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
            $this->propertyProcessors[] = $propertyProcessorFactory->getProcessor(
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
     * @param array $propertyData An array containing the data of the property
     *
     * @return PropertyInterface
     *
     * @throws SchemaException
     * @throws ReflectionException
     */
    public function process(string $propertyName, array $propertyData): PropertyInterface
    {
        $property = parent::process($propertyName, $propertyData);

        foreach ($property->getValidators() as $validator) {
            $this->checks[] = $validator->getValidator()->getCheck();
        }

        $this->processSubProperties($propertyName, $propertyData, $property);

        if (empty($this->allowedPropertyTypeChecks)) {
            return $property;
        }

        return $property->addValidator(
            new MultiTypeCheckValidator(
                array_unique($this->allowedPropertyTypeChecks),
                $property,
                $this->isImplicitNullAllowed($this->schemaProcessor->getGeneratorConfiguration(), $property)
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
                array_push($this->allowedPropertyTypeChecks, ...$validator->getTypes());

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
     * @param array             $propertyData
     * @param PropertyInterface $property
     *
     * @throws SchemaException
     */
    protected function processSubProperties(
        string $propertyName,
        array $propertyData,
        PropertyInterface $property
    ): void {
        $defaultValue = null;
        $invalidDefaultValueException = null;
        $invalidDefaultValues = 0;

        if (isset($propertyData['default'])) {
            $defaultValue = $propertyData['default'];
            unset($propertyData['default']);
        }

        foreach ($this->propertyProcessors as $propertyProcessor) {
            $subProperty = $propertyProcessor->process($propertyName, $propertyData);
            $this->transferValidators($subProperty, $property);

            if ($subProperty->hasDecorators()) {
                $property->addDecorator(new PropertyTransferDecorator($subProperty));
            }

            if ($defaultValue !== null && $propertyProcessor instanceof AbstractTypedValueProcessor) {
                try {
                    $propertyProcessor->setDefaultValue($property, $defaultValue);
                } catch (SchemaException $e) {
                    $invalidDefaultValues++;
                    $invalidDefaultValueException = $e;
                }
            }
        }

        if ($invalidDefaultValues === count($this->propertyProcessors)) {
            throw $invalidDefaultValueException;
        }
    }
}
