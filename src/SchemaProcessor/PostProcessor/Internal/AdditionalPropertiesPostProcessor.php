<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor\Internal;

use Exception;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\AdditionalPropertiesValidator;
use PHPModelGenerator\Model\Validator\PropertyTemplateValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\ArrayTypeHintDecorator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class AdditionalPropertiesPostProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor\PostProcessor\Internal
 */
class AdditionalPropertiesPostProcessor extends PostProcessor
{
    /**
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
     *
     * @throws SchemaException
     */
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $json = $schema->getJsonSchema()->getJson();

        if (isset($json['additionalProperties']) && $json['additionalProperties'] !== false) {
            $this->addAdditionalPropertiesCollectionProperty($schema);
        }
    }

    /**
     * @param Schema $schema
     *
     * @throws SchemaException
     */
    public function addAdditionalPropertiesCollectionProperty(Schema $schema): void
    {
        $validationProperty = null;
        foreach ($schema->getBaseValidators() as $validator) {
            if (is_a($validator, AdditionalPropertiesValidator::class)) {
                $validator->setCollectAdditionalProperties(true);
                $validationProperty = $validator->getValidationProperty();
            }
        }

        $additionalPropertiesCollectionProperty = (new Property(
            'additionalProperties',
            new PropertyType('array'),
            new JsonSchema(__FILE__, []),
            'Collect all additional properties provided to the schema'
        ))
            ->setDefaultValue([])
            ->setInternal(true);

        if ($validationProperty) {
            $additionalPropertiesCollectionProperty->addTypeHintDecorator(
                new ArrayTypeHintDecorator($validationProperty)
            );
        }

        $schema->addProperty($additionalPropertiesCollectionProperty);

        $json = $schema->getJsonSchema()->getJson();
        if (!isset($json['additionalProperties']) || $json['additionalProperties'] === true) {
            $this->addUpdateAdditionalProperties($schema);
        }
    }

    /**
     * Usually the AdditionalPropertiesValidator validates all additional properties against the constraints and updates
     * the internal storage of the additional properties. If no additional property constraints are defined for the
     * schema the provided additional properties must be updated separately as no AdditionalPropertiesValidator is added
     * to the generated class.
     *
     * @param Schema $schema
     */
    private function addUpdateAdditionalProperties(Schema $schema): void
    {
        $schema->addBaseValidator(
            new class ($schema) extends PropertyTemplateValidator {
                public function __construct(Schema $schema)
                {
                    $patternProperties = array_keys($schema->getJsonSchema()->getJson()['patternProperties'] ?? []);

                    parent::__construct(
                        new Property($schema->getClassName(), null, $schema->getJsonSchema()),
                        join(
                            DIRECTORY_SEPARATOR,
                            [
                                '..',
                                'SchemaProcessor',
                                'PostProcessor',
                                'Templates',
                                'AdditionalProperties',
                                'UpdateAdditionalProperties.phptpl',
                            ]
                        ),
                        [
                            'patternProperties' => $patternProperties
                                ? RenderHelper::varExportArray($patternProperties)
                                : null,
                            'additionalProperties' => RenderHelper::varExportArray(
                                array_keys($schema->getJsonSchema()->getJson()['properties'] ?? [])
                            ),
                        ],
                        Exception::class
                    );
                }
            }
        );
    }
}
