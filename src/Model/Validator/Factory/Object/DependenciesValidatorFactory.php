<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Object;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\Factory\AbstractValidatorFactory;
use PHPModelGenerator\Model\Validator\PropertyDependencyValidator;
use PHPModelGenerator\Model\Validator\SchemaDependencyValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\SchemaNamespaceTransferDecorator;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\JsonSchema as JsonSchemaUtil;
use PHPModelGenerator\Utils\TypeIntersection;

class DependenciesValidatorFactory extends AbstractValidatorFactory
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

        if (empty($json['dependencies'])) {
            return;
        }

        $propertyFactory = new PropertyFactory();

        foreach ($json['dependencies'] as $triggerName => $dependency) {
            $triggerName = (string) $triggerName;

            if (($json['properties'][$triggerName] ?? null) === false) {
                throw new SchemaException(
                    sprintf(
                        "Property '%s' is denied (schema false) but also has dependencies defined in file %s",
                        $triggerName,
                        $propertySchema->getFile(),
                    ),
                );
            }

            $triggerProperty = $schema->getProperty($triggerName);
            if ($triggerProperty === null) {
                $triggerProperty = $propertyFactory->create(
                    $schemaProcessor,
                    $schema,
                    $triggerName,
                    $propertySchema->withJson([]),
                );
                $schema->addProperty($triggerProperty);
            }

            if ($this->isArrayFormDependency($dependency)) {
                $this->synthesiseMissingTargets(
                    $dependency,
                    $propertySchema,
                    $schemaProcessor,
                    $schema,
                    $propertyFactory,
                );
                $dependencyPointer = $propertySchema->getPointer()
                    . '/dependencies/'
                    . JsonSchemaUtil::encodePointer($triggerName);
                $triggerProperty->addValidator(
                    (new PropertyDependencyValidator($triggerProperty, $dependency))
                        ->withJsonPointer($dependencyPointer),
                );
                continue;
            }

            $dependencyJsonSchema = $propertySchema->navigate(
                'dependencies/' . JsonSchemaUtil::encodePointer($triggerName),
            );

            $dependencyJson = $dependencyJsonSchema->getJson();
            if (!isset($dependencyJson['type'])) {
                $dependencyJsonSchema = $dependencyJsonSchema->withJson($dependencyJson + ['type' => 'object']);
            }

            $dependencySchema = $schemaProcessor->processSchema(
                $dependencyJsonSchema,
                $schema->getClassPath(),
                "{$schema->getClassName()}_{$triggerName}_Dependency",
                $schema->getSchemaDictionary(),
            );

            // Add as a schema-level base validator, not a property validator on the trigger.
            // Trigger properties from $ref definitions share a single underlying Property object
            // via PropertyProxy; addValidator() on a proxy delegates to that shared object, so
            // all dependency validators for different triggers would land on the same property and
            // be rendered under the wrong trigger condition. Base validators avoid this entirely.
            $schema->addBaseValidator(
                (new SchemaDependencyValidator($schemaProcessor, $triggerProperty, $dependencySchema))
                    ->withJsonPointer($dependencyJsonSchema->getPointer()),
            );
            $schema->addNamespaceTransferDecorator(new SchemaNamespaceTransferDecorator($dependencySchema));

            $this->transferDependencySchemaPropertiesToBaseSchema(
                $dependencySchema,
                $schema,
                $triggerName,
                $propertySchema->getFile(),
            );
        }
    }

    private function isArrayFormDependency(mixed $dependency): bool
    {
        if (!is_array($dependency)) {
            return false;
        }

        foreach ($dependency as $index => $value) {
            if (!is_int($index) || !is_string($value)) {
                return false;
            }
        }

        return true;
    }

    private function synthesiseMissingTargets(
        array $targetNames,
        JsonSchema $propertySchema,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyFactory $propertyFactory,
    ): void {
        foreach ($targetNames as $targetName) {
            if ($schema->getProperty($targetName) !== null) {
                continue;
            }

            $schema->addProperty(
                $propertyFactory->create(
                    $schemaProcessor,
                    $schema,
                    $targetName,
                    $propertySchema->withJson([]),
                ),
            );
        }
    }

    private function transferDependencySchemaPropertiesToBaseSchema(
        Schema $dependencySchema,
        Schema $schema,
        string $triggerName,
        string $schemaFile,
    ): void {
        foreach ($dependencySchema->getProperties() as $dependencyProperty) {
            $existingProperty = $schema->getProperty($dependencyProperty->getName());

            if ($existingProperty !== null) {
                $depType = $dependencyProperty->getType(true);
                $existingType = $existingProperty->getType(true);

                if (
                    $depType !== null
                    && $existingType !== null
                    && TypeIntersection::compute($depType->getNames(), $existingType->getNames()) === []
                ) {
                    throw new SchemaException(
                        sprintf(
                            "Property '%s' is defined with conflicting types between"
                                . " the dependency schema for '%s' and the base schema in file %s",
                            $dependencyProperty->getName(),
                            $triggerName,
                            $schemaFile,
                        ),
                    );
                }

                continue;
            }

            $schema->addProperty(
                (clone $dependencyProperty)
                    ->setRequired(false)
                    ->setType(null)
                    ->filterValidators(static fn(): bool => false),
            );
        }
    }
}
