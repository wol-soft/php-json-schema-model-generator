<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Arrays;

use PHPModelGenerator\Exception\Arrays\ContainsException;
use PHPModelGenerator\Exception\Arrays\MaxContainsException;
use PHPModelGenerator\Exception\Arrays\MinContainsException;
use PHPModelGenerator\Exception\SchemaException;
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
    public function __construct(private readonly bool $supportMinMaxContains = true)
    {}

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

        $nestedProperty = (new PropertyFactory())
            ->create(
                $schemaProcessor,
                $schema,
                "item of array {$property->getName()}",
                $propertySchema->withJson($json[$this->key]),
            );

        $countMatches = $this->supportMinMaxContains &&
            (array_key_exists('minContains', $json) || array_key_exists('maxContains', $json));

        $property->addValidator(
            new class(
                $property,
                DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'ArrayContains.phptpl',
                [
                    'property' => $nestedProperty,
                    'schema' => $schema,
                    'countMatches' => $countMatches,
                    'allowNoMatch' => $json['minContains'] ?? 1 === 0,
                    'viewHelper' => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
                    'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                ],
                ContainsException::class,
            ) extends PropertyTemplateValidator {
                public function getValidatorSetUp(): string
                {
                    return $this->templateValues['countMatches'] ? '
                        $containsMatches = 0;
                    ' : '';
                }
            },
        );

        if (!$countMatches) {
            return;
        }

        if (array_key_exists('minContains', $json)) {
            if (!is_int($json['minContains']) || $json['minContains'] < 0) {
                throw new SchemaException(
                    sprintf(
                        "Invalid minContains %s for property '%s' in file %s",
                        str_replace("\n", '', var_export($json[$this->key], true)),
                        $property->getName(),
                        $propertySchema->getFile(),
                    ),
                );
            }

            $property->addValidator(
                new PropertyValidator(
                    $property,
                    "\$containsMatches < {$json['minContains']}",
                    MinContainsException::class,
                    [$json['minContains'], '&$containsMatches'],
                ),
            );
        }

        if (array_key_exists('maxContains', $json)) {
            if (!is_int($json['maxContains']) || $json['maxContains'] < 1) {
                throw new SchemaException(
                    sprintf(
                        "Invalid minContains %s for property '%s' in file %s",
                        str_replace("\n", '', var_export($json[$this->key], true)),
                        $property->getName(),
                        $propertySchema->getFile(),
                    ),
                );
            }

            $property->addValidator(
                new PropertyValidator(
                    $property,
                    "\$containsMatches > {$json['maxContains']}",
                    MaxContainsException::class,
                    [$json['maxContains'], '&$containsMatches'],
                ),
            );
        }

        if (isset($json['minContains'], $json['maxContains']) && $json['minContains'] > $json['maxContains']) {
            throw new SchemaException(
                sprintf(
                    "minContains (%s) must not be larger than maxContains (%s) for property '%s' in file %s",
                    $json['minContains'],
                    $json['maxContains'],
                    $property->getName(),
                    $propertySchema->getFile(),
                ),
            );
        }
    }
}
