<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use JsonSerializable;
use PHPModelGenerator\Interfaces\SerializationInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\FilterValidator;
use PHPModelGenerator\PropertyProcessor\Filter\TransformingFilterInterface;
use PHPModelGenerator\Traits\SerializableTrait;

/**
 * Class SerializationPostProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor\PostProcessor
 */
class SerializationPostProcessor implements PostProcessorInterface
{
    /**
     * Add serialization support to the provided schema
     *
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
     */
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $schema
            ->addTrait(SerializableTrait::class)
            ->addInterface(JsonSerializable::class)
            ->addInterface(SerializationInterface::class);

        $this->addSerializeFunctionsForTransformingFilters($schema, $generatorConfiguration);
    }

    /**
     * Each transforming filter must provide a method to serialize the value. Add a method to the schema to call the
     * serialization for each property with a transforming filter
     *
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
     */
    private function addSerializeFunctionsForTransformingFilters(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration
    ): void {
        foreach ($schema->getProperties() as $property) {
            foreach ($property->getValidators() as $validator) {
                $validator = $validator->getValidator();

                if ($validator instanceof FilterValidator &&
                    $validator->getFilter() instanceof TransformingFilterInterface
                ) {
                    $schema->addMethod(
                        "serialize{$property->getAttribute()}",
                        new RenderedMethod(
                            $schema,
                            $generatorConfiguration,
                            'TransformingFilterSerializer.phptpl',
                            [

                                'property' => $property->getAttribute(),
                                'serializerClass' => $validator->getFilter()->getSerializer()[0],
                                'serializerMethod' => $validator->getFilter()->getSerializer()[1],
                                'serializerOptions' => var_export($validator->getFilterOptions(), true),
                            ]
                        )
                    );
                }
            }
        }
    }
}
