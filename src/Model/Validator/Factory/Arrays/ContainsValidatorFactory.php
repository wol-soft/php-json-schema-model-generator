<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Arrays;

use PHPModelGenerator\Exception\Arrays\ContainsException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Model\Validator\PropertyTemplateValidator;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

class ContainsValidatorFactory extends AbstractValidatorFactory
{
    /**
     * @throws SchemaException
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

        $nestedProperty = (new PropertyFactory(new PropertyProcessorFactory()))
            ->create(
                $schemaProcessor,
                $schema,
                "item of array {$property->getName()}",
                $propertySchema->withJson($json[$this->key]),
            );

        $property->addValidator(
            new PropertyTemplateValidator(
                $property,
                DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ArrayContains.phptpl',
                [
                    'property' => $nestedProperty,
                    'schema' => $schema,
                    'viewHelper' => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
                    'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                ],
                ContainsException::class,
            ),
        );
    }
}
