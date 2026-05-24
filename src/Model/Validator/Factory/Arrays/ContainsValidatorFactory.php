<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Arrays;

use PHPModelGenerator\Exception\Arrays\ContainsException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Model\Validator\PropertyTemplateValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

class ContainsValidatorFactory extends AbstractValidatorFactory
{
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

        if (is_bool($json[$this->key])) {
            if ($json[$this->key] === false) {
                if ($schemaProcessor->getGeneratorConfiguration()->isOutputEnabled()) {
                    // @codeCoverageIgnoreStart
                    echo "Warning: contains: false for property '{$property->getName()}'"
                        . " can never be satisfied; any array will fail\n";
                    // @codeCoverageIgnoreEnd
                }

                $property->addValidator(
                    new PropertyValidator(
                        $property,
                        'is_array($value)',
                        ContainsException::class,
                    )
                );
                return;
            }

            $propertySchema = $propertySchema->withJson(
                array_merge($json, [$this->key => []]),
            );
        }

        $nestedProperty = (new PropertyFactory())
            ->create(
                $schemaProcessor,
                $schema,
                "item of array {$property->getName()}",
                $propertySchema->navigate($this->key),
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
