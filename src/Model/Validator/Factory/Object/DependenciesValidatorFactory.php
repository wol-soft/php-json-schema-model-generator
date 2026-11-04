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

            $unreachableReason = $this->getUnreachableTriggerReason($schemaProcessor, $json, $triggerName);
            if ($unreachableReason !== null) {
                $schemaProcessor->getGeneratorConfiguration()->getLogger()->warning(
                    "Dependencies on '{trigger}' in class '{class}' can never apply: {reason}",
                    [
                        'trigger' => $triggerName,
                        'class' => $schema->getClassName(),
                        'reason' => $unreachableReason,
                    ],
                );

                continue;
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
                // A trigger declared via $ref is recycled for every reference to the definition; the dependency
                // belongs to this schema only.
                $schema->withOwnedProperty(
                    $triggerName,
                    static fn(PropertyInterface $ownedTrigger): PropertyInterface => $ownedTrigger->addValidator(
                        (new PropertyDependencyValidator($ownedTrigger, $dependency))
                            ->withJsonPointer($dependencyPointer),
                    ),
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

            // Attached to the trigger property, not as a schema-level base validator: base validators only run
            // for the constructor, so a setter or a removal of an additional property would skip the dependency.
            // A trigger declared via $ref is recycled for every reference to the definition (a PropertyProxy
            // delegates addValidator() to that shared property), so the validator goes through
            // withOwnedProperty() to keep it from landing on the other references.
            $schema->withOwnedProperty(
                $triggerName,
                static fn(PropertyInterface $ownedTrigger): PropertyInterface => $ownedTrigger->addValidator(
                    (new SchemaDependencyValidator($schemaProcessor, $ownedTrigger, $dependencySchema))
                        ->withJsonPointer($dependencyJsonSchema->getPointer()),
                ),
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

    /**
     * Returns why the trigger key can never be part of an input of this schema, or null when it can.
     *
     * Decided on the raw schema JSON, not on $schema->getProperty(): a property registered for the key by
     * the required-stub mechanism or a composition transfer does not make the key acceptable, the schema's
     * own additionalProperties still rejects it.
     */
    private function getUnreachableTriggerReason(
        SchemaProcessor $schemaProcessor,
        array $json,
        string $triggerName,
    ): ?string {
        if (array_key_exists($triggerName, $json['properties'] ?? [])) {
            return null;
        }

        // Mirrors AdditionalPropertiesValidatorFactory: an absent keyword is flipped to false by the
        // denyAdditionalProperties() configuration, an explicit value is never overridden.
        if (isset($json['additionalProperties'])) {
            $reason = 'additionalProperties: false rejects the undeclared key';
            $rejectsAdditionalProperties = $json['additionalProperties'] === false;
        } else {
            $reason = 'denyAdditionalProperties() rejects the undeclared key';
            $rejectsAdditionalProperties = $schemaProcessor->getGeneratorConfiguration()->denyAdditionalProperties();
        }

        if (!$rejectsAdditionalProperties) {
            return null;
        }

        // A key matching a patternProperties regex is not an additional property.
        foreach (array_keys($json['patternProperties'] ?? []) as $pattern) {
            if (preg_match('/' . addcslashes((string) $pattern, '/') . '/', $triggerName) === 1) {
                return null;
            }
        }

        return $reason;
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
