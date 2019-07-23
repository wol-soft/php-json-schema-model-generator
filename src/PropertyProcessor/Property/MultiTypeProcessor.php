<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\TypeCheckValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\Property\PropertyTransferDecorator;
use PHPModelGenerator\PropertyProcessor\PropertyCollectionProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorInterface;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

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
     * @param PropertyProcessorFactory    $propertyProcessorFactory
     * @param array                       $types
     * @param PropertyCollectionProcessor $propertyCollectionProcessor
     * @param SchemaProcessor             $schemaProcessor
     * @param Schema                      $schema
     *
     * @throws SchemaException
     */
    public function __construct(
        PropertyProcessorFactory $propertyProcessorFactory,
        array $types,
        PropertyCollectionProcessor $propertyCollectionProcessor,
        SchemaProcessor $schemaProcessor,
        Schema $schema
    ) {
        parent::__construct($propertyCollectionProcessor, $schemaProcessor, $schema);

        foreach ($types as $type) {
            $this->propertyProcessors[] = $propertyProcessorFactory->getProcessor(
                $type,
                $propertyCollectionProcessor,
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
     */
    public function process(string $propertyName, array $propertyData): PropertyInterface
    {
        $property = parent::process($propertyName, $propertyData);

        $this->processSubProperties($propertyName, $propertyData, $property);

        if (empty($this->allowedPropertyTypeChecks)) {
            return $property;
        }

        return $property->addValidator(
            new PropertyValidator(
                '!' . join('($value) && !', array_unique($this->allowedPropertyTypeChecks)) . '($value)' .
                    ($property->isRequired() ? '' : ' && $value !== null'),
                InvalidArgumentException::class,
                "invalid type for {$property->getName()}"
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
        foreach ($source->getValidators() as $validator) {
            // filter out type checks to create a single type check which covers all allowed types
            if ($validator->getValidator() instanceof TypeCheckValidator) {
                preg_match('/(?P<typeCheck>is_[a-z]+)/', $validator->getValidator()->getCheck(), $matches);
                $this->allowedPropertyTypeChecks[] = $matches['typeCheck'];

                continue;
            }

            // remove duplicated checks like an isset check
            if (in_array($validator->getValidator()->getCheck(), $this->checks)) {
                continue;
            }

            $destination->addValidator($validator->getValidator(), $validator->getPriority());
            $this->checks[] = $validator->getValidator()->getCheck();
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
