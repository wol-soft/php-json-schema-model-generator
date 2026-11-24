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
use PHPModelGenerator\Model\Validator\ArrayContainsValidator;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

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

        if (is_bool($json[$this->key])) {
            if ($json[$this->key] === false) {
                $schemaProcessor->getGeneratorConfiguration()->getLogger()->warning(
                    "contains: false for property '{property}' can never be satisfied; any array will fail",
                    ['property' => $property->getName()],
                );

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
                $property->getName(),
                $propertySchema->navigate($this->key),
                isArrayItem: true,
            );

        $countMatches = $this->supportMinMaxContains &&
            (array_key_exists('minContains', $json) || array_key_exists('maxContains', $json));

        $property->addValidator(
            new ArrayContainsValidator(
                $property,
                $nestedProperty,
                $schema,
                $schemaProcessor->getGeneratorConfiguration(),
                $countMatches,
                ($json['minContains'] ?? 1) === 0,
            ),
        );

        if (!$countMatches) {
            return;
        }

        if (array_key_exists('minContains', $json)) {
            if (!is_int($json['minContains']) || $json['minContains'] < 0) {
                throw new SchemaException(
                    sprintf(
                        "Invalid minContains %s for property '%s' in file %s",
                        str_replace("\n", '', var_export($json['minContains'], true)),
                        $property->getName(),
                        $propertySchema->getFile(),
                    ),
                );
            }

            $property->addValidator(
                new PropertyValidator(
                    $property,
                    "is_array(\$value) && \$containsMatches < {$json['minContains']}",
                    MinContainsException::class,
                    [$json['minContains'], '&$containsMatches'],
                ),
            );
        }

        if (array_key_exists('maxContains', $json)) {
            if (!is_int($json['maxContains']) || $json['maxContains'] < 0) {
                throw new SchemaException(
                    sprintf(
                        "Invalid maxContains %s for property '%s' in file %s",
                        str_replace("\n", '', var_export($json['maxContains'], true)),
                        $property->getName(),
                        $propertySchema->getFile(),
                    ),
                );
            }

            $property->addValidator(
                new PropertyValidator(
                    $property,
                    "is_array(\$value) && \$containsMatches > {$json['maxContains']}",
                    MaxContainsException::class,
                    [$json['maxContains'], '&$containsMatches'],
                ),
            );
        }

        // An absent minContains defaults to 1, so maxContains: 0 without minContains: 0 contradicts itself:
        // contains requires one match while maxContains allows none. Comparing only explicit values would
        // generate a class which rejects every array.
        $effectiveMinContains = $json['minContains'] ?? 1;

        if (isset($json['maxContains']) && $effectiveMinContains > $json['maxContains']) {
            throw new SchemaException(
                sprintf(
                    "minContains (%s) must not be larger than maxContains (%s) for property '%s' in file %s",
                    $effectiveMinContains,
                    $json['maxContains'],
                    $property->getName(),
                    $propertySchema->getFile(),
                ),
            );
        }
    }
}
