<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\BaseProperty;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\PropertyProcessor\ComposedValueProcessorFactory;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\TypeHintTransferDecorator;
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
    public function __construct(
        protected SchemaProcessor $schemaProcessor,
        protected Schema $schema,
        protected bool $required = false,
    ) {}

    /**
     * Generates the validators for the property
     *
     * @throws SchemaException
     */
    protected function generateValidators(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        if ($property->isRequired() && !str_starts_with($property->getName(), 'item of array ')) {
            $property->addValidator(new RequiredPropertyValidator($property), 1);
        }

        $this->addComposedValueValidator($property, $propertySchema);
    }

    /**
     * @throws SchemaException
     */
    protected function addComposedValueValidator(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        // For non-root object-type properties, composition keywords are processed in full
        // inside the nested schema by ObjectProcessor (via processSchema with rootLevelComposition=true).
        // Adding a composition validator here would duplicate the validation at the parent level:
        // by the time this validator runs, $value is already an instantiated object, so branch
        // instanceof checks against branch-specific classes fail, rejecting valid input.
        // It would also inject a _Merged_ class name into the type hint, overriding the correct type.
        if (!($property instanceof BaseProperty) && ($propertySchema->getJson()['type'] ?? '') === 'object') {
            return;
        }

        $composedValueKeywords = ['allOf', 'anyOf', 'oneOf', 'not', 'if'];
        $propertyFactory = new PropertyFactory(new ComposedValueProcessorFactory($property instanceof BaseProperty));

        foreach ($composedValueKeywords as $composedValueKeyword) {
            if (!isset($propertySchema->getJson()[$composedValueKeyword])) {
                continue;
            }

            $propertySchema = $this->inheritPropertyType($propertySchema, $composedValueKeyword);

            $composedProperty = $propertyFactory
                ->create(
                    $this->schemaProcessor,
                    $this->schema,
                    $property->getName(),
                    $propertySchema->withJson([
                        'type' => $composedValueKeyword,
                        'propertySchema' => $propertySchema,
                        'onlyForDefinedValues' => !($this instanceof BaseProcessor) &&
                            (!$property->isRequired()
                                && $this->schemaProcessor->getGeneratorConfiguration()->isImplicitNullAllowed()),
                    ]),
                    $property->isRequired(),
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
     */
    protected function isImplicitNullAllowed(PropertyInterface $property): bool
    {
        return $this->schemaProcessor->getGeneratorConfiguration()->isImplicitNullAllowed() && !$property->isRequired();
    }
}
