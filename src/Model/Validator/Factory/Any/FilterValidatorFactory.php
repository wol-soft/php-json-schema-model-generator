<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Any;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\PropertyProcessor\Filter\FilterProcessor;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use ReflectionException;

class FilterValidatorFactory extends AbstractValidatorFactory
{
    /**
     * @throws SchemaException
     * @throws ReflectionException
     */
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        $json = $propertySchema->getJson();

        if (!isset($json[$this->key])) {
            return;
        }

        (new FilterProcessor())->process(
            $property,
            $json[$this->key],
            $schemaProcessor->getGeneratorConfiguration(),
            $schema,
        );
    }
}
