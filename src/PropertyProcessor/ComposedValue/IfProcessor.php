<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\ComposedValue;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\ConditionalPropertyValidator;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\PropertyProcessor\Property\AbstractValueProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyMetaDataCollection;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class IfProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\ComposedValue
 */
class IfProcessor extends AbstractValueProcessor implements ComposedPropertiesInterface
{
    /**
     * @inheritdoc
     */
    protected function generateValidators(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        $json = $propertySchema->getJson()['propertySchema']->getJson();

        if (!isset($json['then']) && !isset($json['else'])) {
            throw new SchemaException(
                sprintf(
                    'Incomplete conditional composition for property %s in file %s',
                    $property->getName(),
                    $property->getJsonSchema()->getFile()
                )
            );
        }

        $propertyFactory = new PropertyFactory(new PropertyProcessorFactory());

        $properties = [];

        foreach (['if', 'then', 'else'] as $compositionElement) {
            if (!isset($json[$compositionElement])) {
                $properties[$compositionElement] = null;
                continue;
            }

            $compositionSchema = $propertySchema->getJson()['propertySchema']->withJson($json[$compositionElement]);

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

            $properties[$compositionElement] = $compositionProperty;
        }

        $property->addValidator(
            new ConditionalPropertyValidator(
                $property,
                $properties,
                [
                    'ifProperty' => $properties['if'],
                    'thenProperty' => $properties['then'],
                    'elseProperty' => $properties['else'],
                    'generatorConfiguration' => $this->schemaProcessor->getGeneratorConfiguration(),
                    'viewHelper' => new RenderHelper($this->schemaProcessor->getGeneratorConfiguration()),
                    'onlyForDefinedValues' => $propertySchema->getJson()['onlyForDefinedValues'],
                ]
            ),
            100
        );

        //parent::generateValidators($property, $propertySchema);
    }
}
