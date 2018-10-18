<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\InvalidArgumentException;
use PHPModelGenerator\Model\Property;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\TypeCheckValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\PropertyTransferDecorator;
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
     * @param PropertyProcessorFactory $propertyProcessorFactory
     * @param array $types
     * @param PropertyCollectionProcessor $propertyCollectionProcessor
     * @param SchemaProcessor $schemaProcessor
     */
    public function __construct(
        PropertyProcessorFactory $propertyProcessorFactory,
        array $types,
        PropertyCollectionProcessor $propertyCollectionProcessor,
        SchemaProcessor $schemaProcessor
    ) {
        parent::__construct($propertyCollectionProcessor);

        foreach ($types as $type) {
            $this->propertyProcessors[] = $propertyProcessorFactory
                ->getPropertyProcessor($type, $propertyCollectionProcessor, $schemaProcessor);
        }
    }

    /**
     * Process a property
     *
     * @param string $propertyName The name of the property
     * @param array $propertyData An array containing the data of the property
     *
     * @return Property
     */
    public function process(string $propertyName, array $propertyData): Property
    {
        $property = parent::process($propertyName, $propertyData);

        foreach ($this->propertyProcessors as $propertyProcessor) {
            $subProperty = $propertyProcessor->process($propertyName, $propertyData);
            $this->transferValidators($subProperty, $property);

            if ($subProperty->hasDecorators()) {
                $property->addDecorator(new PropertyTransferDecorator($subProperty));
            }
        }

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
     * @param Property $source
     * @param Property $destination
     */
    protected function transferValidators(Property $source, Property $destination)
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
}
